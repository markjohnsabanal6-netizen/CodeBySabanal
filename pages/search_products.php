<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// CHECK LOGIN
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

// GET SEARCH INPUT
$search = trim($_GET['q'] ?? '');

if ($search === '') {
    echo json_encode([]);
    exit;
}

try {

    $stmt = $conn->prepare("
        SELECT
            p.product_id,
            p.product_name,
            c.category_name
        FROM products p
        LEFT JOIN categories c
            ON p.category_id = c.category_id
        WHERE p.product_name LIKE :product_prefix
           OR c.category_name LIKE :category_prefix
        ORDER BY p.product_name ASC
        LIMIT 8
    ");

    $prefix = $search . '%';

    $stmt->execute([
        'product_prefix' => $prefix,
        'category_prefix' => $prefix
    ]);

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($products);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([]);

}