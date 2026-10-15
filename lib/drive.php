<?php
/**
 * Automatic nightly backup: the whole database is written to one compressed file (storage/backups) and uploaded
 * to the owner's Google Drive (folder "Invoicyfy Backups"). The Backup screen can restore from that file.
 *
 * Google Drive needs a one-time "Connect Google Drive" by the Admin (OAuth, scope drive.file: the app can only see
 * the files it created). The client secret and refresh token are stored encrypted.
 */
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/automation.php';

const DRIVE_DEFAULTS = [
    'enabled' => false,          // nightly backup on/off (local copy is always made when on)
    'time' => '02:00',
    'client_id' => '',
    'client_secret' => '',       // encrypted
    'refresh_token' => '',       // encrypted
    'email' => '',
    'folder_id' => '',
    'keep' => 30,                // nightly backups kept on Drive (folder FY xxxx-xx / daily)
    'keep_monthly' => 12,        // month-start copies kept (folder FY xxxx-xx / monthly)
    'folders' => [],             // Drive folder ids found / created (cache)
    'local_keep' => 5,           // backups kept on the server
    'last_run_at' => null,
    'last_result' => null,
    'last_ok' => null,
];

function drive_settings(): array
{
    return array_merge(DRIVE_DEFAULTS, array_intersect_key(app_setting('drive'), DRIVE_DEFAULTS));
}

function drive_save(array $s): void
{
    save_app_setting('drive', array_intersect_key($s, DRIVE_DEFAULTS));
}

function drive_public(array $s): array
{
    $out = $s;
    $out['client_secret_set'] = $s['client_secret'] !== '';
    $out['connected'] = $s['refresh_token'] !== '';
    unset($out['client_secret'], $out['refresh_token'], $out['folders']);
    $fy = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
    $out['current_fy'] = sprintf('FY %d-%02d', $fy, ($fy + 1) % 100);
    $out['redirect_uri'] = drive_redirect_uri();
    $out['local_files'] = backup_local_files();
    return $out;
}

function drive_redirect_uri(): string
{
    return api_public_base() . '/master/backup/drive-callback.php';
}

function backup_dir(): string
{
    $dir = __DIR__ . '/../storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function backup_local_files(): array
{
    $files = glob(backup_dir() . '/invoicyfy-backup-*.json.gz') ?: [];
    rsort($files);
    return array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'created_at' => date('Y-m-d H:i:s', filemtime($f))], $files);
}

/**
 * Write every table to storage/backups/invoicyfy-backup-<date>.json.gz, a page at a time (low memory).
 * Format: {"format":"invoicyfy-backup","version":1,"created_at":..,"tables":{"customers":{"columns":[..],"rows":[[..],..]},..}}
 */
