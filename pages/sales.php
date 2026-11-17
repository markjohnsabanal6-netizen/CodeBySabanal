
<?php
// Use Windows Pacific Time
date_default_timezone_set('America/Los_Angeles');

session_start();
require_once '../config/database.php';

// Keep MySQL session timezone consistent with PHP
$pacificOffset = (
    new DateTime('now', new DateTimeZone('America/Los_Angeles'))
)->format('P');

$conn->exec("SET time_zone = " . $conn->quote($pacificOffset));

// Protect page
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// USER INFORMATION - MATCHED WITH DASHBOARD
$rawName = $_SESSION['full_name'] ?? 'User';
$userName = htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8');
$userRole = $_SESSION['role'] ?? 'staff';
$isAdmin = ($userRole === 'admin');
$initial = strtoupper(substr($rawName, 0, 1));

$status = $_GET['status'] ?? '';
$sale_id = filter_input(INPUT_GET, 'sale_id', FILTER_VALIDATE_INT);

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function peso($amount) {
    return '₱' . number_format((float)$amount, 2);
}

// PROCESS CHECKOUT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {

    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    $payment_amount = filter_var(
        $_POST['payment_amount'] ?? 0,
        FILTER_VALIDATE_FLOAT
    );

    if (
        !is_array($product_ids) ||
        !is_array($quantities) ||
        empty($product_ids) ||
        empty($quantities)
    ) {
        header("Location: sales.php?status=empty");
        exit;
    }

    if ($payment_amount === false || $payment_amount < 0) {
        header("Location: sales.php?status=invalid");
        exit;
    }

    // Combine duplicate products
    $cart = [];

    foreach ($product_ids as $index => $product_id) {

        $product_id = filter_var($product_id, FILTER_VALIDATE_INT);

        $quantity = filter_var(
            $quantities[$index] ?? 0,
            FILTER_VALIDATE_INT
        );

        if (!$product_id || !$quantity || $quantity <= 0) {
            header("Location: sales.php?status=invalid");
            exit;
        }

        if (!isset($cart[$product_id])) {
            $cart[$product_id] = 0;
        }

        $cart[$product_id] += $quantity;
    }

    try {

        $conn->beginTransaction();

        $total_amount = 0;
        $sale_items = [];

        // Validate products and lock inventory rows
        foreach ($cart as $product_id => $quantity) {

            $productStmt = $conn->prepare("
                SELECT product_id, product_name, price
                FROM products
                WHERE product_id = ?
            ");

            $productStmt->execute([$product_id]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception("A selected product does not exist.");
            }

            $stockStmt = $conn->prepare("
                SELECT quantity
                FROM inventory
                WHERE product_id = ?
                FOR UPDATE
            ");

            $stockStmt->execute([$product_id]);
            $inventory = $stockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                throw new Exception(
                    "Inventory record missing for " .
                    $product['product_name']
                );
            }

            $current_stock = (int)$inventory['quantity'];

            if ($quantity > $current_stock) {
                throw new Exception(
                    "Not enough stock for " .
                    $product['product_name'] .
                    ". Available stock: " . $current_stock
                );
            }

            $price = (float)$product['price'];
            $subtotal = $price * $quantity;

            $total_amount += $subtotal;

            $sale_items[] = [
                'product_id' => $product_id,
                'quantity' => $quantity,
                'price' => $price,
                'subtotal' => $subtotal
            ];
        }

        if ($payment_amount < $total_amount) {
            throw new Exception("Insufficient payment amount.");
        }

        $change_amount = $payment_amount - $total_amount;

        // Save sale
        $saleStmt = $conn->prepare("
            INSERT INTO sales
            (user_id, total_amount, payment_amount, change_amount)
            VALUES (?, ?, ?, ?)
        ");

        $saleStmt->execute([
            $_SESSION['user_id'],
            $total_amount,
            $payment_amount,
            $change_amount
        ]);

        $new_sale_id = (int)$conn->lastInsertId();

        // Save sale details, deduct inventory, and record movements
        foreach ($sale_items as $item) {

            $detailStmt = $conn->prepare("
                INSERT INTO sale_details
                (sale_id, product_id, quantity, unit_price, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ");

            $detailStmt->execute([
                $new_sale_id,
                $item['product_id'],
                $item['quantity'],
                $item['price'],
                $item['subtotal']
            ]);

            $updateStock = $conn->prepare("
                UPDATE inventory
                SET quantity = quantity - ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE product_id = ?
            ");

            $updateStock->execute([
                $item['quantity'],
                $item['product_id']
            ]);

            $movementStmt = $conn->prepare("
                INSERT INTO stock_movements
                (product_id, user_id, movement_type, quantity, remarks)
                VALUES (?, ?, 'stock_out', ?, 'Sold Products')
            ");

            $movementStmt->execute([
                $item['product_id'],
                $_SESSION['user_id'],
                $item['quantity']
            ]);
        }

        $conn->commit();

        header("Location: sales.php?status=success&sale_id=" . $new_sale_id);
        exit;

    } catch (Throwable $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        error_log($e->getMessage());

        header(
            "Location: sales.php?status=error&message=" .
            urlencode($e->getMessage())
        );
        exit;
    }
}

// GET ALL CATEGORIES
$categoryStmt = $conn->query("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_name ASC
");

$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

// GET PRODUCTS WITH CATEGORY AND STOCK
$productStmt = $conn->query("
    SELECT
        p.product_id,
        p.product_name,
        p.price,
        p.category_id,
        c.category_name,
        COALESCE(i.quantity, 0) AS stock
    FROM products p
    LEFT JOIN categories c
        ON p.category_id = c.category_id
    LEFT JOIN inventory i
        ON p.product_id = i.product_id
    ORDER BY p.product_name ASC
");

$products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

// GET RECENT SALES
$recentStmt = $conn->query("
    SELECT
        s.sale_id,
        s.total_amount,
        s.payment_amount,
        s.change_amount,
        s.sale_date,
        u.full_name
    FROM sales s
    INNER JOIN users u
        ON s.user_id = u.user_id
    ORDER BY s.sale_id DESC
    LIMIT 10
");

$recent_sales = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Page summary
$totalProducts = count($products);
$availableProducts = 0;
$outOfStockProducts = 0;

foreach ($products as $product) {
    if ((int)$product['stock'] > 0) {
        $availableProducts++;
    } else {
        $outOfStockProducts++;
    }
}

$todayLabel = date('l, F d, Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Sales and POS | Bakery System</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --sidebar-width: 260px;

    --brown-dark: #54351f;
    --brown-deep: #422918;
    --brown: #805735;
    --brown-light: #a77b55;

    --cream: #f7f4ef;
    --cream-light: #fffdf9;

    --white: #ffffff;
    --text: #34271f;
    --muted: #88796e;
    --border: #eee5dc;

    --green: #27834c;
    --red: #c64b43;

    --shadow: 0 7px 22px rgba(69,43,25,.05);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    font-family: 'DM Sans', Arial, sans-serif;
    background: var(--cream);
    color: var(--text);
}

/* SIDEBAR */

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 1000;

    display: flex;
    flex-direction: column;

    width: var(--sidebar-width);
    padding: 28px 25px 20px;

    color: white;
    background: var(--brown-dark);

    overflow-y: auto;
}

.brand {
    display: flex;
    align-items: center;
    gap: 13px;

    padding: 10px 8px 0;
    margin-bottom: 65px;
}

.brand-icon {
    font-size: 39px;
    line-height: 1;
}

.brand-name {
    margin: 0;

    color: #fff;
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 23px;
    line-height: 1.15;
}

.brand-subtitle {
    margin: 5px 0 0;

    color: #e7cbb3;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .12em;
}

.menu-label {
    margin: 0 0 17px 17px;

    color: #d3b69c;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .14em;
}

.nav-menu {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 17px;

    min-height: 57px;
    padding: 12px 17px;

    color: #fff;
    text-decoration: none;

    border-radius: 14px;
    border-left: 4px solid transparent;

    font-size: 15px;
    font-weight: 600;

    transition: .2s ease;
}

.nav-link:hover {
    background: rgba(255,255,255,.10);
}

.nav-link.active {
    color: #fff;
    background: #805735;
    border-left-color: #f0c99d;
}

.nav-icon {
    display: inline-flex;
    justify-content: center;
    align-items: center;

    width: 22px;
    flex-shrink: 0;

    font-size: 19px;
}

.sidebar-bottom {
    margin-top: auto;
    padding-top: 22px;

    border-top: 1px solid rgba(255,255,255,.17);
}

.logout-link {
    display: flex;
    align-items: center;
    gap: 16px;

    min-height: 57px;
    padding: 12px 18px;

    color: #fff;
    background: #a13f30;

    border-radius: 13px;
    text-decoration: none;

    font-size: 15px;
    font-weight: 700;

    transition: .2s ease;
}

.logout-link:hover {
    background: #8e3428;
}

/* MAIN CONTENT */

.main-content {
    min-height: 100vh;
    margin-left: var(--sidebar-width);
    padding: 32px;
}

.content-container {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
}

/* TOPBAR */

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;

    margin-bottom: 28px;
}

.page-heading h1 {
    margin: 0;

    color: var(--brown-deep);
    font-family: 'Playfair Display', Georgia, serif;
    font-size: clamp(27px, 3vw, 35px);
}

.page-heading p {
    margin: 8px 0 0;
    color: var(--muted);
    font-size: 14px;
}

/* USER PROFILE - MATCHED WITH DASHBOARD */

.user-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar {
    display: flex;
    justify-content: center;
    align-items: center;

    width: 43px;
    height: 43px;

    border-radius: 50%;
    background: #e9d8c5;
    color: var(--brown);

    font-size: 17px;
    font-weight: 800;
}

.user-details strong {
    display: block;
    font-size: 13px;
}

.user-details span {
    display: inline-block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 11px;
}

.role-badge {
    padding: 5px 9px;
    border-radius: 20px;
    background: #eee3d5;
    color: var(--brown);
    font-size: 10px;
    font-weight: 700;
}

/* UPDATED COMPACT HERO */

.hero {
    position: relative;
    overflow: hidden;

    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;

    min-height: 135px;
    padding: 25px 32px;
    margin-bottom: 24px;

    border-radius: 18px;
    background: linear-gradient(115deg, #54351f, #805735);
    color: white;
}

.hero h2 {
    margin: 0 0 7px;

    font-family: 'Playfair Display', Georgia, serif;
    font-size: 25px;
}

.hero p {
    margin: 4px 0;
    color: #f4e8dc;
    font-size: 13px;
    line-height: 1.5;
}

.hero-icon {
    padding-right: 8px;
    font-size: 53px;
}

/* SUMMARY */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;

    margin-bottom: 27px;
}

.summary-card {
    padding: 22px;

    background: white;
    border: 1px solid var(--border);
    border-radius: 17px;

    box-shadow: var(--shadow);
}

.summary-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.summary-label {
    color: var(--muted);
    font-size: 13px;
    font-weight: 700;
}

.summary-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 42px;
    height: 42px;

    border-radius: 12px;
    background: #f4e9dc;
    font-size: 21px;
}

.summary-number {
    margin-top: 12px;
    color: var(--brown);
    font-size: 29px;
    font-weight: 800;
}

/* CONTENT CARDS */

.card {
    padding: 25px;
    margin-bottom: 25px;

    background: white;
    border: 1px solid var(--border);
    border-radius: 17px;

    box-shadow: var(--shadow);
}

.card-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;

    margin-bottom: 20px;
}

.card-heading h3 {
    margin: 0;
    color: var(--brown-deep);

    font-family: 'Playfair Display', Georgia, serif;
    font-size: 22px;
}

.card-subtitle {
    margin-top: 7px;
    color: var(--muted);
    font-size: 13px;
}

/* MESSAGES */

.message {
    padding: 15px 18px;
    margin-bottom: 20px;

    border-radius: 11px;
    font-size: 14px;
    line-height: 1.6;
}

.success {
    background: #eaf7ee;
    color: #216b3b;
    border: 1px solid #d3edda;
}

.error {
    background: #fff0ef;
    color: #a52f2f;
    border: 1px solid #f4d5d2;
}

.info {
    background: #e9f2f7;
    color: #315d74;
    border: 1px solid #d4e5ef;
}

.receipt-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    padding: 10px 15px;
    margin-top: 10px;

    border-radius: 8px;
    background: var(--green);
    color: white;
    text-decoration: none;

    font-size: 13px;
    font-weight: 700;
}

/* FORM */

.form-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr .7fr;
    gap: 15px;
    align-items: end;
}

.form-group label {
    display: block;
    margin-bottom: 8px;

    color: #493321;
    font-size: 13px;
    font-weight: 800;
}

input,
select {
    width: 100%;
    min-height: 48px;
    padding: 12px 14px;

    border: 1px solid #e8ded3;
    border-radius: 9px;

    background: #fff;
    color: var(--text);

    font-family: inherit;
    font-size: 14px;

    outline: none;
    transition: border .2s, box-shadow .2s;
}

input:focus,
select:focus {
    border-color: #a77a50;
    box-shadow: 0 0 0 3px rgba(167,122,80,.12);
}

input[readonly] {
    background: #f8f5f1;
    color: #766b62;
}

.primary-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 8px;

    min-height: 47px;
    padding: 12px 20px;

    border: none;
    border-radius: 9px;

    background: var(--brown);
    color: white;

    font-family: inherit;
    font-size: 14px;
    font-weight: 800;

    cursor: pointer;
    transition: .2s ease;
}

.primary-btn:hover {
    background: var(--brown-deep);
    transform: translateY(-1px);
}

.add-product-row {
    display: flex;
    justify-content: flex-end;
    margin-top: 17px;
}

/* TABLE */

.table-wrap {
    width: 100%;
    overflow-x: auto;
    border-radius: 12px;
}

table {
    width: 100%;
    min-width: 650px;
    border-collapse: collapse;
}

th {
    padding: 14px 13px;

    background: #f8f3ed;
    color: var(--brown);

    text-align: left;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    white-space: nowrap;
}

td {
    padding: 15px 13px;

    border-bottom: 1px solid #f0e9e1;
    color: #493f37;

    font-size: 13px;
}

tbody tr:hover {
    background: #fcfaf7;
}

.empty {
    padding: 28px;
    color: var(--muted);
    text-align: center;
}

.remove-btn {
    padding: 8px 12px;

    border: none;
    border-radius: 7px;

    background: #f9e6e3;
    color: #a33e32;

    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
}

.remove-btn:hover {
    background: #f1d1cc;
}

.product-name {
    color: var(--brown);
    font-weight: 800;
}

.stock-note {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 11px;
}

/* CART TOTAL */

.cart-bottom {
    display: grid;
    grid-template-columns: 1fr minmax(270px, 360px);
    gap: 25px;
    align-items: start;

    margin-top: 25px;
}

.cart-note {
    padding: 17px;

    border-radius: 11px;
    background: #faf6f1;
    color: #80664e;

    font-size: 13px;
    line-height: 1.6;
}

.total-panel {
    padding: 20px;

    border: 1px solid #eee3d8;
    border-radius: 13px;

    background: #fcfaf7;
}

.total-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;

    margin-bottom: 15px;
    color: #74665a;
    font-size: 14px;
}

