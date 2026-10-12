<?php
/** POST { store_id, email } — send one test email with the saved SMTP settings. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('POST');
$user = require_auth();
$d = input();
$store = get_store(write_store($user, $d['store_id'] ?? null));
$to = filter_var(trim((string) ($d['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: fail('Enter a valid email address to test');
[$ok, , $err] = email_send($store, $to, 'Test email from ' . $store['name'], "Hello!\nThis is a test offer email from " . $store['name'] . ". Your email setup works. 🎉");
$ok || fail($err);
json_out(['status' => 'success', 'message' => "Test email sent to $to"]);