function backup_write_file(): array
{
    @set_time_limit(900);
    $name = 'invoicyfy-backup-' . date('Ymd-His') . '.json.gz';
    $path = backup_dir() . '/' . $name;
    $gz = gzopen($path, 'wb6');
    if (!$gz) {
        throw new RuntimeException('Cannot write the backup file in storage/backups');
    }
    $counts = [];
    gzwrite($gz, '{"format":"invoicyfy-backup","version":1,"created_at":' . json_encode(date('Y-m-d H:i:s')) . ',"tables":{');
    $first = true;
    foreach (backup_existing_tables() as $table) {
        $cfg = BACKUP_TABLES[$table];
        $cols = array_keys(backup_columns($table));
        $sel = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $order = $cfg['pk'] ? "`{$cfg['pk']}`" : implode(', ', array_map(fn($c) => "`$c`", $cfg['unique'][0]));
        gzwrite($gz, ($first ? '' : ',') . json_encode($table) . ':{"columns":' . json_encode($cols) . ',"rows":[');
        $first = false;
        $n = 0;
        $last = null;
        while (true) {
            // Keyset paging on the primary key keeps each page fast on big tables.
            if ($cfg['pk']) {
                $rows = db_all("SELECT $sel FROM `$table`" . ($last !== null ? " WHERE `{$cfg['pk']}` > ?" : '') . " ORDER BY $order LIMIT 2000", $last !== null ? [$last] : []);
            } else {
                $rows = db_all("SELECT $sel FROM `$table` ORDER BY $order LIMIT 2000 OFFSET ?", [$n]);
            }
            if (!$rows) {
                break;
            }
            $buf = '';
            foreach ($rows as $r) {
                $buf .= ($n++ ? ',' : '') . json_encode(array_values($r), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            gzwrite($gz, $buf);
            if ($cfg['pk']) {
                $last = end($rows)[$cfg['pk']];
            }
            if (count($rows) < 2000) {
                break;
            }
        }
        gzwrite($gz, ']}');
        $counts[$table] = $n;
    }
    gzwrite($gz, '}}');
    gzclose($gz);
    return [$path, $name, $counts];
}

/* ---------------------------------------------------------------- Google Drive API */

function google_url(string $kind): string
{
    $map = [
        'auth' => env('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token' => env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'api' => env('GOOGLE_DRIVE_API', 'https://www.googleapis.com/drive/v3'),
        'upload' => env('GOOGLE_DRIVE_UPLOAD', 'https://www.googleapis.com/upload/drive/v3'),
    ];
    return rtrim((string) $map[$kind], '/');
}

/** [httpCode, decodedJson|null, rawBody, responseHeaders] */
function drive_http(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Could not reach Google: ' . $err);
    }
    $json = json_decode((string) $raw, true);
    return [$code, is_array($json) ? $json : null, (string) $raw, $respHeaders];
}

function google_error(?array $json, int $code): string
{
    $m = $json['error_description'] ?? $json['error']['message'] ?? (is_string($json['error'] ?? null) ? $json['error'] : null);
    return mb_substr((string) ($m ?: "Google returned HTTP $code"), 0, 250);
}

/** Signed "state" for the connect link, so the callback knows the request came from this app's Admin. */
function drive_state(int $userId, string $return): string
{
    $payload = rtrim(strtr(base64_encode(json_encode(['u' => $userId, 'r' => $return, 'e' => time() + 900, 'n' => bin2hex(random_bytes(6))])), '+/', '-_'), '=');
    return $payload . '.' . substr(hash_hmac('sha256', "drive:$payload", wa_key()), 0, 32);
}

function drive_check_state(string $state): ?array
{
    [$payload, $sig] = array_pad(explode('.', $state, 2), 2, '');
    if (!hash_equals(substr(hash_hmac('sha256', "drive:$payload", wa_key()), 0, 32), $sig)) {
        return null;
    }
    $d = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
    return is_array($d) && ($d['e'] ?? 0) >= time() ? $d : null;
}

function drive_auth_url(array $s, string $state): string
{
    return google_url('auth') . '?' . http_build_query([
        'client_id' => $s['client_id'], 'redirect_uri' => drive_redirect_uri(), 'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive.file', 'access_type' => 'offline', 'prompt' => 'consent',
        'include_granted_scopes' => 'true', 'state' => $state,
    ]);
}

/** Exchange the code from Google for tokens; saves the refresh token. */
function drive_finish_connect(string $code): array
{
    $s = drive_settings();
    [$c, $j] = drive_http('POST', google_url('token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $code, 'client_id' => $s['client_id'], 'client_secret' => (string) wa_decrypt($s['client_secret']),
        'redirect_uri' => drive_redirect_uri(), 'grant_type' => 'authorization_code',
    ]));
    if ($c !== 200 || empty($j['access_token'])) {
        throw new RuntimeException(google_error($j, $c));
    }
    if (empty($j['refresh_token'])) {
        throw new RuntimeException('Google did not give offline access. Remove the app from your Google account permissions and connect again.');
    }
    $s['refresh_token'] = wa_encrypt($j['refresh_token']);
    [$c2, $about] = drive_http('GET', google_url('api') . '/about?fields=user(emailAddress)', ['Authorization: Bearer ' . $j['access_token']]);
    $s['email'] = $c2 === 200 ? (string) ($about['user']['emailAddress'] ?? '') : '';
    $s['folder_id'] = '';
    $s['folders'] = [];
    drive_save($s);
    return $s;
}

function drive_access_token(array $s): string
{
    $refresh = wa_decrypt($s['refresh_token'] ?: null);
    if (!$refresh) {
        throw new RuntimeException('Google Drive is not connected');
    }
    [$c, $j] = drive_http('POST', google_url('token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => $s['client_id'], 'client_secret' => (string) wa_decrypt($s['client_secret']), 'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
    ]));
    if ($c !== 200 || empty($j['access_token'])) {
        throw new RuntimeException('Google Drive login expired or was removed: ' . google_error($j, $c) . '. Connect Google Drive again.');
    }
    return $j['access_token'];
}

/** The "Invoicyfy Backups" folder (created once; found again by id). */
function drive_folder(string $token, array &$s): string
{
    $auth = ['Authorization: Bearer ' . $token];
    if ($s['folder_id']) {
        [$c, $j] = drive_http('GET', google_url('api') . '/files/' . rawurlencode($s['folder_id']) . '?fields=id,trashed', $auth);
        if ($c === 200 && empty($j['trashed'])) {
            return $s['folder_id'];
        }
    }
    [$c, $j] = drive_http('POST', google_url('api') . '/files?fields=id', array_merge($auth, ['Content-Type: application/json']),
        json_encode(['name' => 'Invoicyfy Backups', 'mimeType' => 'application/vnd.google-apps.folder']));
    if ($c !== 200 || empty($j['id'])) {
        throw new RuntimeException('Could not create the backup folder on Drive: ' . google_error($j, $c));
    }
    $s['folder_id'] = $j['id'];
    return $j['id'];
}

