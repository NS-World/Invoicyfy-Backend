<?php
require_once __DIR__ . '/../../config/cors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit; // stop here for preflight
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../auth/validate.php';

header('Content-Type: application/json');

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    if (!empty($search)) {
        $searchParam = "%$search%";

        $stmt = $pdo->prepare("
            SELECT 
                u.user_id,
                u.name,
                u.email,
                u.phone,
                u.city,
                r.role_name,
                s.name AS store_name,
                u.created_at
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN stores s ON u.store_id = s.store_id
            WHERE u.name LIKE ? 
                OR u.email LIKE ? 
                OR u.phone LIKE ?
                OR u.city LIKE ?
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $stmt->bindValue(1, $searchParam);
        $stmt->bindValue(2, $searchParam);
        $stmt->bindValue(3, $searchParam);
        $stmt->bindValue(4, $searchParam);
        $stmt->bindValue(5, $limit, PDO::PARAM_INT);
        $stmt->bindValue(6, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count results
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM users 
                WHERE name LIKE ? 
                OR email LIKE ? 
                OR phone LIKE ?
                OR city LIKE ?
        ");
        $countStmt->execute([
            $searchParam,
            $searchParam,
            $searchParam,
            $searchParam
            ]);

        $total = $countStmt->fetchColumn();

    } else {

        $stmt = $pdo->prepare("
            SELECT 
                u.user_id,
                u.name,
                u.email,
                u.phone,
                u.city, 
                r.role_name,
                s.name AS store_name,
                u.created_at
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN stores s ON u.store_id = s.store_id
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalStmt = $pdo->query("SELECT COUNT(*) FROM users");
        $total = $totalStmt->fetchColumn();
    }

    echo json_encode([
        "status" => "success",
        "total" => $total,
        "page" => $page,
        "limit" => $limit,
        "data" => $users
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
