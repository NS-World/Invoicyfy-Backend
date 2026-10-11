<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? '%' . trim($_GET['search']) . '%' : null;

try {
    if ($search) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM stores WHERE name LIKE ?");
        $countStmt->execute([$search]);

        $stmt = $pdo->prepare("SELECT store_id, name, address, phone, logo_url, created_at FROM stores WHERE name LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $search, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM stores");
        $stmt = $pdo->prepare("SELECT store_id, name, address, phone, logo_url, created_at FROM stores ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    }

    $total = $search ? $countStmt->fetch(PDO::FETCH_ASSOC)['total'] : $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $stmt->execute();
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "data" => $stores
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
