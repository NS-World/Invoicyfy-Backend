<?php
require_once __DIR__ . '/../lib/bootstrap.php';
allow_methods('GET');

$user = require_auth();
json_out(["status" => "success", "data" => public_user($user)]);
