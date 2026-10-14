<?php
/** GET — automatic backup / Google Drive settings (Admin only). Secrets are never sent back. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/drive.php';
allow_methods('GET');
$user = require_auth();
require_admin($user);
json_out(['status' => 'success', 'data' => drive_public(drive_settings())]);