.grand-total {
    color: var(--brown);
    font-size: 21px;
    font-weight: 800;
}

.change-value {
    color: var(--green);
    font-weight: 800;
}

.checkout-btn {
    width: 100%;
    min-height: 49px;
    margin-top: 16px;

    border: none;
    border-radius: 9px;

    background: var(--green);
    color: white;

    font-family: inherit;
    font-size: 14px;
    font-weight: 800;

    cursor: pointer;
    transition: .2s ease;
}

.checkout-btn:hover:not(:disabled) {
    background: #176b48;
}

.checkout-btn:disabled {
    background: #b7b7b7;
    cursor: not-allowed;
}

/* SALE ID */

.sale-id {
    display: inline-block;
    padding: 6px 9px;

    border-radius: 7px;
    background: #f4eadf;
    color: var(--brown);

    font-weight: 800;
}

/* RESPONSIVE */

@media (max-width: 1100px) {

    :root {
        --sidebar-width: 225px;
    }

    .sidebar {
        padding-left: 18px;
        padding-right: 18px;
    }

    .main-content {
        padding: 24px;
    }

    .form-grid {
        grid-template-columns: 1fr 1fr;
    }

    .form-grid .form-group:first-child {
        grid-column: 1 / -1;
    }
}

@media (max-width: 800px) {

    :root {
        --sidebar-width: 190px;
    }

    .sidebar {
        padding: 20px 12px;
    }

    .nav-link {
        font-size: 13px;
    }

    .main-content {
        padding: 20px 15px;
    }

    .page-heading h1 {
        font-size: 27px;
    }

    /* COMPACT HERO FOR TABLETS */

    .hero {
        padding: 22px;
        min-height: 125px;
    }

    .hero h2 {
        font-size: 22px;
    }

    .hero p {
        font-size: 12px;
    }

    .hero-icon {
        font-size: 40px;
    }

    .summary-grid {
        gap: 10px;
    }

    .summary-card {
        padding: 16px;
    }

    .summary-number {
        font-size: 25px;
    }

    .card {
        padding: 18px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-grid .form-group:first-child {
        grid-column: auto;
    }

    .add-product-row .primary-btn {
        width: 100%;
    }

    .cart-bottom {
        grid-template-columns: 1fr;
    }
}

/* MOBILE SIDEBAR */

@media (max-width: 620px) {

    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
        padding: 16px;

        overflow: visible;
    }

    .brand {
        margin-bottom: 20px;
    }

    .menu-label {
        margin-bottom: 10px;
    }

    .nav-menu {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 7px;
    }

    .nav-link {
        min-height: 43px;
        gap: 7px;
        padding: 8px;

        font-size: 11px;
        border-radius: 9px;
    }

    .nav-icon {
        width: 17px;
        font-size: 15px;
    }

    .sidebar-bottom {
        margin-top: 15px;
        padding-top: 12px;
    }

    .logout-link {
        min-height: 43px;
        padding: 9px 13px;
        font-size: 12px;
    }

    .main-content {
        margin-left: 0;
        padding: 17px 12px;
    }

    .topbar {
        align-items: flex-start;
        margin-bottom: 20px;
    }

    .page-heading h1 {
        font-size: 25px;
    }

    .page-heading p {
        font-size: 12px;
    }

    .user-info {
        gap: 9px;
    }

    .user-avatar {
        width: 38px;
        height: 38px;
        font-size: 15px;
    }

    .user-details strong {
        font-size: 12px;
    }

    .user-details span {
        font-size: 10px;
    }

    .role-badge {
        padding: 4px 7px;
        font-size: 9px;
    }

    .hero {
        padding: 20px;
        min-height: 130px;
    }

    .hero h2 {
        font-size: 21px;
    }

    .hero p {
        font-size: 12px;
    }

    .hero-icon {
        display: none;
    }

    .summary-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .summary-card {
        padding: 17px;
    }

    .card {
        padding: 14px;
    }

    .card-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .card-heading h3 {
        font-size: 20px;
    }

    .cart-bottom {
        grid-template-columns: 1fr;
        gap: 15px;
    }

    .total-panel {
        padding: 16px;
    }
}

