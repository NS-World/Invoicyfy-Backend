<?php
/**
 * Offer picture: POST { image: "data:image/jpeg;base64,..." } (the screen resizes it first).
 * Saved as a re-encoded JPEG under api/uploads/offers/ so WhatsApp and email can download it.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/channels.php';
allow_methods('POST');
$user = require_auth();
$data = (string) (input()['image'] ?? '');
if (!preg_match('#^data:image/(png|jpe?g|webp);base64,([A-Za-z0-9+/=\s]+)$#', $data, $m)) {
    fail('Choose a JPG, PNG or WebP picture');
}
$bin = base64_decode($m[2], true);
if ($bin === false || strlen($bin) > 3 * 1024 * 1024) {
    fail('Picture is too large (max 3 MB)');
}
$info = @getimagesizefromstring($bin);
if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
    fail('This file is not a valid picture');
}
$dir = __DIR__ . '/../../uploads/offers';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fail('Could not save the picture (uploads folder is not writable)', 500);
}
$name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.jpg';
// Re-encode: strips anything hidden in the file and keeps it small for WhatsApp (max 5 MB).
if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($bin))) {
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, 1600 / max($w, $h));
    $out = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    imagejpeg($out, "$dir/$name", 85);
} elseif ($info[2] === IMAGETYPE_JPEG) {
    file_put_contents("$dir/$name", $bin);
} else {
    fail('Please upload a JPG picture');
}
json_out(['status' => 'success', 'url' => api_public_base() . "/uploads/offers/$name"]);
