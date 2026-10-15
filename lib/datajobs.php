<?php
/**
 * Long-term data care: nightly backup, year archives (one FINAL file per financial year on Google Drive) and
 * purging old years from the live database. Built for crores of rows on shared hosting:
 *
 *  - Everything runs as a job in small steps (a few seconds each), from the cron job, the app's tick, or the
 *    Data & Archive screen while it is open. A step never holds a request long, and a job continues where it
 *    stopped (after a timeout, a restart, the next minute...).
 *  - Rows are read in (date, id) index order, a page at a time, so each step costs the same on 1 lakh or 1 crore bills.
 *  - Google Drive layout:  Invoicyfy Backups / FY 2025-26 / daily | monthly / "FY 2025-26 FINAL.json.gz"
 *  - A year can be purged only after its FINAL archive (and the next year's) is safely stored. Unpaid / partly paid
 *    bills, pending orders, open cash shifts and unpaid supplier bills are never purged. Report summaries of purged
 *    days are kept (frozen), so old years still show their totals.
 *
 * Job kinds: daily (nightly backup), archive (FINAL file of one FY), purge (one FY), logs (activity / message logs).
 */
require_once __DIR__ . '/drive.php';

const RETENTION_DEFAULTS = [
    'keep_years' => 0,            // 0 = never purge; else keep this many full financial years before the running one
    'logs_keep_months' => 0,      // activity log / message logs: 0 = keep forever
    'auto_archive' => true,       // make the FINAL file of a finished year automatically (night)
    'auto_purge' => false,        // purge eligible years automatically after their archive is safe
    'allow_local_archive' => false, // allow purging with an archive kept on the server only (downloaded by the Admin)
    'purged_before' => null,      // days before this date are purged: their report summaries are frozen
];

/** Tables whose rows belong to a date (index on (date column, primary key) used for paging). */
const DATA_DATED = [
    'orders' => ['order_date', 'order_id'],
    'invoices' => ['invoice_date', 'invoice_id'],
    'payments' => ['payment_date', 'payment_id'],
    'expenses' => ['expense_date', 'expense_id'],
    'stock_movements' => ['created_at', 'movement_id'],
    'campaigns' => ['created_at', 'campaign_id'],
    'coupon_redemptions' => ['created_at', 'redemption_id'],
    'loyalty_ledger' => ['created_at', 'entry_id'],
    'cash_sessions' => ['opened_at', 'session_id'],
    'purchases' => ['purchase_date', 'purchase_id'],
    'supplier_payments' => ['payment_date', 'payment_id'],
];
/** Rows that belong to a dated parent: [parent table, foreign key]. */
const DATA_CHILDREN = [
    'order_items' => ['orders', 'order_id'],
    'invoice_items' => ['invoices', 'invoice_id'],
    'campaign_recipients' => ['campaigns', 'campaign_id'],
    'cash_movements' => ['cash_sessions', 'session_id'],
    'purchase_items' => ['purchases', 'purchase_id'],
];

/* ------------------------------------------------------------------ settings & financial years */

function retention_settings(): array
{
    return array_merge(RETENTION_DEFAULTS, array_intersect_key(app_setting('data_retention'), RETENTION_DEFAULTS));
}

function retention_save(array $s): void
{
    save_app_setting('data_retention', array_intersect_key($s, RETENTION_DEFAULTS));
}

/** Days before this date were purged; their report summaries stay as they were (null = nothing purged). */
function data_purged_before(): ?string
{
    return stats_frozen_before();
}

/** Financial year (April–March) of a date, as its starting year: 2025 = FY 2025-26. */
function fy_of(string $date): int
{
    $y = (int) substr($date, 0, 4);
    return (int) substr($date, 5, 2) >= 4 ? $y : $y - 1;
}

function fy_label(int $fy): string
{
    return sprintf('FY %d-%02d', $fy, ($fy + 1) % 100);
}

/** [first day, first day of the next year] */
function fy_range(int $fy): array
{
    return ["$fy-04-01", ($fy + 1) . '-04-01'];
}

function fy_current(): int
{
    return fy_of(date('Y-m-d'));
}

/** The oldest financial year that has any bill, order, purchase, expense or stock entry. */
function data_first_fy(): ?int
{
    $min = null;
    foreach (['invoices' => 'invoice_date', 'orders' => 'order_date', 'purchases' => 'purchase_date', 'expenses' => 'expense_date', 'stock_movements' => 'created_at', 'payments' => 'payment_date'] as $t => $c) {
        try {
            $d = db_value("SELECT MIN($c) FROM $t");
        } catch (Throwable $e) {
            $d = null;
        }
        if ($d && ($min === null || $d < $min)) {
            $min = substr((string) $d, 0, 10);
        }
    }
    return $min ? fy_of($min) : null;
}

/** First financial year that may be purged with the current setting (years before this one), or null. */
function purge_cutoff_fy(?array $s = null): ?int
{
    $s ??= retention_settings();
    $keep = (int) $s['keep_years'];
    return $keep > 0 ? fy_current() - $keep : null;
}

/* ------------------------------------------------------------------ year archives */

function fy_archive(int $fy): ?array
{
    try {
        return db_one('SELECT * FROM fy_archives WHERE fy = ?', [$fy]);
    } catch (Throwable $e) {
        return null;
    }
}

/** An archive counts as safe when Google Drive confirmed the full file, or (if allowed) a downloaded server copy. */
function fy_archive_safe(?array $a, ?array $s = null): bool
{
    if (!$a) {
        return false;
    }
    if ($a['drive_verified_at']) {
        return true;
    }
    $s ??= retention_settings();
    return $s['allow_local_archive'] && $a['downloaded_at'] && is_file(archive_dir() . '/' . $a['file_name']);
}

