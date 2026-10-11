<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
$search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%%';

$offset = ($page - 1) * $limit;

try {

    // ✅ COUNT QUERY
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM products p
        LEFT JOIN stores s ON p.store_id = s.store_id
        WHERE 
            p.name LIKE ?
            OR p.sku LIKE ?
            OR p.description LIKE ?
            OR p.price LIKE ?
            OR p.unit LIKE ?
            OR p.status LIKE ?
            OR s.name LIKE ?
            OR p.product_id LIKE ?
    ");

    $countStmt->execute([
        $search,
        $search,
        $search,
        $search,
        $search,
        $search,
        $search,
        $search
    ]);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];


    // ✅ MAIN QUERY
    $stmt = $pdo->prepare("
        SELECT p.*, s.name AS store_name
        FROM products p
        LEFT JOIN stores s ON p.store_id = s.store_id
        WHERE 
            p.name LIKE ?
            OR p.sku LIKE ?
            OR p.description LIKE ?
            OR p.price LIKE ?
            OR p.unit LIKE ?
            OR p.status LIKE ?
            OR s.name LIKE ?
            OR p.product_id LIKE ?
        ORDER BY p.product_id DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bindValue(1, $search);
    $stmt->bindValue(2, $search);
    $stmt->bindValue(3, $search);
    $stmt->bindValue(4, $search);
    $stmt->bindValue(5, $search);
    $stmt->bindValue(6, $search);
    $stmt->bindValue(7, $search);
    $stmt->bindValue(8, $search);
    $stmt->bindValue(9, $limit, PDO::PARAM_INT);
    $stmt->bindValue(10, $offset, PDO::PARAM_INT);


    $stmt->execute();

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "page" => $page,
        "limit" => $limit,
        "total" => $total,
        "data" => $products
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