/** Resumable upload in 8 MB pieces (works for big backups and slow hosting). Returns the Drive file id. */
function drive_upload(string $token, string $folderId, string $path, string $name): string
{
    $size = filesize($path);
    [$c, $j, , $h] = drive_http('POST', google_url('upload') . '/files?uploadType=resumable&fields=id', [
        'Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: application/gzip', "X-Upload-Content-Length: $size",
    ], json_encode(['name' => $name, 'parents' => [$folderId], 'mimeType' => 'application/gzip']));
    $session = $h['location'] ?? null;
    if ($c !== 200 || !$session) {
        throw new RuntimeException('Drive upload could not start: ' . google_error($j, $c));
    }
    $fh = fopen($path, 'rb');
    $chunk = 8 * 1024 * 1024;
    $offset = 0;
    try {
        while (true) {
            $data = $size > 0 ? fread($fh, $chunk) : '';
            $len = strlen((string) $data);
            $range = $size > 0 ? 'bytes ' . $offset . '-' . ($offset + $len - 1) . "/$size" : 'bytes */0';
            [$c, $j] = drive_http('PUT', $session, ["Content-Length: $len", "Content-Range: $range"], $data);
            if ($c === 200 || $c === 201) {
                return (string) ($j['id'] ?? '');
            }
            if ($c !== 308) {
                throw new RuntimeException('Drive upload failed: ' . google_error($j, $c));
            }
            $offset += $len;
            if ($offset >= $size) {
                throw new RuntimeException('Drive upload did not finish');
            }
        }
    } finally {
        fclose($fh);
    }
}

/** Keep only the newest $keep backups in the folder. */
function drive_cleanup(string $token, string $folderId, int $keep): int
{
    $q = rawurlencode("'$folderId' in parents and trashed = false and name contains 'invoicyfy-backup-'");
    [$c, $j] = drive_http('GET', google_url('api') . "/files?q=$q&orderBy=createdTime%20desc&pageSize=200&fields=files(id,name,createdTime)", ['Authorization: Bearer ' . $token]);
    if ($c !== 200) {
        return 0;
    }
    $removed = 0;
    foreach (array_slice($j['files'] ?? [], max(1, $keep)) as $f) {
        [$dc] = drive_http('DELETE', google_url('api') . '/files/' . rawurlencode($f['id']), ['Authorization: Bearer ' . $token]);
        $removed += $dc === 204 || $dc === 200 ? 1 : 0;
    }
    return $removed;
}

/**
 * Make a backup now: written to the server, then uploaded to Google Drive (FY xxxx-xx / daily) when connected.
 * Runs as a data job (lib/datajobs.php); small databases finish within this call, big ones continue in the
 * background (cron / tick / Data & Archive screen). Returns ['ok', 'message', 'file', 'drive', 'job_id', 'running'].
 */
function run_backup(?int $userId = null, float $budget = 240.0): array
{
    require_once __DIR__ . '/datajobs.php';
    $id = data_job_queue('daily', null, $userId);
    $deadline = microtime(true) + $budget;
    do {
        data_jobs_run(min(30.0, max(1.0, $deadline - microtime(true))));
        $j = data_job($id);
    } while ($j && in_array($j['status'], ['queued', 'running'], true) && microtime(true) < $deadline);
    if (!$j) {
        return ['ok' => false, 'message' => 'Backup could not start', 'file' => null, 'drive' => false, 'job_id' => $id, 'running' => false];
    }
    if (in_array($j['status'], ['queued', 'running'], true)) {
        return ['ok' => true, 'message' => 'Backup is running in the background (' . number_format($j['rows_done']) . ' records so far). It continues automatically.',
            'file' => $j['file_name'], 'drive' => false, 'job_id' => $id, 'running' => true];
    }
    return ['ok' => $j['status'] === 'done', 'message' => $j['status'] === 'done' ? (string) $j['message'] : 'Backup failed: ' . preg_replace('/^Stopped: /', '', (string) $j['message']),
        'file' => $j['file_name'], 'drive' => (bool) $j['drive_file_id'], 'job_id' => $id, 'running' => false];
}

/** Called by cron / the app's tick: once a day after the chosen time. */
function backup_due(): bool
{
    $s = drive_settings();
    return $s['enabled'] && date('H:i') >= $s['time'] && claim_job('backup', 0, date('Y-m-d'));
}
