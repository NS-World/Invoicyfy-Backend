<?php
/** Admin: create new tables / columns after uploading a new version (same as `php database/migrate.php`, no SSH needed). */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/migrator.php';
allow_methods('POST');
$user = require_auth();
require_admin($user);
@set_time_limit(300);
global $pdo;
$result = run_migrations($pdo, (string) db_value('SELECT DATABASE()'));
json_out(['status' => 'success', 'message' => $result['changes'] ? "Database updated ({$result['changes']} change(s))" : 'Database already up to date', 'data' => $result]);
