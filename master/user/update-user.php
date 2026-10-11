<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php'; // JWT validation

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

// Sanitize inputs
$user_id  = $data['user_id'] ?? null;
$name     = trim($data['name'] ?? '');
$email    = trim($data['email'] ?? '');
$phone    = trim($data['phone'] ?? '');
$city     = trim($data['city'] ?? '');
$store_id = $data['store_id'] ?? null;
$role_id  = $data['role_id'] ?? null;

// Validation
if (!$user_id || !$name || !$email || !$phone || !$city || !$store_id || !$role_id) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "All fields are required"
    ]);
    exit;
}

try {
    // Check if user exists
    $check = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $check->execute([$user_id]);
    $user = $check->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "User not found"]);
        exit;
    }

    // Prevent update if data is identical
    if (
        $user['name'] === $name &&
        $user['email'] === $email &&
        $user['phone'] === $phone &&
        $user['city'] === $city &&
        (int)$user['store_id'] === (int)$store_id &&
        (int)$user['role_id'] === (int)$role_id
    ) {
        echo json_encode([
            "status" => "warning",
            "message" => "No changes detected"
        ]);
        exit;
    }

    // Prevent duplicate email or phone from other users
    $duplicateCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM users 
        WHERE (email = ? OR phone = ?) 
        AND user_id != ?
    ");
    $duplicateCheck->execute([$email, $phone, $user_id]);

    if ($duplicateCheck->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode([
            "status" => "error",
            "message" => "Email or phone already exists for another user"
        ]);
        exit;
    }

    // Perform update
    $update = $pdo->prepare("
        UPDATE users 
        SET name = ?, email = ?, phone = ?, city = ?, store_id = ?, role_id = ?
        WHERE user_id = ?
    ");

    $update->execute([
        $name,
        $email,
        $phone,
        $city,
        $store_id,
        $role_id,
        $user_id
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "User updated successfully"
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
