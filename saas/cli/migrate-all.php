<?php
/**
 * Brings every shop's database to the latest schema (run after uploading a new version).
 *   SAAS_MODE=true php saas/cli/migrate-all.php [--shop=scoopiez]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../lib/env.php';
require_once __DIR__ . '/../../lib/saas.php';
$only = getopt('', ['shop::'])['shop'] ?? null;
$failed = 0;
foreach (saas_control_pdo()->query('SELECT slug, db_name FROM saas_tenants ORDER BY tenant_id')->fetchAll() as $t) {
    if ($only && $only !== $t['slug']) {
        continue;
    }
    // Each shop in its own process, so its settings (database, secrets) never mix with another shop's.
    $cmd = 'TENANT=' . escapeshellarg($t['slug']) . ' DB_NAME=' . escapeshellarg($t['db_name']) . ' ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/../../database/migrate.php') . ' 2>&1';
    exec($cmd, $out, $code);
    $last = end($out) ?: '';
    echo str_pad($t['slug'], 32) . ($code === 0 ? 'ok   ' : 'FAIL ') . $last . PHP_EOL;
    $failed += $code === 0 ? 0 : 1;
    $out = [];
}
exit($failed ? 1 : 0);