@media (max-width: 360px) {

    .nav-menu {
        grid-template-columns: 1fr;
    }

    .main-content {
        padding: 15px 10px 25px;
    }

    .page-heading h1 {
        font-size: 23px;
    }

    .card {
        padding: 13px;
    }

    .hero h2 {
        font-size: 19px;
    }
}
</style>
</head>

<body>

<!-- SIDEBAR -->
<aside class="sidebar">

    <div class="brand">
        <span class="brand-icon">🥐</span>

        <div>
            <h2 class="brand-name">Bakery System</h2>
            <p class="brand-subtitle">SALES & INVENTORY</p>
        </div>
    </div>

    <p class="menu-label">MAIN MENU</p>

    <nav class="nav-menu">

        <a href="dashboard.php" class="nav-link">
            <span class="nav-icon">▦</span>
            Dashboard
        </a>

        <a href="products.php" class="nav-link">
            <span class="nav-icon">📦</span>
            Products
        </a>

        <?php if ($isAdmin): ?>
            <a href="categories.php" class="nav-link">
                <span class="nav-icon">▤</span>
                Categories
            </a>
        <?php endif; ?>

        <a href="inventory.php" class="nav-link">
            <span class="nav-icon">📦</span>
            Inventory
        </a>

        <a href="sales.php" class="nav-link active" aria-current="page">
            <span class="nav-icon">🛒</span>
            Sales / POS
        </a>

        <?php if ($isAdmin): ?>
            <a href="reports.php" class="nav-link">
                <span class="nav-icon">📊</span>
                Reports
            </a>

            <a href="user_management.php" class="nav-link">
                <span class="nav-icon">👥</span>
                User Management
            </a>
        <?php endif; ?>

    </nav>

    <div class="sidebar-bottom">
        <a href="../logout.php" class="logout-link">
            <span class="nav-icon">↪</span>
            Logout
        </a>
    </div>

