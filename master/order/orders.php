<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : null;

try {
    // Total count with search
    if ($search) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders o JOIN customers c ON o.customer_id = c.customer_id WHERE c.name LIKE ?");
        $countStmt->execute([$search]);
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM orders");
    }
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Fetch paginated list
    if ($search) {
        $stmt = $pdo->prepare("
            SELECT o.*, c.name AS customer_name, u.name AS user_name, s.name AS store_name
            FROM orders o
            JOIN customers c ON o.customer_id = c.customer_id
            JOIN users u ON o.user_id = u.user_id
            JOIN stores s ON o.store_id = s.store_id
            WHERE c.name LIKE ?
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $search, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT o.*, c.name AS customer_name, u.name AS user_name, s.name AS store_name
            FROM orders o
            JOIN customers c ON o.customer_id = c.customer_id
            JOIN users u ON o.user_id = u.user_id
            JOIN stores s ON o.store_id = s.store_id
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
    }

    echo json_encode([
        "status" => "success",
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