function archive_dir(): string
{
    $dir = backup_dir() . '/archive';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

/** Start of the oldest year that is not safely archived yet: the nightly backup holds everything from here on. */
function daily_backup_from(): string
{
    $first = data_first_fy() ?? fy_current();
    $s = retention_settings();
    for ($fy = $first; $fy < fy_current(); $fy++) {
        $a = fy_archive($fy);
        if (!$a || (!fy_archive_safe($a, $s) && !$a['purged_at'])) {
            return fy_range($fy)[0];
        }
    }
    return fy_range(fy_current())[0];
}

/* ------------------------------------------------------------------ job queue */

function data_job(int $id): ?array
{
    $j = db_one('SELECT * FROM data_jobs WHERE job_id = ?', [$id]);
    return $j ? data_job_row($j) : null;
}

function data_job_row(array $j): array
{
    $j = cast_numbers($j, [], ['job_id', 'fy', 'rows_done', 'rows_total', 'file_size', 'user_id']);
    $j['counts'] = $j['counts'] ? json_decode($j['counts'], true) : null;
    unset($j['state']);
    $j['fy_label'] = $j['fy'] !== null ? fy_label((int) $j['fy']) : null;
    return $j;
}

/** Queue a job unless the same one is already waiting or running. Returns the job id. */
function data_job_queue(string $kind, ?int $fy, ?int $userId, array $state = []): int
{
    $open = db_value("SELECT job_id FROM data_jobs WHERE kind = ? AND (fy <=> ?) AND status IN ('queued','running') LIMIT 1", [$kind, $fy]);
    if ($open) {
        return (int) $open;
    }
    db_query('INSERT INTO data_jobs (kind, fy, status, stage, state, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [$kind, $fy, 'queued', 'start', json_encode($state), $userId]);
    return (int) db_value('SELECT LAST_INSERT_ID()');
}

function data_jobs_open(): array
{
    return array_map('data_job_row', db_all("SELECT * FROM data_jobs WHERE status IN ('queued','running') ORDER BY job_id"));
}

/**
 * Work on the queued / running jobs for up to $budget seconds. Only one runner at a time (a lock row), so cron,
 * tick and the screen can all call this safely. Returns the jobs touched.
 */
function data_jobs_run(float $budget = 8.0): array
{
    $deadline = microtime(true) + $budget;
    $lock = db_query("UPDATE app_settings SET setting_value = ? WHERE setting_key = 'data_jobs_lock' AND (setting_value < ? OR setting_value = '')",
        [(string) (time() + (int) ceil($budget) + 60), (string) time()])->rowCount();
    if (!$lock) {
        // First run ever: create the lock row (a duplicate insert means another runner holds it).
        try {
            db_query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('data_jobs_lock', ?)", [(string) (time() + (int) ceil($budget) + 60)]);
        } catch (Throwable $e) {
            return [];
        }
    }
    $touched = [];
    try {
        @set_time_limit((int) ceil($budget) + 120);
        while (microtime(true) < $deadline) {
            $j = db_one("SELECT * FROM data_jobs WHERE status IN ('queued','running') ORDER BY job_id LIMIT 1");
            if (!$j) {
                break;
            }
            $touched[$j['job_id']] = true;
            data_job_step($j, $deadline);
        }
    } finally {
        db_query("UPDATE app_settings SET setting_value = '' WHERE setting_key = 'data_jobs_lock'");
    }
    return array_keys($touched);
}

function data_job_save(array $j, array $state, array $set = []): void
{
    $cols = ['state = ?', 'updated_at = NOW()'];
    $params = [json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    foreach ($set as $k => $v) {
        $cols[] = "$k = ?";
        $params[] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
    }
    $params[] = $j['job_id'];
    db_query('UPDATE data_jobs SET ' . implode(', ', $cols) . ' WHERE job_id = ?', $params);
}

function data_job_finish(array $j, array $state, string $status, string $message, array $set = []): void
{
    data_job_save($j, $state, $set + ['status' => $status, 'stage' => $status, 'message' => mb_substr($message, 0, 1000), 'finished_at' => date('Y-m-d H:i:s')]);
    try {
        log_activity("data.{$j['kind']}", null, null, ($j['fy'] !== null ? fy_label((int) $j['fy']) . ': ' : '') . $message, [], null,
            ['user_id' => $j['user_id'], 'name' => 'System', 'store_id' => null]);
    } catch (Throwable $e) {
    }
}

/** One slice of work on a job (until $deadline). Errors stop the job with the reason; it can be started again. */
function data_job_step(array $j, float $deadline): void
{
    $state = json_decode((string) $j['state'], true) ?: [];
    if ($j['status'] === 'queued') {
        db_query("UPDATE data_jobs SET status = 'running', started_at = COALESCE(started_at, NOW()) WHERE job_id = ?", [$j['job_id']]);
        $j['status'] = 'running';
    }
    try {
        switch ($j['kind']) {
            case 'daily':
            case 'archive':
                export_job_step($j, $state, $deadline);
                break;
            case 'purge':
                purge_job_step($j, $state, $deadline);
                break;
            case 'logs':
                logs_job_step($j, $state, $deadline);
                break;
            default:
                data_job_finish($j, $state, 'failed', 'Unknown job');
        }
    } catch (Throwable $e) {
        error_log('[Invoicyfy data job ' . $j['job_id'] . '] ' . $e->getMessage());
        data_job_finish($j, $state, 'failed', 'Stopped: ' . $e->getMessage());
        if ($j['kind'] === 'daily') {
            $s = drive_settings();
            $s['last_run_at'] = date('Y-m-d H:i:s');
            $s['last_result'] = 'Backup failed: ' . $e->getMessage();
            $s['last_ok'] = false;
            drive_save($s);
        }
    }
}

/* ------------------------------------------------------------------ export (nightly backup / year archive) */

/** The plan of an export: every table, with how its rows are chosen. */
function export_sections(string $from, ?string $to): array
{
    $sections = [];
    $existing = array_flip(backup_existing_tables());
    foreach (array_keys(BACKUP_TABLES) as $t) {
        if (!isset($existing[$t])) {
            continue;
        }
        if (isset(DATA_DATED[$t])) {
            $sections[] = ['table' => $t, 'mode' => 'dated'];
        } elseif (isset(DATA_CHILDREN[$t])) {
            if (isset($existing[DATA_CHILDREN[$t][0]])) {
                $sections[] = ['table' => $t, 'mode' => 'child'];
            }
        } else {
            $sections[] = ['table' => $t, 'mode' => 'all'];
        }
    }
    return ['from' => $from, 'to' => $to, 'sections' => $sections];
}

/** Next page of a dated table inside [from, to), after the cursor [date, id]. */
function dated_page(string $table, array $cursor, string $from, ?string $to, int $limit, string $cols = '*', string $extra = '', array $extraParams = []): array
{
    [$dc, $pk] = DATA_DATED[$table];
    $where = "`$dc` >= ?" . ($to ? " AND `$dc` < ?" : '');
    $params = $to ? [$from, $to] : [$from];
    if ($cursor) {
        $where .= " AND (`$dc` > ? OR (`$dc` = ? AND `$pk` > ?))";
        array_push($params, $cursor[0], $cursor[0], $cursor[1]);
    }
    return db_all("SELECT $cols FROM `$table` WHERE $where$extra ORDER BY `$dc`, `$pk` LIMIT $limit", array_merge($params, $extraParams));
}

function export_paths(array $j): array
{
    if ($j['kind'] === 'archive') {
        $name = str_replace(' ', '-', fy_label((int) $j['fy'])) . '-FINAL.json.gz';
        return [archive_dir() . "/.job{$j['job_id']}.json.part", archive_dir() . '/' . $name, $name];
    }
    $name = 'invoicyfy-backup-' . date('Ymd-His', strtotime($j['created_at'])) . '.json.gz';
    return [backup_dir() . "/.job{$j['job_id']}.json.part", backup_dir() . '/' . $name, $name];
}

function export_job_step(array $j, array $state, float $deadline): void
{
    [$raw, $gz, $name] = export_paths($j);
    if (empty($state['plan'])) {
        if ($j['kind'] === 'archive') {
            [$from, $to] = fy_range((int) $j['fy']);
            $state['plan'] = export_sections($from, $to);
        } else {
            $state['plan'] = export_sections(daily_backup_from(), null);
        }
        $state += ['i' => 0, 'cursor' => [], 'n' => 0, 'open' => false, 'counts' => [], 'stage' => 'write'];
        @unlink($raw);
        file_put_contents($raw, '{"format":"invoicyfy-backup","version":1,"created_at":' . json_encode(date('Y-m-d H:i:s'))
            . ',"kind":' . json_encode($j['kind']) . ($j['fy'] !== null ? ',"fy":' . json_encode(fy_label((int) $j['fy'])) : '')
            . ',"range":' . json_encode(['from' => $state['plan']['from'], 'to' => $state['plan']['to']]) . ',"tables":{');
        data_job_save($j, $state, ['stage' => 'write', 'file_name' => $name]);
    }
    $plan = $state['plan'];
    if ($state['stage'] === 'write') {
        $fh = fopen($raw, 'ab');
        if (!$fh) {
            throw new RuntimeException('Cannot write in storage/backups');
        }
        try {
            while ($state['i'] < count($plan['sections']) && microtime(true) < $deadline) {
                $sec = $plan['sections'][$state['i']];
                $t = $sec['table'];
                $cols = array_keys(backup_columns($t));
                if (!$state['open']) {
                    fwrite($fh, ($state['i'] ? ',' : '') . json_encode($t) . ':{"columns":' . json_encode($cols) . ',"rows":[');
                    $state['open'] = true;
                    $state['n'] = 0;
                    $state['cursor'] = [];
                }
                [$rows, $cursor, $done] = export_page($t, $sec['mode'], $cols, $state['cursor'], $plan['from'], $plan['to']);
                $buf = '';
                foreach ($rows as $r) {
                    $buf .= ($state['n']++ ? ',' : '') . json_encode(array_values($r), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                }
                fwrite($fh, $buf);
                $state['cursor'] = $cursor;
                if ($done) {
                    fwrite($fh, ']}');
                    $state['counts'][$t] = $state['n'];
                    $state['open'] = false;
                    $state['i']++;
                }
                $state['rows'] = array_sum($state['counts']) + ($state['open'] ? $state['n'] : 0);
            }
            if ($state['i'] >= count($plan['sections'])) {
                fwrite($fh, '}}');
                $state['stage'] = 'compress';
            }
        } finally {
            fclose($fh);
        }
        data_job_save($j, $state, ['stage' => $state['stage'], 'rows_done' => $state['rows'] ?? 0, 'counts' => $state['counts']]);
        if ($state['stage'] === 'write') {
            return;
        }
    }
    if ($state['stage'] === 'compress') {
        // One pass, one gzip stream (opens in any unzip tool and in the Backup screen). Fast: ~50 MB/s.
        @set_time_limit(600);
        $in = fopen($raw, 'rb');
        $out = gzopen($gz . '.tmp', 'wb6');
        if (!$in || !$out) {
            throw new RuntimeException('Cannot compress the backup file');
        }
        while (!feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        rename($gz . '.tmp', $gz);
        @unlink($raw);
        $state['stage'] = 'upload';
        $state['size'] = filesize($gz);
        data_job_save($j, $state, ['stage' => 'upload', 'file_size' => $state['size']]);
    }
    if ($state['stage'] === 'upload') {
        $ds = drive_settings();
        if ($ds['refresh_token'] === '') {
            $state['stage'] = 'local';
        } else {
            $token = drive_access_token($ds);
            if (empty($state['folder'])) {
                $fy = $j['kind'] === 'archive' ? (int) $j['fy'] : fy_current();
                $state['folder'] = $j['kind'] === 'archive' ? drive_fy_folder($token, $ds, $fy) : drive_fy_folder($token, $ds, $fy, 'daily');
                drive_save($ds);
            }
            $id = drive_upload_step($token, $state, $gz, $name, $deadline);
            if ($id === null) {
                data_job_save($j, $state, ['stage' => 'upload']);
                return;
            }
            $state['drive_id'] = $id;
            // Check Google has the whole file.
            [$c, $meta] = drive_http('GET', google_url('api') . '/files/' . rawurlencode($id) . '?fields=id,size', ['Authorization: Bearer ' . $token]);
            if ($c !== 200 || (int) ($meta['size'] ?? -1) !== (int) $state['size']) {
                throw new RuntimeException('Google Drive has a different file size than the backup; it will be uploaded again next time');
            }
            $state['stage'] = 'organize';
            data_job_save($j, $state, ['stage' => 'organize', 'drive_file_id' => $id]);
            if ($j['kind'] === 'daily') {
                drive_organize($token, $ds, $id, $name);
            }
        }
    }
    // Done: record the result.
    $mb = round(($state['size'] ?? 0) / 1048576, 2);
    $rows = array_sum($state['counts'] ?? []);
    $onDrive = !empty($state['drive_id']);
    if ($j['kind'] === 'archive') {
        $fy = (int) $j['fy'];
        $prev = fy_archive($fy);
        db_query(
            'REPLACE INTO fy_archives (fy, file_name, file_size, rows_total, counts, drive_file_id, drive_verified_at, created_at, job_id, purged_at, purge_job_id, downloaded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NULL)',
            [$fy, $name, $state['size'] ?? 0, $rows, json_encode($state['counts'] ?? []), $state['drive_id'] ?? null, $onDrive ? date('Y-m-d H:i:s') : null,
             $j['job_id'], $prev['purged_at'] ?? null, $prev['purge_job_id'] ?? null]
        );
        $msg = fy_label($fy) . " archive $name ($mb MB, " . number_format($rows) . ' records) ' . ($onDrive ? 'saved on Google Drive and checked' : 'saved on the server (Google Drive not connected)');
    } else {
        foreach (array_slice(backup_local_files(), max(1, (int) drive_settings()['local_keep'])) as $old) {
            @unlink(backup_dir() . '/' . $old['name']);
        }
        $msg = "Backup $name ($mb MB, " . number_format($rows) . ' records) saved on the server' . ($onDrive ? ' and uploaded to Google Drive' . (drive_settings()['email'] ? ' (' . drive_settings()['email'] . ')' : '') : '');
        $ds = drive_settings();
        $ds['last_run_at'] = date('Y-m-d H:i:s');
        $ds['last_result'] = $msg;
        $ds['last_ok'] = true;
        drive_save($ds);
        try {
            db_query('INSERT INTO backup_logs (action, user_id, file_name, summary) VALUES (?, ?, ?, ?)',
                [$onDrive ? 'drive' : 'auto', $j['user_id'], $name, json_encode(['ok' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
        }
    }
    data_job_finish($j, $state, 'done', $msg, ['rows_done' => $rows, 'rows_total' => $rows, 'file_size' => $state['size'] ?? 0, 'counts' => $state['counts'] ?? []]);
}

/** One page of rows for a section. Returns [rows, next cursor, finished]. */
function export_page(string $t, string $mode, array $cols, array $cursor, string $from, ?string $to): array
{
    $sel = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $cfg = BACKUP_TABLES[$t];
    if ($mode === 'all') {
        if ($cfg['pk']) {
            $last = $cursor[0] ?? null;
            $rows = db_all("SELECT $sel FROM `$t`" . ($last !== null ? " WHERE `{$cfg['pk']}` > ?" : '') . " ORDER BY `{$cfg['pk']}` LIMIT 2000", $last !== null ? [$last] : []);
            return [$rows, $rows ? [end($rows)[$cfg['pk']]] : $cursor, count($rows) < 2000];
        }
        $off = (int) ($cursor[0] ?? 0);
        $order = implode(', ', array_map(fn($c) => "`$c`", $cfg['unique'][0]));
        $rows = db_all("SELECT $sel FROM `$t` ORDER BY $order LIMIT 2000 OFFSET $off");
        return [$rows, [$off + count($rows)], count($rows) < 2000];
    }
    if ($mode === 'dated') {
        [$dc, $pk] = DATA_DATED[$t];
        $rows = dated_page($t, $cursor, $from, $to, 2000, "$sel, `$dc` AS __d");
        $next = $rows ? [end($rows)['__d'], end($rows)[$pk]] : $cursor;
        foreach ($rows as &$r) {
            unset($r['__d']);
        }
        unset($r);
        return [$rows, $next, count($rows) < 2000];
    }
    // child: the children of the next 1000 parents of the range.
    [$parent, $fk] = DATA_CHILDREN[$t];
    [$dc, $ppk] = DATA_DATED[$parent];
    $parents = dated_page($parent, $cursor, $from, $to, 1000, "`$ppk` AS id, `$dc` AS d");
    if (!$parents) {
        return [[], $cursor, true];
    }
    $ids = array_column($parents, 'id');
    $rows = db_all("SELECT $sel FROM `$t` WHERE `$fk` IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY `{$cfg['pk']}`");
    return [$rows, [end($parents)['d'], end($parents)['id']], count($parents) < 1000];
}

/* ------------------------------------------------------------------ Google Drive folders */

/** Folder by name inside a parent (found or created). Ids are remembered in the Drive settings. */
function drive_named_folder(string $token, array &$s, string $parentId, string $name): string
{
    $key = "$parentId/$name";
    $s['folders'] = is_array($s['folders'] ?? null) ? $s['folders'] : [];
    $auth = ['Authorization: Bearer ' . $token];
    if (!empty($s['folders'][$key])) {
        [$c, $f] = drive_http('GET', google_url('api') . '/files/' . rawurlencode($s['folders'][$key]) . '?fields=id,trashed', $auth);
        if ($c === 200 && empty($f['trashed'])) {
            return $s['folders'][$key];
        }
    }
    $q = rawurlencode("'$parentId' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace("'", "\\'", $name) . "'");
    [$c, $j] = drive_http('GET', google_url('api') . "/files?q=$q&fields=files(id,name)&pageSize=10", $auth);
    $id = $c === 200 ? ($j['files'][0]['id'] ?? null) : null;
    if (!$id) {
        [$c, $j] = drive_http('POST', google_url('api') . '/files?fields=id', array_merge($auth, ['Content-Type: application/json']),
            json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId]]));
        if ($c !== 200 || empty($j['id'])) {
            throw new RuntimeException("Could not create the folder $name on Drive: " . google_error($j, $c));
        }
        $id = $j['id'];
    }
    $s['folders'][$key] = $id;
    return $id;
}

/** Invoicyfy Backups / FY 2025-26 [/ daily | monthly] */
function drive_fy_folder(string $token, array &$s, int $fy, ?string $sub = null): string
{
    $root = drive_folder($token, $s);
    $year = drive_named_folder($token, $s, $root, fy_label($fy));
    return $sub ? drive_named_folder($token, $s, $year, $sub) : $year;
}

/** Resumable upload, continued across steps. Returns the Drive file id when finished, null when more is left. */
function drive_upload_step(string $token, array &$state, string $path, string $name, float $deadline): ?string
{
    $size = filesize($path);
    if (empty($state['session'])) {
        [$c, $j, , $h] = drive_http('POST', google_url('upload') . '/files?uploadType=resumable&fields=id', [
            'Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: application/gzip', "X-Upload-Content-Length: $size",
        ], json_encode(['name' => $name, 'parents' => [$state['folder']], 'mimeType' => 'application/gzip']));
        if ($c !== 200 || empty($h['location'])) {
            throw new RuntimeException('Drive upload could not start: ' . google_error($j, $c));
        }
        $state['session'] = $h['location'];
        $state['offset'] = 0;
    }
    $fh = fopen($path, 'rb');
    try {
        do {
            fseek($fh, (int) $state['offset']);
            $data = $size > 0 ? (string) fread($fh, 8 * 1024 * 1024) : '';
            $len = strlen($data);
            $range = $size > 0 ? 'bytes ' . $state['offset'] . '-' . ($state['offset'] + $len - 1) . "/$size" : 'bytes */0';
            [$c, $j, , $h] = drive_http('PUT', $state['session'], ["Content-Length: $len", "Content-Range: $range"], $data);
            if ($c === 200 || $c === 201) {
                return (string) ($j['id'] ?? '');
            }
            if ($c === 404 || $c === 410) {
                unset($state['session']); // upload session expired: start again next step
                return null;
            }
            if ($c !== 308) {
                throw new RuntimeException('Drive upload failed: ' . google_error($j, $c));
            }
            // Google tells how much it has: "Range: bytes=0-N".
            $state['offset'] = isset($h['range']) && preg_match('/-(\d+)$/', $h['range'], $m) ? (int) $m[1] + 1 : $state['offset'] + $len;
        } while (microtime(true) < $deadline && $state['offset'] < $size);
    } finally {
        fclose($fh);
    }
    return null;
}

/** After a nightly backup: first backup of the month also goes to "monthly"; old daily / monthly copies are removed. */
function drive_organize(string $token, array &$s, string $fileId, string $name): void
{
    $auth = ['Authorization: Bearer ' . $token];
    $monthly = drive_fy_folder($token, $s, fy_current(), 'monthly');
    $monthName = 'invoicyfy-backup-' . date('Y-m') . '.json.gz';
    $q = rawurlencode("'$monthly' in parents and trashed = false and name = '$monthName'");
    [$c, $j] = drive_http('GET', google_url('api') . "/files?q=$q&fields=files(id)", $auth);
    if ($c === 200 && empty($j['files'])) {
        drive_http('POST', google_url('api') . '/files/' . rawurlencode($fileId) . '/copy?fields=id', array_merge($auth, ['Content-Type: application/json']),
            json_encode(['name' => $monthName, 'parents' => [$monthly]]));
    }
    drive_save($s);
    // Keep the newest N daily and 12 monthly copies over all years (and the old root-folder backups of earlier versions).
    $groups = ['daily' => (int) $s['keep'], 'monthly' => (int) ($s['keep_monthly'] ?? 12)];
    foreach ($groups as $sub => $keep) {
        $folders = [];
        foreach ($s['folders'] ?? [] as $key => $id) {
            if (str_ends_with($key, "/$sub")) {
                $folders[] = $id;
            }
        }
        $files = [];
        foreach ($folders as $fid) {
            $q = rawurlencode("'$fid' in parents and trashed = false and name contains 'invoicyfy-backup-'");
            [$c, $j] = drive_http('GET', google_url('api') . "/files?q=$q&pageSize=500&fields=files(id,name,createdTime)", $auth);
            foreach ($c === 200 ? $j['files'] ?? [] : [] as $f) {
                $files[] = $f;
            }
        }
        usort($files, fn($a, $b) => strcmp($b['name'], $a['name']));
        foreach (array_slice($files, max(1, $keep)) as $f) {
            drive_http('DELETE', google_url('api') . '/files/' . rawurlencode($f['id']), $auth);
        }
    }
    if (!empty($s['folder_id'])) {
        drive_cleanup($token, $s['folder_id'], max(1, (int) $s['keep']));
    }
}

/* ------------------------------------------------------------------ purge */

/** What a purge of one year removes and keeps (row counts), for the screen before starting. */
function purge_preview(int $fy): array
{
    [$from, $to] = fy_range($fy);
    $c = fn(string $sql, array $p = []) => (int) (db_value($sql, $p) ?? 0);
    $r = [$from, $to];
    $remove = [
        'invoices' => $c("SELECT COUNT(*) FROM invoices WHERE invoice_date >= ? AND invoice_date < ? AND status NOT IN ('Unpaid','Partial')", $r),
        'payments' => $c('SELECT COUNT(*) FROM payments WHERE payment_date >= ? AND payment_date < ?', $r),
        'orders' => $c("SELECT COUNT(*) FROM orders WHERE order_date >= ? AND order_date < ? AND status NOT IN ('Pending','Approved')", $r),
        'expenses' => $c('SELECT COUNT(*) FROM expenses WHERE expense_date >= ? AND expense_date < ?', $r),
        'stock_movements' => $c('SELECT COUNT(*) FROM stock_movements WHERE created_at >= ? AND created_at < ?', $r),
    ];
    $kept = [
        'unpaid_bills' => $c("SELECT COUNT(*) FROM invoices WHERE status IN ('Unpaid','Partial') AND invoice_date >= ? AND invoice_date < ?", $r),
        'open_orders' => $c("SELECT COUNT(*) FROM orders WHERE order_date >= ? AND order_date < ? AND status IN ('Pending','Approved')", $r),
    ];
    if (v4_ready()) {
        $remove['purchases'] = $c("SELECT COUNT(*) FROM purchases WHERE purchase_date >= ? AND purchase_date < ? AND (balance_due <= 0 OR status = 'Cancelled')", $r);
        $remove['supplier_payments'] = $c('SELECT COUNT(*) FROM supplier_payments WHERE payment_date >= ? AND payment_date < ?', $r);
        $kept['unpaid_purchases'] = $c("SELECT COUNT(*) FROM purchases WHERE purchase_date >= ? AND purchase_date < ? AND balance_due > 0 AND status <> 'Cancelled'", $r);
    }
    try {
        $remove['cash_shifts'] = $c("SELECT COUNT(*) FROM cash_sessions WHERE opened_at >= ? AND opened_at < ? AND status <> 'Open'", $r);
        $remove['loyalty_entries'] = $c('SELECT COUNT(*) FROM loyalty_ledger WHERE created_at >= ? AND created_at < ?', $r);
    } catch (Throwable $e) {
    }
    return ['fy' => $fy, 'fy_label' => fy_label($fy), 'from' => $from, 'to' => date('Y-m-d', strtotime("$to -1 day")), 'remove' => $remove, 'kept' => $kept];
}

/** Why a year cannot be purged now (null = it can). */
function purge_blocker(int $fy, ?array $s = null): ?string
{
    $s ??= retention_settings();
    $cut = purge_cutoff_fy($s);
    if ($cut === null) {
        return 'Purging is off. Choose how many years to keep first.';
    }
    if ($fy >= $cut) {
        return fy_label($fy) . ' is inside the years you keep.';
    }
    $a = fy_archive($fy);
    if ($a && $a['purged_at']) {
        return fy_label($fy) . ' is already purged.';
    }
    $first = data_first_fy();
    for ($y = $first ?? $fy; $y < $fy; $y++) {
        $older = fy_archive($y);
        if (!($older && $older['purged_at']) && db_value('SELECT 1 FROM invoices WHERE invoice_date >= ? AND invoice_date < ? LIMIT 1', fy_range($y))) {
            return 'Purge ' . fy_label($y) . ' first (oldest year first).';
        }
    }
    foreach ([$fy, $fy + 1] as $y) {
        // The next year matters only when it has bills (its payments may belong to this year's bills).
        if ($y >= fy_current() || ($y > $fy && !db_value('SELECT 1 FROM invoices WHERE invoice_date >= ? AND invoice_date < ? LIMIT 1', fy_range($y)))) {
            continue;
        }
        $ay = fy_archive($y);
        if (!fy_archive_safe($ay, $s) && !($ay && $ay['purged_at'])) {
            return 'The archive of ' . fy_label($y) . ' must be safely stored first' . ($s['allow_local_archive'] ? ' (on Google Drive, or downloaded from the server).' : ' on Google Drive.');
        }
    }
    return null;
}

function purge_job_step(array $j, array $state, float $deadline): void
{
    $fy = (int) $j['fy'];
    [$from, $to] = fy_range($fy);
    if (empty($state['sections'])) {
        if ($b = purge_blocker($fy)) {
            data_job_finish($j, $state, 'failed', $b);
            return;
        }
        $state = ['sections' => ['invoices', 'payments', 'orders', 'expenses', 'stock_movements', 'cash_sessions', 'purchases', 'supplier_payments', 'loyalty_ledger', 'coupon_redemptions', 'stock_batches'],
            'i' => 0, 'cursor' => [], 'counts' => []];
        $total = array_sum(purge_preview($fy)['remove']);
        data_job_save($j, $state, ['stage' => 'purge', 'rows_total' => $total]);
    }
    global $pdo;
    while ($state['i'] < count($state['sections']) && microtime(true) < $deadline) {
        $sec = $state['sections'][$state['i']];
        $pdo->beginTransaction();
        try {
            [$n, $cursor, $done] = purge_batch($sec, $state['cursor'], $from, $to, $state['counts']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $state['cursor'] = $cursor;
        if ($done) {
            $state['i']++;
            $state['cursor'] = [];
        }
        data_job_save($j, $state, ['rows_done' => array_sum($state['counts']), 'counts' => $state['counts']]);
    }
    if ($state['i'] < count($state['sections'])) {
        return;
    }
    // Report summaries of these days stay as they are; keep them out of any recompute.
    $s = retention_settings();
    if (!$s['purged_before'] || $s['purged_before'] < $to) {
        $s['purged_before'] = $to;
        retention_save($s);
    }
    db_query('DELETE FROM stat_dirty WHERE day < ?', [$to]);
    db_query('UPDATE fy_archives SET purged_at = NOW(), purge_job_id = ? WHERE fy = ?', [$j['job_id'], $fy]);
    $n = array_sum($state['counts']);
    data_job_finish($j, $state, 'done', fy_label($fy) . ' purged: ' . number_format($n) . ' old records removed. Unpaid bills, pending orders and unpaid supplier bills were kept.',
        ['rows_done' => $n]);
}

/** Deletes one batch of a section. Returns [rows removed, next cursor, section finished]. */
function purge_batch(string $sec, array $cursor, string $from, string $to, array &$counts): array
{
    $in = fn(array $ids) => implode(',', array_map('intval', $ids));
    $add = function (string $k, int $n) use (&$counts) {
        if ($n) {
            $counts[$k] = ($counts[$k] ?? 0) + $n;
        }
    };
    $del = fn(string $sql) => db_query($sql)->rowCount();
    switch ($sec) {
        case 'invoices':
            $page = dated_page('invoices', $cursor, $from, $to, 1000, 'invoice_id, invoice_date AS d, status');
            $ids = array_column(array_filter($page, fn($r) => !in_array($r['status'], ['Unpaid', 'Partial'], true)), 'invoice_id');
            $n = 0;
            if ($ids) {
                $l = $in($ids);
                $add('loyalty_ledger', cascade_safe_delete("DELETE FROM loyalty_ledger WHERE invoice_id IN ($l)"));
                $add('coupon_redemptions', cascade_safe_delete("DELETE FROM coupon_redemptions WHERE invoice_id IN ($l)"));
                $add('payments', $del("DELETE FROM payments WHERE invoice_id IN ($l)"));
                $add('invoice_items', $del("DELETE FROM invoice_items WHERE invoice_id IN ($l)"));
                $add('invoices', $n = $del("DELETE FROM invoices WHERE invoice_id IN ($l)"));
            }
            return [$n, $page ? [end($page)['d'], end($page)['invoice_id']] : $cursor, count($page) < 1000];
        case 'payments':
            // Payments not linked to a kept bill (bill payments went with their bill above).
            $page = dated_page('payments', $cursor, $from, $to, 2000, 'payment_id, payment_date AS d, invoice_id');
            $ids = array_column(array_filter($page, fn($r) => $r['invoice_id'] === null), 'payment_id');
            $n = $ids ? $del('DELETE FROM payments WHERE payment_id IN (' . $in($ids) . ')') : 0;
            $add('payments', $n);
            return [$n, $page ? [end($page)['d'], end($page)['payment_id']] : $cursor, count($page) < 2000];
        case 'orders':
            $page = dated_page('orders', $cursor, $from, $to, 1000, 'order_id, order_date AS d, status');
            $ids = array_column(array_filter($page, fn($r) => !in_array($r['status'], ['Pending', 'Approved'], true)), 'order_id');
            $n = 0;
            if ($ids) {
                $l = $in($ids);
                $add('payments', $del("DELETE FROM payments WHERE order_id IN ($l) AND invoice_id IS NULL"));
                db_query("UPDATE payments SET order_id = NULL WHERE order_id IN ($l)");
                $add('order_items', $del("DELETE FROM order_items WHERE order_id IN ($l)"));
                $add('orders', $n = $del("DELETE FROM orders WHERE order_id IN ($l)"));
            }
            return [$n, $page ? [end($page)['d'], end($page)['order_id']] : $cursor, count($page) < 1000];
        case 'expenses':
        case 'stock_movements':
        case 'loyalty_ledger':
        case 'coupon_redemptions':
            try {
                [$dc, $pk] = DATA_DATED[$sec];
                $page = dated_page($sec, $cursor, $from, $to, 2000, "`$pk` AS id, `$dc` AS d");
            } catch (PDOException $e) {
                return [0, $cursor, true]; // table not there (database not updated)
            }
            $n = $page ? $del("DELETE FROM `$sec` WHERE `$pk` IN (" . $in(array_column($page, 'id')) . ')') : 0;
            $add($sec, $n);
            return [$n, $page ? [end($page)['d'], end($page)['id']] : $cursor, count($page) < 2000];
        case 'cash_sessions':
            try {
                $page = dated_page('cash_sessions', $cursor, $from, $to, 500, 'session_id, opened_at AS d, status');
            } catch (PDOException $e) {
                return [0, $cursor, true];
            }
            $ids = array_column(array_filter($page, fn($r) => $r['status'] !== 'Open'), 'session_id');
            $n = 0;
            if ($ids) {
                $l = $in($ids);
                $add('cash_movements', $del("DELETE FROM cash_movements WHERE session_id IN ($l)"));
                $add('cash_sessions', $n = $del("DELETE FROM cash_sessions WHERE session_id IN ($l)"));
            }
            return [$n, $page ? [end($page)['d'], end($page)['session_id']] : $cursor, count($page) < 500];
        case 'purchases':
            if (!v4_ready()) {
                return [0, $cursor, true];
            }
            $page = dated_page('purchases', $cursor, $from, $to, 500, 'purchase_id, purchase_date AS d, supplier_id, status, balance_due, total_amount');
            $gone = array_filter($page, fn($r) => $r['status'] === 'Cancelled' || (float) $r['balance_due'] <= 0);
            $n = 0;
            if ($gone) {
                $l = $in(array_column($gone, 'purchase_id'));
                // Supplier balance = opening + bills - payments: carry the removed amounts into the opening balance.
                foreach (db_all("SELECT supplier_id, SUM(amount) AS paid FROM supplier_payments WHERE purchase_id IN ($l) GROUP BY supplier_id") as $p) {
                    db_query('UPDATE suppliers SET opening_balance = opening_balance - ? WHERE supplier_id = ?', [$p['paid'], $p['supplier_id']]);
                }
                $add('supplier_payments', $del("DELETE FROM supplier_payments WHERE purchase_id IN ($l)"));
                $bySupplier = [];
                foreach ($gone as $g) {
                    if ($g['supplier_id'] !== null && $g['status'] !== 'Cancelled') {
                        $bySupplier[$g['supplier_id']] = ($bySupplier[$g['supplier_id']] ?? 0) + (float) $g['total_amount'];
                    }
                }
                foreach ($bySupplier as $sid => $amt) {
                    db_query('UPDATE suppliers SET opening_balance = opening_balance + ? WHERE supplier_id = ?', [round($amt, 2), $sid]);
                }
                db_query("UPDATE stock_batches SET purchase_id = NULL WHERE purchase_id IN ($l)");
                $add('purchase_items', $del("DELETE FROM purchase_items WHERE purchase_id IN ($l)"));
                $add('purchases', $n = $del("DELETE FROM purchases WHERE purchase_id IN ($l)"));
            }
            return [$n, $page ? [end($page)['d'], end($page)['purchase_id']] : $cursor, count($page) < 500];
        case 'supplier_payments':
            if (!v4_ready()) {
                return [0, $cursor, true];
            }
            $page = dated_page('supplier_payments', $cursor, $from, $to, 1000, 'payment_id, payment_date AS d, supplier_id, amount');
            $n = 0;
            if ($page) {
                foreach (array_reduce($page, function ($a, $p) {
                    $a[$p['supplier_id']] = ($a[$p['supplier_id']] ?? 0) + (float) $p['amount'];
                    return $a;
                }, []) as $sid => $amt) {
                    db_query('UPDATE suppliers SET opening_balance = opening_balance - ? WHERE supplier_id = ?', [round($amt, 2), $sid]);
                }
                $add('supplier_payments', $n = $del('DELETE FROM supplier_payments WHERE payment_id IN (' . $in(array_column($page, 'payment_id')) . ')'));
            }
            return [$n, $page ? [end($page)['d'], end($page)['payment_id']] : $cursor, count($page) < 1000];
        case 'stock_batches':
            if (!v4_ready()) {
                return [0, $cursor, true];
            }
            $last = (int) ($cursor[0] ?? 0);
            $page = db_all('SELECT batch_id FROM stock_batches WHERE batch_id > ? AND created_at >= ? AND created_at < ? AND qty_left <= 0 ORDER BY batch_id LIMIT 2000', [$last, $from, $to]);
            $n = $page ? $del('DELETE FROM stock_batches WHERE batch_id IN (' . $in(array_column($page, 'batch_id')) . ')') : 0;
            $add('stock_batches', $n);
            return [$n, $page ? [end($page)['batch_id']] : $cursor, count($page) < 2000];
    }
    return [0, $cursor, true];
}

function cascade_safe_delete(string $sql): int
{
    try {
        return db_query($sql)->rowCount();
    } catch (PDOException $e) {
        return 0;
    }
}

/* ------------------------------------------------------------------ logs (activity log, message logs) */

function logs_cutoff(?array $s = null): ?string
{
    $s ??= retention_settings();
    $m = (int) $s['logs_keep_months'];
    return $m > 0 ? date('Y-m-01', strtotime("-$m months")) : null;
}

function logs_preview(): array
{
    $cut = logs_cutoff();
    if (!$cut) {
        return ['cutoff' => null, 'activity_log' => 0, 'messages' => 0, 'backup_logs' => 0];
    }
    $c = fn(string $sql) => (int) (db_value($sql, [$cut]) ?? 0);
    return ['cutoff' => $cut, 'activity_log' => $c('SELECT COUNT(*) FROM activity_log WHERE created_at < ?'),
        'messages' => $c("SELECT COUNT(*) FROM campaign_recipients r JOIN campaigns c ON c.campaign_id = r.campaign_id WHERE c.created_at < ? AND c.status NOT IN ('Sending','Scheduled')"),
        'backup_logs' => $c('SELECT COUNT(*) FROM backup_logs WHERE created_at < ?')];
}

function logs_job_step(array $j, array $state, float $deadline): void
{
    $cut = $state['cutoff'] ?? logs_cutoff();
    if (!$cut) {
        data_job_finish($j, $state, 'failed', 'Log keeping is set to "forever"');
        return;
    }
    $state['cutoff'] = $cut;
    $state['counts'] ??= [];
    $state['phase'] ??= 'activity_log';
    while (microtime(true) < $deadline) {
        if ($state['phase'] === 'activity_log') {
            $ids = array_column(db_all('SELECT log_id FROM activity_log WHERE created_at < ? ORDER BY created_at, log_id LIMIT 5000', [$cut]), 'log_id');
            $n = $ids ? db_query('DELETE FROM activity_log WHERE log_id IN (' . implode(',', array_map('intval', $ids)) . ')')->rowCount() : 0;
            $state['counts']['activity_log'] = ($state['counts']['activity_log'] ?? 0) + $n;
            if (count($ids) < 5000) {
                $state['phase'] = 'messages';
            }
        } elseif ($state['phase'] === 'messages') {
            $c = db_one("SELECT campaign_id FROM campaigns WHERE created_at < ? AND status NOT IN ('Sending','Scheduled') ORDER BY campaign_id LIMIT 1", [$cut]);
            if (!$c) {
                $state['phase'] = 'backup_logs';
                continue;
            }
            $n = db_query('DELETE FROM campaign_recipients WHERE campaign_id = ? LIMIT 5000', [$c['campaign_id']])->rowCount();
            $state['counts']['messages'] = ($state['counts']['messages'] ?? 0) + $n;
            if ($n < 5000) {
                db_query('DELETE FROM campaigns WHERE campaign_id = ?', [$c['campaign_id']]);
                $state['counts']['campaigns'] = ($state['counts']['campaigns'] ?? 0) + 1;
            }
        } else {
            $state['counts']['backup_logs'] = db_query('DELETE FROM backup_logs WHERE created_at < ?', [$cut])->rowCount();
            $n = array_sum($state['counts']);
            data_job_finish($j, $state, 'done', 'Old logs before ' . $cut . ' removed: ' . number_format($n) . ' entries.', ['rows_done' => $n, 'counts' => $state['counts']]);
            return;
        }
        data_job_save($j, $state, ['rows_done' => array_sum($state['counts']), 'counts' => $state['counts'], 'stage' => $state['phase']]);
    }
}

/* ------------------------------------------------------------------ nightly planning */

/**
 * Called by cron / tick: once a night after the backup time it queues the nightly backup (when turned on), FINAL archives
 * of finished years, and — only when the Admin turned it on — purge of years past the keep period and of old logs.
 * Returns the queued job ids (empty on every other call).
 */
function data_nightly_queue(): array
{
    $queued = [];
    $ds = drive_settings();
    $time = $ds['time'] ?: '02:00';
    if (date('H:i') < $time) {
        return [];
    }
    if ($ds['enabled'] && claim_job('backup', 0, date('Y-m-d'))) {
        $queued[] = data_job_queue('daily', null, null);
    }
    if (!claim_job('data_night', 0, date('Y-m-d'))) {
        return $queued;
    }
    $s = retention_settings();
    $first = data_first_fy();
    if ($s['auto_archive'] && $first !== null) {
        for ($fy = $first; $fy < fy_current(); $fy++) {
            $a = fy_archive($fy);
            if ((!$a || !fy_archive_safe($a, $s)) && !($a && $a['purged_at'])
                && db_value('SELECT 1 FROM invoices WHERE invoice_date >= ? AND invoice_date < ? LIMIT 1', fy_range($fy))) {
                $queued[] = data_job_queue('archive', $fy, null);
            }
        }
    }
    if ($s['auto_purge'] && ($cut = purge_cutoff_fy($s)) !== null && $first !== null) {
        for ($fy = $first; $fy < $cut; $fy++) {
            if (!purge_blocker($fy, $s)) {
                $queued[] = data_job_queue('purge', $fy, null);
                break; // one year a night, oldest first
            }
        }
        if (logs_cutoff($s)) {
            $queued[] = data_job_queue('logs', null, null);
        }
    }
    return $queued;
}

/** Years with data, for the screen: bills and sales (from the day summaries), archive and purge status. */
function data_years_overview(): array
{
    $s = retention_settings();
    $cut = purge_cutoff_fy($s);
    $rows = [];
    try {
        foreach (db_all("SELECT IF(MONTH(day) >= 4, YEAR(day), YEAR(day) - 1) AS fy, SUM(bills) AS bills, SUM(CASE WHEN status <> 'Cancelled' THEN total ELSE 0 END) AS sales
                         FROM stat_sales_daily GROUP BY fy ORDER BY fy DESC") as $r) {
            $rows[(int) $r['fy']] = ['bills' => (int) $r['bills'], 'sales' => money($r['sales'])];
        }
    } catch (Throwable $e) {
    }
    $first = data_first_fy();
    if ($first !== null) {
        for ($fy = $first; $fy <= fy_current(); $fy++) {
            $rows[$fy] ??= ['bills' => 0, 'sales' => 0.0];
        }
    }
    foreach (db_all('SELECT * FROM fy_archives') as $a) {
        $rows[(int) $a['fy']] ??= ['bills' => 0, 'sales' => 0.0];
    }
    krsort($rows);
    $open = [];
    foreach (data_jobs_open() as $j) {
        $open[$j['kind'] . ':' . $j['fy']] = $j;
    }
    $out = [];
    foreach ($rows as $fy => $r) {
        $a = fy_archive($fy);
        // Years with nothing in them (a gap before the shop used the app) are not listed.
        if (!$r['bills'] && !$a && $fy !== fy_current() && !db_value('SELECT 1 FROM invoices WHERE invoice_date >= ? AND invoice_date < ? LIMIT 1', fy_range($fy))) {
            continue;
        }
        $done = $fy < fy_current();
        $out[] = $r + [
            'fy' => $fy, 'fy_label' => fy_label($fy), 'range' => fy_range($fy), 'finished' => $done,
            'archive' => $a ? ['file_name' => $a['file_name'], 'file_size' => (int) $a['file_size'], 'rows' => (int) $a['rows_total'],
                'on_drive' => (bool) $a['drive_verified_at'], 'drive_verified_at' => $a['drive_verified_at'], 'created_at' => $a['created_at'],
                'downloaded_at' => $a['downloaded_at'], 'local' => is_file(archive_dir() . '/' . $a['file_name']), 'safe' => fy_archive_safe($a, $s)] : null,
            'purged_at' => $a['purged_at'] ?? null,
            'in_keep_period' => $cut === null || $fy >= $cut,
            'purge_blocker' => $done ? purge_blocker($fy, $s) : 'Running year',
            'archive_job' => $open["archive:$fy"] ?? null,
            'purge_job' => $open["purge:$fy"] ?? null,
        ];
    }
    return $out;
}