</aside>

<!-- MAIN CONTENT -->
<main class="main-content">

<div class="content-container">

    <!-- TOPBAR -->
    <header class="topbar">

        <div class="page-heading">
            <h1>Sales / POS</h1>
            <p>Manage customer purchases and process bakery sales.</p>
        </div>

        <!-- DASHBOARD-MATCHED USER PROFILE -->
        <div class="user-info">

            <div class="user-avatar">
                <?= h($initial) ?>
            </div>

            <div class="user-details">
                <strong><?= $userName ?></strong>

                <span>
                    <span class="role-badge">
                        <?= $isAdmin ? 'ADMINISTRATOR' : 'STAFF' ?>
                    </span>
                </span>
            </div>

        </div>

    </header>

    <!-- HERO BANNER -->
    <section class="hero">

        <div>
            <h2>Sales and Point of Sale 🥐</h2>

            <p>
                Process customer orders, calculate payments,
                and automatically update your bakery inventory.
            </p>

            <p><?= h($todayLabel) ?></p>
        </div>

        <div class="hero-icon">🛒</div>

    </section>

    <!-- STATUS MESSAGES -->
    <?php if ($status === 'success' && $sale_id): ?>

        <div class="message success">
            <strong>Sale completed successfully!</strong><br>

            Your transaction has been recorded and inventory has been updated.

            <br>

            <a
                class="receipt-link"
                href="receipt.php?sale_id=<?= (int)$sale_id ?>"
                target="_blank"
                rel="noopener">
                🧾 Print Sales Receipt
            </a>
        </div>

    <?php elseif ($status === 'empty'): ?>

        <div class="message info">
            Please add products to your cart first.
        </div>

    <?php elseif ($status === 'invalid'): ?>

        <div class="message error">
            Invalid product or quantity. Please try again.
        </div>

    <?php elseif ($status === 'error'): ?>

        <div class="message error">
            <?= h($_GET['message'] ?? 'Unable to complete sale.') ?>
        </div>

    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <section class="summary-grid">

        <div class="summary-card">

            <div class="summary-top">
                <span class="summary-label">Total Products</span>
                <span class="summary-icon">📦</span>
            </div>

            <div class="summary-number">
                <?= number_format($totalProducts) ?>
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-top">
                <span class="summary-label">Available Products</span>
                <span class="summary-icon">✅</span>
            </div>

            <div class="summary-number">
                <?= number_format($availableProducts) ?>
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-top">
                <span class="summary-label">Out of Stock</span>
                <span class="summary-icon">⚠️</span>
            </div>

            <div class="summary-number">
                <?= number_format($outOfStockProducts) ?>
            </div>

        </div>

    </section>

    <!-- PRODUCT SELECTION -->
    <section class="card">

        <div class="card-heading">
            <div>
                <h3>🧁 Select Products</h3>

                <div class="card-subtitle">
                    Choose a category and select the products to add to the cart.
                </div>
            </div>
        </div>

        <div class="form-grid">

            <div class="form-group">
                <label for="categoryFilter">Product Category</label>

                <select id="categoryFilter">
                    <option value="all">All Categories</option>

                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int)$category['category_id'] ?>">
                            <?= h($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-group">
                <label for="productSelect">Select Product</label>

                <select id="productSelect">
                    <option value="">-- Select Product --</option>

                    <?php foreach ($products as $product): ?>

                        <?php $stock = (int)$product['stock']; ?>

                        <option
                            value="<?= (int)$product['product_id'] ?>"
                            data-name="<?= h($product['product_name']) ?>"
                            data-price="<?= h($product['price']) ?>"
                            data-stock="<?= $stock ?>"
                            data-category-id="<?= (int)($product['category_id'] ?? 0) ?>"
                            <?= $stock <= 0 ? 'disabled' : '' ?>
                        >
                            <?= h($product['product_name']) ?>
                            (<?= $stock > 0
                                ? 'Stock: ' . $stock
                                : 'Out of stock' ?>)
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-group">
                <label for="productPrice">Price</label>

                <input
                    type="text"
                    id="productPrice"
                    readonly
                    placeholder="₱0.00">
            </div>

        </div>

        <div class="form-grid" style="margin-top: 16px;">

            <div class="form-group">
                <label for="productQuantity">Quantity</label>

                <input
                    type="number"
                    id="productQuantity"
                    min="1"
                    step="1"
                    value="1">
            </div>

        </div>

        <div class="add-product-row">
            <button type="button" class="primary-btn" id="addProduct">
                + Add Product to Cart
            </button>
        </div>

    </section>

    <!-- SHOPPING CART -->
    <section class="card">

        <div class="card-heading">
            <div>
                <h3>🛒 Shopping Cart</h3>

                <div class="card-subtitle">
                    Review the selected products before completing the sale.
                </div>
            </div>
        </div>

        <form method="POST" id="checkoutForm">

            <div class="table-wrap">
                <table>

                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody id="cartBody">
                        <tr>
                            <td colspan="5" class="empty">
                                No products added yet.
                            </td>
                        </tr>
                    </tbody>

                </table>
            </div>

            <div id="hiddenInputs"></div>

            <div class="cart-bottom">

                <div class="cart-note">
                    <strong>💡 Sales Reminder</strong><br>

                    Please check the selected products and quantities.
                    The system will automatically deduct the sold quantities
                    from your inventory after a successful checkout.
                </div>

                <div class="total-panel">

                    <div class="total-line grand-total">
                        <span>Total Amount</span>
                        <span id="totalAmount">₱0.00</span>
                    </div>

                    <div class="form-group">
                        <label for="paymentAmount">Cash Received (₱)</label>

                        <input
                            type="number"
                            id="paymentAmount"
                            name="payment_amount"
                            min="0"
                            step="0.01"
                            placeholder="Enter payment amount"
                            required>
                    </div>

                    <div class="total-line" style="margin-top: 18px;">
                        <span>Change</span>

                        <strong class="change-value" id="changeAmount">
                            ₱0.00
                        </strong>
                    </div>

                    <button
                        type="submit"
                        name="checkout"
                        value="1"
                        class="checkout-btn"
                        id="checkoutButton"
                        disabled>
                        Complete Sale
                    </button>

                </div>

            </div>

        </form>

    </section>

    <!-- RECENT SALES -->
    <section class="card">

        <div class="card-heading">
            <div>
                <h3>📋 Recent Sales</h3>

                <div class="card-subtitle">
                    Latest 10 recorded transactions.
                </div>
            </div>
        </div>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Sale ID</th>
                        <th>Cashier</th>
                        <th>Total Amount</th>
                        <th>Date and Time</th>
                        <th>Receipt</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($recent_sales)): ?>

                        <tr>
                            <td colspan="5" class="empty">
                                No sales recorded yet.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($recent_sales as $sale): ?>

                            <tr>

                                <td>
                                    <span class="sale-id">
                                        #<?= (int)$sale['sale_id'] ?>
                                    </span>
                                </td>

                                <td><?= h($sale['full_name']) ?></td>

                                <td>
                                    <strong>
                                        <?= peso($sale['total_amount']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= h(date(
                                        'M d, Y h:i A',
                                        strtotime($sale['sale_date'])
                                    )) ?>
                                </td>

                                <td>
                                    <a
                                        href="receipt.php?sale_id=<?= (int)$sale['sale_id'] ?>"
                                        target="_blank"
                                        rel="noopener"
                                        style="color:#805735;font-weight:800;text-decoration:none;">
                                        View Receipt
                                    </a>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>
</main>

<script>
let cart = [];

const productSelect = document.getElementById('productSelect');
const categoryFilter = document.getElementById('categoryFilter');
const productPrice = document.getElementById('productPrice');
const productQuantity = document.getElementById('productQuantity');

const cartBody = document.getElementById('cartBody');
const hiddenInputs = document.getElementById('hiddenInputs');

const totalAmount = document.getElementById('totalAmount');
const paymentAmount = document.getElementById('paymentAmount');
const changeAmount = document.getElementById('changeAmount');

const checkoutButton = document.getElementById('checkoutButton');

function formatPeso(amount) {
    return '₱' + Number(amount).toFixed(2);
}

// Keep a clean copy of all product options
const allProductOptions = Array.from(productSelect.options)
    .slice(1)
    .map(option => option.cloneNode(true));

// FILTER PRODUCTS BY CATEGORY
function filterProductsByCategory() {

    const selectedCategory = categoryFilter.value;

    productSelect.replaceChildren(
        new Option('-- Select Product --', '')
    );

    allProductOptions.forEach(option => {

        const productCategory = option.dataset.categoryId || '0';

        if (
            selectedCategory === 'all' ||
            productCategory === selectedCategory
        ) {
            productSelect.add(option.cloneNode(true));
        }

    });

    productSelect.value = '';
    productPrice.value = '';
}

categoryFilter.addEventListener('change', filterProductsByCategory);

filterProductsByCategory();

// DISPLAY SELECTED PRODUCT PRICE
productSelect.addEventListener('change', function() {

    const option = this.options[this.selectedIndex];

    if (!this.value) {
        productPrice.value = '';
        return;
    }

    productPrice.value = formatPeso(
        Number(option.dataset.price)
    );

});

// ADD PRODUCT TO CART
document.getElementById('addProduct').addEventListener('click', function() {

    const option = productSelect.options[productSelect.selectedIndex];

    if (!productSelect.value) {
        alert('Please select a product.');
        return;
    }

    const productId = Number(productSelect.value);
    const name = option.dataset.name;
    const price = Number(option.dataset.price);
    const stock = Number(option.dataset.stock);
    const quantity = Number(productQuantity.value);

    if (stock <= 0) {
        alert('This product is currently out of stock.');
        return;
    }

    if (!Number.isInteger(quantity) || quantity <= 0) {
        alert('Please enter a valid quantity.');
        return;
    }

    const existing = cart.find(item => item.id === productId);
    const currentQuantity = existing ? existing.quantity : 0;

    if (currentQuantity + quantity > stock) {
        alert('Not enough stock. Available stock: ' + stock);
        return;
    }

    if (existing) {

        existing.quantity += quantity;

    } else {

        cart.push({
            id: productId,
            name: name,
            price: price,
            quantity: quantity,
            stock: stock
        });

    }

    productSelect.value = '';
    productPrice.value = '';
    productQuantity.value = 1;

    renderCart();

});

// REMOVE PRODUCT FROM CART
function removeProduct(index) {

    cart.splice(index, 1);

    renderCart();

}

// RENDER CART
function renderCart() {

    cartBody.replaceChildren();
    hiddenInputs.replaceChildren();

    let total = 0;

    if (cart.length === 0) {

        const row = document.createElement('tr');
        const cell = document.createElement('td');

        cell.colSpan = 5;
        cell.className = 'empty';
        cell.textContent = 'No products added yet.';

        row.appendChild(cell);
        cartBody.appendChild(row);

    }

    cart.forEach((item, index) => {

        const subtotal = item.price * item.quantity;

        total += subtotal;

        const row = document.createElement('tr');

        // Product name
        const nameCell = document.createElement('td');
        const productName = document.createElement('div');

        productName.className = 'product-name';
        productName.textContent = item.name;

        nameCell.appendChild(productName);
        row.appendChild(nameCell);

        // Price
        const priceCell = document.createElement('td');
        priceCell.textContent = formatPeso(item.price);
        row.appendChild(priceCell);

        // Quantity
        const quantityCell = document.createElement('td');
        quantityCell.textContent = item.quantity;
        row.appendChild(quantityCell);

        // Subtotal
        const subtotalCell = document.createElement('td');
        subtotalCell.textContent = formatPeso(subtotal);
        row.appendChild(subtotalCell);

        // Remove button
        const actionCell = document.createElement('td');
        const removeButton = document.createElement('button');

        removeButton.type = 'button';
        removeButton.className = 'remove-btn';
        removeButton.textContent = 'Remove';

        removeButton.addEventListener('click', function() {
            removeProduct(index);
        });

        actionCell.appendChild(removeButton);
        row.appendChild(actionCell);

        cartBody.appendChild(row);

        // Hidden inputs submitted to PHP
        const productInput = document.createElement('input');

        productInput.type = 'hidden';
        productInput.name = 'product_id[]';
        productInput.value = item.id;

        const quantityInput = document.createElement('input');

        quantityInput.type = 'hidden';
        quantityInput.name = 'quantity[]';
        quantityInput.value = item.quantity;

        hiddenInputs.appendChild(productInput);
        hiddenInputs.appendChild(quantityInput);

    });

    totalAmount.textContent = formatPeso(total);

    updateChange();

}

// CALCULATE CHANGE
function updateChange() {

    const total = cart.reduce(
        (sum, item) => sum + item.price * item.quantity,
        0
    );

    const payment = Number(paymentAmount.value) || 0;
    const change = Math.max(payment - total, 0);

    changeAmount.textContent = formatPeso(change);

    checkoutButton.disabled =
        cart.length === 0 ||
        payment < total ||
        payment <= 0;

}

paymentAmount.addEventListener('input', updateChange);

// CONFIRM CHECKOUT
document.getElementById('checkoutForm').addEventListener('submit', function(event) {

    if (cart.length === 0) {

        event.preventDefault();
        alert('Please add products to your cart.');
        return;

    }

    const total = cart.reduce(
        (sum, item) => sum + item.price * item.quantity,
        0
    );

    if (Number(paymentAmount.value) < total) {

        event.preventDefault();
        alert('Insufficient payment amount.');
        return;

    }

    if (!confirm('Are you sure you want to complete this sale?')) {
        event.preventDefault();
    }

});

// INITIALIZE
renderCart();
</script>

</body>
</html>
