<?php
// php -S 127.0.0.1:8091 tests/mock_v4_router.php   (state: <tmp>/invoicyfy-mock-v4.json, delete it for a fresh run)
// Mock SMS gateway + Google OAuth / Drive for the V4 API test. State in state.json next to this file.
$file = sys_get_temp_dir() . '/invoicyfy-mock-v4.json';
$st = is_file($file) ? json_decode(file_get_contents($file), true) : ['sms' => [], 'files' => [], 'uploads' => [], 'n' => 0];
$save = function () use (&$st, $file) { file_put_contents($file, json_encode($st)); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');
header('Content-Type: application/json');
if ($path === '/sms') {
    $st['sms'][] = $_GET;
    $save();
    echo '{"ok":true}';
    return;
}
if ($path === '/state') { echo json_encode($st); return; }
if ($path === '/token') {
    parse_str($body, $p);
    if (($p['client_secret'] ?? '') !== 'test-secret') { http_response_code(401); echo '{"error":"invalid_client","error_description":"bad secret"}'; return; }
    if (($p['grant_type'] ?? '') === 'authorization_code') {
        if (($p['code'] ?? '') !== 'good-code') { http_response_code(400); echo '{"error":"invalid_grant"}'; return; }
        echo '{"access_token":"at-1","refresh_token":"rt-1","expires_in":3600}'; return;
    }
    if (($p['refresh_token'] ?? '') === 'rt-1') { echo '{"access_token":"at-2","expires_in":3600}'; return; }
    http_response_code(400); echo '{"error":"invalid_grant"}'; return;
}
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($path, '/drive') && !preg_match('/^Bearer at-/', $auth) && !str_starts_with($path, '/drive/session')) { http_response_code(401); echo '{"error":{"message":"no auth"}}'; return; }
if ($path === '/drive/about') { echo '{"user":{"emailAddress":"owner@example.com"}}'; return; }
if ($path === '/drive/files' && $m === 'POST') {
    $d = json_decode($body, true); $id = 'f' . (++$st['n']);
    $st['files'][$id] = ['id' => $id, 'name' => $d['name'], 'folder' => true, 'parents' => []]; $save();
    echo json_encode(['id' => $id]); return;
}
if ($path === '/drive/files' && $m === 'GET') {
    preg_match("/'([^']+)' in parents/", $_GET['q'] ?? '', $mm);
    $list = array_values(array_filter($st['files'], fn($f) => in_array($mm[1] ?? '', $f['parents'] ?? [], true) && !($f['deleted'] ?? false)));
    usort($list, fn($a, $b) => $b['n'] <=> $a['n']);
    echo json_encode(['files' => $list]); return;
}
if (preg_match('#^/drive/files/(\w+)$#', $path, $mm)) {
    $f = $st['files'][$mm[1]] ?? null;
    if (!$f || ($f['deleted'] ?? false)) { http_response_code(404); echo '{"error":{"message":"not found"}}'; return; }
    if ($m === 'DELETE') { $st['files'][$mm[1]]['deleted'] = true; $save(); http_response_code(204); return; }
    echo json_encode(['id' => $f['id'], 'trashed' => false]); return;
}
if ($path === '/upload/files' && $m === 'POST') {
    $d = json_decode($body, true); $sid = 's' . (++$st['n']);
    $st['uploads'][$sid] = ['name' => $d['name'], 'parents' => $d['parents'], 'size' => (int) ($_SERVER['HTTP_X_UPLOAD_CONTENT_LENGTH'] ?? 0), 'got' => 0, 'md5' => ''];
    $save();
    $host = $_SERVER['HTTP_HOST'];
    header("Location: http://$host/drive/session/$sid", true, 200); echo '{}'; return;
}
if (preg_match('#^/drive/session/(\w+)$#', $path, $mm) && $m === 'PUT') {
    $u = &$st['uploads'][$mm[1]];
    $u['got'] += strlen($body);
    file_put_contents(sys_get_temp_dir() . '/invoicyfy-mock-upload-' . $mm[1] . '.bin', $body, FILE_APPEND);
    if ($u['got'] >= $u['size']) {
        $id = 'f' . (++$st['n']);
        $st['files'][$id] = ['id' => $id, 'name' => $u['name'], 'parents' => $u['parents'], 'n' => $st['n'], 'size' => $u['got'], 'blob' => 'upload-' . $mm[1] . '.bin'];
        $save(); http_response_code(200); echo json_encode(['id' => $id]); return;
    }
    $save(); http_response_code(308); header('Range: bytes=0-' . ($u['got'] - 1)); return;
}
http_response_code(404); echo '{"error":{"message":"mock: unknown ' . $path . '"}}';
