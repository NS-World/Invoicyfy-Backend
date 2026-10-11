<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    if (!empty($search)) {
        $searchParam = "%$search%";
        $stmt = $pdo->prepare("SELECT customer_id, name, phone, email, address, created_at 
                               FROM customers 
                               WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?
                               ORDER BY created_at DESC 
                               LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $searchParam);
        $stmt->bindValue(2, $searchParam);
        $stmt->bindValue(3, $searchParam);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->bindValue(5, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count filtered records
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers 
                                    WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?");
        $countStmt->execute([$searchParam, $searchParam, $searchParam]);
        $total = $countStmt->fetchColumn();
    } else {
        // Without search
        $stmt = $pdo->prepare("SELECT customer_id, name, phone, email, address, created_at 
                               FROM customers 
                               ORDER BY created_at DESC 
                               LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count total
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM customers");
        $total = $totalStmt->fetchColumn();
    }

    echo json_encode([
        "status" => "success",
        "total" => $total,
        "page" => $page,
        "limit" => $limit,
        "data" => $customers
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
