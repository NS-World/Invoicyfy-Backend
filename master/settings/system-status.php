<?php
/** Admin: is the database up to date with this version of the app? (lists missing tables / columns) */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/migrator.php';
allow_methods('GET');
$user = require_auth();
require_admin($user);
global $pdo;
$pending = pending_schema_changes($pdo, (string) db_value('SELECT DATABASE()'));
json_out(['status' => 'success', 'data' => [
    'needs_update' => count($pending) > 0,
    'pending' => array_slice($pending, 0, 50),
    'php_version' => PHP_VERSION,
    'cron_last_run' => db_value("SELECT MAX(sent_at) FROM campaign_recipients WHERE status = 'Sent'"),
]]);
