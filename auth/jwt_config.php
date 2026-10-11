<?php
$accessKey = getenv('JWT_ACCESS_SECRET');
$refreshKey = getenv('JWT_REFRESH_SECRET');

if ($accessKey === false || $accessKey === '' || $refreshKey === false || $refreshKey === '') {
    throw new RuntimeException('JWT_ACCESS_SECRET and JWT_REFRESH_SECRET must be configured.');
}

$key = $accessKey;
$issuer = "yourdomain.com";
$audience = "yourdomain.com";
$access_token_exp = 9000; // 15 minutes
$refresh_token_exp = 604800; // 7 days
$issued_at = time();
