<?php
/**
 * Legacy include used by older endpoints: validates the JWT and exposes $user_id / $authUser.
 * New endpoints should use lib/bootstrap.php + require_auth() directly.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
$authUser = require_auth();
$user_id = (int) $authUser['user_id'];
