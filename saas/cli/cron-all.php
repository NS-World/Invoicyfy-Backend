<?php
/**
 * One cron line for all shops (every 5 minutes):  SAAS_MODE=true php saas/cli/cron-all.php
 * Runs each shop's background work (offers, wishes, night backup, data jobs) and once a day the plan reminders.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../lib/env.php';
require_once __DIR__ . '/../../lib/saas.php';
$started = microtime(true);
$n = 0;
foreach (saas_control_pdo()->query("SELECT * FROM saas_tenants WHERE status <> 'suspended' ORDER BY tenant_id")->fetchAll() as $t) {
    if (saas_effective_status($t) === 'expired') {
        continue;
    }
    $cmd = 'TENANT=' . escapeshellarg($t['slug']) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../cron/process-campaigns.php') . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) {
        error_log("[Khanak cron] {$t['slug']}: " . implode(' | ', array_slice($out, -3)));
    }
    $out = [];
    $n++;
}
$flag = __DIR__ . '/../../storage/saas/daily-' . date('Y-m-d');
if (!is_file($flag)) {
    @mkdir(dirname($flag), 0750, true);
    @touch($flag);
    $done = saas_daily();
    echo 'daily: ' . json_encode($done) . PHP_EOL;
}
printf("%d shops in %.1fs\n", $n, microtime(true) - $started);
