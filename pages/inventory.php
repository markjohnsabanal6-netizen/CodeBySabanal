
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

// Initialize inventory records for products without inventory.
try {
    $conn->exec("
        INSERT INTO inventory (product_id, quantity, minimum_stock)
        SELECT p.product_id, 0, 5
        FROM products p
        LEFT JOIN inventory i ON p.product_id = i.product_id
        WHERE i.product_id IS NULL
    ");
} catch (PDOException $e) {
    die("Unable to initialize inventory: " . $e->getMessage());
}

// Handle form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

    try {
        if (!$product_id || $product_id < 1) {
            throw new Exception("Invalid product selected.");
        }

        // STOCK IN AND STOCK OUT
        if ($action === 'stock_in' || $action === 'stock_out') {
            $amount = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

            if (!$amount || $amount < 1) {
                throw new Exception("Please enter a valid quantity.");
            }

            $remark_choice = trim($_POST['remarks_choice'] ?? '');
            $custom_remarks = trim($_POST['custom_remarks'] ?? '');

            $allowed_remarks = [
                'stock_in' => [
                    'New Production',
                    'Supplier Delivery',
                    'Restocking',
                    'Customer Return',
                    'Stock Adjustment'
                ],
                'stock_out' => [
                    'Sold Products',
                    'Damaged Products',
                    'Expired Products',
                    'Production Use',
                    'Returned to Supplier',
                    'Stock Adjustment'
                ]
            ];

            if ($remark_choice === 'Custom Remark') {
                if ($custom_remarks === '') {
                    throw new Exception("Please enter your custom remark.");
                }
                $remarks = $custom_remarks;
            } else {
                if (!in_array($remark_choice, $allowed_remarks[$action], true)) {
                    throw new Exception("Please select a valid remark.");
                }
                $remarks = $remark_choice;
            }

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT quantity
                FROM inventory
                WHERE product_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$product_id]);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                throw new Exception("Inventory record not found.");
            }

            $current_quantity = (int) $inventory['quantity'];

            if ($action === 'stock_out' && $amount > $current_quantity) {
                throw new Exception("Not enough stock available.");
            }

            if ($action === 'stock_in') {
                $new_quantity = $current_quantity + $amount;
                $movement_type = 'stock_in';
            } else {
                $new_quantity = $current_quantity - $amount;
                $movement_type = 'stock_out';
            }

            // Update current stock.
            $update = $conn->prepare("
                UPDATE inventory
                SET quantity = ?
                WHERE product_id = ?
            ");
            $update->execute([$new_quantity, $product_id]);

            // Record stock movement.
            $movement = $conn->prepare("
                INSERT INTO stock_movements
                    (product_id, user_id, movement_type, quantity, remarks)
                VALUES (?, ?, ?, ?, ?)
            ");
            $movement->execute([
                $product_id,
                $_SESSION['user_id'],
                $movement_type,
                $amount,
                $remarks
            ]);

            $conn->commit();

            $status = $action === 'stock_in'
                ? 'stock_in_success'
                : 'stock_out_success';

            header("Location: inventory.php?status=" . $status);
            exit;
        }

        // UPDATE MINIMUM STOCK
        if ($action === 'set_minimum') {
            $minimum_stock = filter_input(
                INPUT_POST,
                'minimum_stock',
                FILTER_VALIDATE_INT
            );

            if (
                $minimum_stock === false ||
                $minimum_stock === null ||
                $minimum_stock < 0
            ) {
                throw new Exception("Please enter a valid minimum stock level.");
            }

            $stmt = $conn->prepare("
                UPDATE inventory
                SET minimum_stock = ?
                WHERE product_id = ?
            ");
            $stmt->execute([$minimum_stock, $product_id]);

            header("Location: inventory.php?status=minimum_updated");
            exit;
        }

        throw new Exception("Invalid action.");

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        header("Location: inventory.php?error=" . urlencode("A database error occurred."));
        exit;

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        header("Location: inventory.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}

// GET INVENTORY
$stmt = $conn->query("
    SELECT
        p.product_id,
        p.product_name,
        p.category_id,
        c.category_name,
        i.quantity,
        i.minimum_stock,
        i.updated_at
    FROM products p
    LEFT JOIN categories c
        ON p.category_id = c.category_id
    INNER JOIN inventory i
        ON p.product_id = i.product_id
    ORDER BY p.product_name ASC
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// GET ALL CATEGORIES
$categoryStmt = $conn->query("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_name ASC
");
$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Count products in each category.
$category_counts = [];

foreach ($products as $product) {
    $category_id = $product['category_id'] === null
        ? 'uncategorized'
        : (string) $product['category_id'];

    if (!isset($category_counts[$category_id])) {
        $category_counts[$category_id] = 0;
    }

    $category_counts[$category_id]++;
}

// GET RECENT STOCK MOVEMENTS
$stmt = $conn->query("
    SELECT
        sm.movement_type,
        sm.quantity,
        sm.remarks,
        sm.movement_date,
        p.product_name,
        u.full_name
    FROM stock_movements sm
    INNER JOIN products p
        ON sm.product_id = p.product_id
    INNER JOIN users u
        ON sm.user_id = u.user_id
    ORDER BY sm.movement_date DESC
    LIMIT 20
");
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// INVENTORY SUMMARY
$total_products = count($products);
$low_stock_count = 0;
$total_stock = 0;

foreach ($products as $product) {
    $total_stock += (int) $product['quantity'];

    if ((int) $product['quantity'] <= (int) $product['minimum_stock']) {
        $low_stock_count++;
    }
}

// Secure output function.
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

    <title>Inventory Management</title>

    <style>
        :root {
            --brown-950: #382316;
            --brown-900: #4b2e1d;
            --brown-800: #60402b;
            --brown-700: #795238;
            --brown-600: #946b4d;
            --cream-50: #fffdf9;
            --cream-100: #faf5ee;
            --cream-200: #f1e7dc;
            --text: #34271f;
            --muted: #88796e;
            --line: #eee5dc;
            --green: #27834c;
            --red: #c64b43;
            --shadow: 0 12px 32px rgba(69, 43, 25, .07);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px;
            font-family: 'DM Sans', 'Segoe UI', Arial, sans-serif;
            background: radial-gradient(circle at top right, #f3e7d9 0, transparent 32%), #f7f4ef;
            color: var(--text);
        }

        .container {
            max-width: 1500px;
            margin: 0 auto;
        }

        /* PAGE HEADER */
        .page-header {
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 25px;
            padding: 30px 34px;
            color: #fffaf4;
            background: linear-gradient(120deg, #382316 0%, #60402b 58%, #946b4d 100%);
            border-radius: 22px;
            box-shadow: 0 16px 35px rgba(69, 43, 25, .16);
        }

        .page-header::after {
            content: '🥐';
            position: absolute;
            right: 34px;
            top: 50%;
            transform: translateY(-50%) rotate(-12deg);
            font-size: 82px;
            opacity: .15;
            pointer-events: none;
        }

        .header-copy {
            position: relative;
            z-index: 1;
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 9px;
            color: #e8cdb5;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .18em;
            text-transform: uppercase;
        }

        .page-header h1 {
            color: #fffaf4;
        }

        .page-header .subtitle {
            margin: 9px 0 0;
            color: #eaded3;
        }

        .header-chip {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            color: #fff7ee;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* BACK BUTTON */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 20px;
            padding: 9px 14px;
            color: var(--brown-800);
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 10px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            transition: .2s ease;
        }

        .back-link:hover {
            background: var(--cream-100);
            transform: translateX(-2px);
        }

        h1 {
            margin: 0;
            color: var(--brown-950);
            font-family: 'Playfair Display', Georgia, serif;
            font-size: clamp(29px, 4vw, 38px);
            letter-spacing: -.7px;
        }

        .subtitle {
            margin: 8px 0 27px;
            color: var(--muted);
            font-size: 14px;
        }

        h2 {
            margin: 0;
            color: var(--brown-950);
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 23px;
        }

        /* SUMMARY CARDS */
        .summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            min-height: 145px;
            padding: 23px 24px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 17px;
            box-shadow: var(--shadow);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 34px rgba(69,43,25,.11);
        }

        .summary-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .summary-icon {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 13px;
            background: #f4e9df;
            font-size: 20px;
        }

        .summary-card::after {
            content: '';
            position: absolute;
            width: 110px;
            height: 110px;
            right: -28px;
            bottom: -42px;
            border-radius: 50%;
            background: var(--cream-100);
        }

        .summary-card h3 {
            position: relative;
            z-index: 1;
            margin: 0 0 13px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .summary-card p {
            position: relative;
            z-index: 1;
            margin: 0;
            color: var(--brown-900);
            font-size: 34px;
            line-height: 1;
            font-weight: 800;
        }

        .summary-card:nth-child(2) p {
            color: var(--green);
        }

        .summary-card:nth-child(3) p {
            color: var(--red);
        }

        /* PANELS */
        .panel {
            margin-bottom: 25px;
            padding: 26px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
        }

        .panel-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 5px;
        }

        .panel-kicker {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .panel-tag {
            padding: 7px 11px;
            color: var(--brown-700);
            background: var(--cream-100);
            border: 1px solid var(--cream-200);
            border-radius: 30px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        /* NOTIFICATIONS */
        .message {
            padding: 14px 17px;
            margin-bottom: 18px;
            border-radius: 11px;
            font-size: 14px;
            font-weight: 700;
        }

        .success {
            color: #216b3b;
            background: #eaf7ee;
            border: 1px solid #d3edda;
        }

        .error {
            color: #a52f2f;
            background: #fff0ef;
            border: 1px solid #f4d5d2;
        }

        /* CATEGORY FILTER */
        .category-filter {
            margin: 21px 0 22px;
        }

        .category-filter-title {
            margin: 0 0 13px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .category-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 11px;
        }

        .category-button {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 155px;
            padding: 11px 14px;
            color: var(--brown-800);
            text-align: left;
            background: var(--cream-50);
            border: 1px solid var(--cream-200);
            border-radius: 13px;
            box-shadow: 0 3px 9px rgba(91, 57, 36, .04);
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
        }

        .category-button:hover {
            transform: translateY(-2px);
            border-color: #c9a98f;
            box-shadow: 0 7px 15px rgba(91, 57, 36, .1);
        }

        .category-button.active {
            color: #fff;
            border-color: var(--brown-900);
            background: linear-gradient(135deg, #80583b, #4b2e1d);
            box-shadow: 0 6px 15px rgba(75, 46, 29, .2);
        }

        .category-icon {
            display: grid;
            place-items: center;
            width: 39px;
            height: 39px;
            flex: 0 0 39px;
            border-radius: 11px;
            background: #f1e5d9;
            font-size: 20px;
        }

        .category-button.active .category-icon {
            background: rgba(255,255,255,.16);
        }

        .category-button-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .category-name {
            font-size: 13px;
            font-weight: 800;
        }

        .category-count {
            color: #9a8879;
            font-size: 11px;
        }

        .category-button.active .category-count {
            color: #f0dfd1;
        }

        .filter-result {
            margin: 13px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        /* TABLES */
        .table-wrapper {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 13px;
        }

        table {
            width: 100%;
            min-width: 930px;
            border-collapse: separate;
            border-spacing: 0;
        }

        th, td {
            padding: 15px 13px;
            border-bottom: 1px solid #f0eae4;
            text-align: left;
            vertical-align: middle;
            font-size: 13px;
        }

        th {
            color: var(--brown-800);
            background: #f8f3ed;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        tbody tr.inventory-row {
            transition: background .15s ease;
        }

        tbody tr.inventory-row:hover {
            background: #fffcf8;
        }

        td:first-child {
            color: var(--brown-950);
            font-weight: 700;
        }

        .low-stock, .normal-stock {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .low-stock {
            color: #b33c35;
            background: #fff0ee;
        }

        .normal-stock {
            color: #237644;
            background: #eaf7ee;
        }

        .movement-in {
            color: #237644;
            background: #eaf7ee;
        }

        .movement-out {
            color: #b33c35;
            background: #fff0ee;
        }

        .movement-badge {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        /* STOCK ACTION FORMS */
        .action-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 7px;
            margin: 0 0 9px;
            padding: 8px;
            background: #fcfaf7;
            border: 1px solid #f1e9e1;
            border-radius: 10px;
        }

        .action-form:last-child {
            margin-bottom: 0;
        }

        input, select, button {
            font: inherit;
        }

        input, select {
            min-height: 35px;
            padding: 8px 10px;
            color: var(--text);
            background: #fff;
            border: 1px solid #e6ddd4;
            border-radius: 8px;
            font-size: 12px;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }

        input:focus, select:focus {
            border-color: #a77c5b;
            box-shadow: 0 0 0 3px rgba(167,124,91,.13);
        }

        input[type='number'] {
            width: 90px;
        }

        input[type='text'] {
            width: 150px;
        }

        select {
            width: 165px;
        }

        button {
            cursor: pointer;
        }

        .action-form button {
            min-height: 35px;
            padding: 8px 12px;
            border: 0;
            border-radius: 8px;
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
            transition: transform .15s, filter .15s;
        }

        .action-form button:hover {
            transform: translateY(-1px);
            filter: brightness(.95);
        }

        .stock-in-btn {
            background: #27834c;
        }

        .stock-out-btn {
            background: #c64b43;
        }

        .minimum-btn {
            background: var(--brown-700);
        }

        .custom-remark {
            display: none;
        }

        .empty {
            padding: 28px;
            color: var(--muted);
            text-align: center;
        }

        .no-filter-results {
            display: none;
        }

        /* RESPONSIVE DESIGN */
        @media (max-width: 850px) {
            body {
                padding: 20px;
            }

            .page-header {
                padding: 25px;
            }

            .panel {
                padding: 19px;
            }

            .summary {
                gap: 12px;
            }

            .summary-card {
                padding: 19px;
            }

            .category-button {
                min-width: 140px;
            }
        }

        @media (max-width: 620px) {
            body {
                padding: 13px;
            }

            .page-header {
                align-items: flex-start;
                flex-direction: column;
                padding: 23px 20px;
                border-radius: 17px;
            }

            .page-header::after {
                right: 15px;
                top: 24px;
                transform: none;
                font-size: 54px;
            }

            .header-chip {
                font-size: 11px;
            }

            .panel-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .summary {
                grid-template-columns: 1fr;
                gap: 11px;
            }

            .summary-card {
                min-height: 105px;
                padding: 18px;
            }

            .summary-card p {
                font-size: 29px;
            }

            .panel {
                padding: 15px;
                border-radius: 14px;
            }

            h2 {
                font-size: 21px;
            }

            .category-buttons {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .category-button {
                min-width: 0;
                padding: 9px;
                gap: 8px;
            }

            .category-icon {
                width: 33px;
                height: 33px;
                flex-basis: 33px;
                font-size: 17px;
            }

            .category-name {
                font-size: 11px;
            }

            .category-count {
                font-size: 10px;
            }
        }

        @media (max-width: 360px) {
            .category-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <a href="dashboard.php" class="back-link">
        <span aria-hidden="true">←</span> Back to Dashboard
    </a>

    <header class="page-header">
        <div class="header-copy">
            <span class="eyebrow">Bakery Sales & Stock System</span>
            <h1>Inventory Management</h1>
            <p class="subtitle">Keep track of your products, stock levels, and inventory activity.</p>
        </div>

        <div class="header-chip">
            <span aria-hidden="true">✦</span> Stock Overview
        </div>
    </header>

    <?php
    $messages = [
        'stock_in_success' => 'Stock successfully added!',
        'stock_out_success' => 'Stock successfully deducted!',
        'minimum_updated' => 'Minimum stock level updated!'
    ];

    $status = $_GET['status'] ?? '';

    if (isset($messages[$status])):
    ?>
        <div class="message success">
            <?= e($messages[$status]) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="message error">
            <?= e($_GET['error']) ?>
        </div>
    <?php endif; ?>


    <!-- INVENTORY SUMMARY -->
    <div class="summary">

        <div class="summary-card">
            <div class="summary-top">
                <h3>Total Products</h3>
                <span class="summary-icon">🧁</span>
            </div>
            <p><?= $total_products ?></p>
        </div>

        <div class="summary-card">
            <div class="summary-top">
                <h3>Total Stock Quantity</h3>
                <span class="summary-icon">📦</span>
            </div>
            <p><?= $total_stock ?></p>
        </div>

        <div class="summary-card">
            <div class="summary-top">
                <h3>Low Stock Products</h3>
                <span class="summary-icon">⚠️</span>
            </div>
            <p><?= $low_stock_count ?></p>
        </div>

    </div>


    <!-- PRODUCT INVENTORY -->
    <div class="panel">

        <div class="panel-heading">
            <div>
                <h2>Product Inventory</h2>
                <p class="panel-kicker">Select a category, then update the stock for each product.</p>
            </div>

            <span class="panel-tag">
                <?= $total_products ?> items tracked
            </span>
        </div>

        <!-- DYNAMIC CATEGORY BUTTONS -->
        <div class="category-filter">

            <p class="category-filter-title">Choose a product category</p>

            <div class="category-buttons" id="categoryButtons">

                <button type="button"
                        class="category-button active"
                        data-category="all"
                        aria-pressed="true">

                    <span class="category-icon">🛍️</span>

                    <span class="category-button-text">
                        <span class="category-name">All Products</span>
                        <span class="category-count">
                            <?= $total_products ?> products
                        </span>
                    </span>
                </button>

                <?php foreach ($categories as $category): ?>

                    <?php
                    $category_id = (string) $category['category_id'];
                    $category_count = $category_counts[$category_id] ?? 0;
                    $category_name_lower = strtolower($category['category_name']);

                    // Choose a suitable icon based on category name.
                    if (strpos($category_name_lower, 'cake') !== false) {
                        $category_icon = '🎂';
                    } elseif (strpos($category_name_lower, 'bread') !== false) {
                        $category_icon = '🍞';
                    } elseif (
                        strpos($category_name_lower, 'pastry') !== false ||
                        strpos($category_name_lower, 'croissant') !== false
                    ) {
                        $category_icon = '🥐';
                    } elseif (
                        strpos($category_name_lower, 'cookie') !== false ||
                        strpos($category_name_lower, 'biscuit') !== false
                    ) {
                        $category_icon = '🍪';
                    } elseif (strpos($category_name_lower, 'drink') !== false) {
                        $category_icon = '🥤';
                    } else {
                        $category_icon = '🧁';
                    }
                    ?>

                    <button type="button"
                            class="category-button"
                            data-category="<?= (int) $category['category_id'] ?>"
                            aria-pressed="false">

                        <span class="category-icon"><?= $category_icon ?></span>

                        <span class="category-button-text">
                            <span class="category-name">
                                <?= e($category['category_name']) ?>
                            </span>

                            <span class="category-count">
                                <?= $category_count ?>
                                <?= $category_count === 1 ? 'product' : 'products' ?>
                            </span>
                        </span>
                    </button>

                <?php endforeach; ?>

                <?php if (isset($category_counts['uncategorized'])): ?>

                    <button type="button"
                            class="category-button"
                            data-category="uncategorized"
                            aria-pressed="false">

                        <span class="category-icon">📦</span>

                        <span class="category-button-text">
                            <span class="category-name">Uncategorized</span>

                            <span class="category-count">
                                <?= $category_counts['uncategorized'] ?>
                                <?= $category_counts['uncategorized'] === 1 ? 'product' : 'products' ?>
                            </span>
                        </span>
                    </button>

                <?php endif; ?>

            </div>

            <p class="filter-result" id="filterResult">
                Showing all <?= $total_products ?> products.
            </p>

        </div>


        <!-- INVENTORY TABLE -->
        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Minimum Stock</th>
                        <th>Status</th>
                        <th>Manage Stock</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($products)): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No products found. Add products first.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($products as $product): ?>

                        <?php
                        $is_low =
                            (int) $product['quantity'] <=
                            (int) $product['minimum_stock'];
                        ?>

                        <tr class="inventory-row"
                            data-category="<?= $product['category_id'] === null
                                ? 'uncategorized'
                                : (int) $product['category_id'] ?>">

                            <td>
                                <?= e($product['product_name']) ?>
                            </td>

                            <td>
                                <?= e($product['category_name'] ?? 'Uncategorized') ?>
                            </td>

                            <td>
                                <?= (int) $product['quantity'] ?>
                            </td>

                            <td>
                                <?= (int) $product['minimum_stock'] ?>
                            </td>

                            <td>
                                <?php if ($is_low): ?>
                                    <span class="low-stock">Low Stock</span>
                                <?php else: ?>
                                    <span class="normal-stock">In Stock</span>
                                <?php endif; ?>
                            </td>

                            <td>

                                <!-- STOCK IN -->
                                <form method="POST" class="action-form">

                                    <input type="hidden"
                                           name="product_id"
                                           value="<?= (int) $product['product_id'] ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="stock_in">

                                    <input type="number"
                                           name="quantity"
                                           min="1"
                                           placeholder="Quantity"
                                           required>

                                    <select name="remarks_choice"
                                            class="remark-select"
                                            required>

                                        <option value="">Select Remark</option>
                                        <option value="New Production">New Production</option>
                                        <option value="Supplier Delivery">Supplier Delivery</option>
                                        <option value="Restocking">Restocking</option>
                                        <option value="Customer Return">Customer Return</option>
                                        <option value="Stock Adjustment">Stock Adjustment</option>
                                        <option value="Custom Remark">Custom Remark</option>

                                    </select>

                                    <input type="text"
                                           name="custom_remarks"
                                           class="custom-remark"
                                           placeholder="Enter your remark...">

                                    <button type="submit" class="stock-in-btn">
                                        Stock In
                                    </button>

                                </form>


                                <!-- STOCK OUT -->
                                <form method="POST" class="action-form">

                                    <input type="hidden"
                                           name="product_id"
                                           value="<?= (int) $product['product_id'] ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="stock_out">

                                    <input type="number"
                                           name="quantity"
                                           min="1"
                                           max="<?= (int) $product['quantity'] ?>"
                                           placeholder="Quantity"
                                           required>

                                    <select name="remarks_choice"
                                            class="remark-select"
                                            required>

                                        <option value="">Select Remark</option>
                                        <option value="Sold Products">Sold Products</option>
                                        <option value="Damaged Products">Damaged Products</option>
                                        <option value="Expired Products">Expired Products</option>
                                        <option value="Production Use">Production Use</option>
                                        <option value="Returned to Supplier">Returned to Supplier</option>
                                        <option value="Stock Adjustment">Stock Adjustment</option>
                                        <option value="Custom Remark">Custom Remark</option>

                                    </select>

                                    <input type="text"
                                           name="custom_remarks"
                                           class="custom-remark"
                                           placeholder="Enter your remark...">

                                    <button type="submit" class="stock-out-btn">
                                        Stock Out
                                    </button>

                                </form>


                                <!-- SET MINIMUM STOCK -->
                                <form method="POST" class="action-form">

                                    <input type="hidden"
                                           name="product_id"
                                           value="<?= (int) $product['product_id'] ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="set_minimum">

                                    <input type="number"
                                           name="minimum_stock"
                                           min="0"
                                           value="<?= (int) $product['minimum_stock'] ?>"
                                           required>

                                    <button type="submit" class="minimum-btn">
                                        Set Minimum
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                    <tr class="no-filter-results">
                        <td colspan="6" class="empty">
                            No products found in this category.
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </div>


    <!-- RECENT STOCK MOVEMENTS -->
    <div class="panel">

        <div class="panel-heading">
            <div>
                <h2>Recent Stock Movements</h2>
                <p class="panel-kicker">A record of the latest inventory changes.</p>
            </div>

            <span class="panel-tag">Latest 20 records</span>
        </div>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Movement</th>
                        <th>Quantity</th>
                        <th>Remarks</th>
                        <th>Processed By</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($movements)): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No stock movements recorded yet.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($movements as $movement): ?>

                        <tr>

                            <td>
                                <?= e($movement['movement_date']) ?>
                            </td>

                            <td>
                                <?= e($movement['product_name']) ?>
                            </td>

                            <td>
                                <?php
                                $movement_class = $movement['movement_type'] === 'stock_in'
                                    ? 'movement-in'
                                    : 'movement-out';
                                ?>

                                <span class="movement-badge <?= $movement_class ?>">
                                    <?= e(ucwords(str_replace('_', ' ', $movement['movement_type']))) ?>
                                </span>
                            </td>

                            <td>
                                <?= (int) $movement['quantity'] ?>
                            </td>

                            <td>
                                <?= e($movement['remarks'] ?: '-') ?>
                            </td>

                            <td>
                                <?= e($movement['full_name']) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<script>
// DYNAMIC CATEGORY FILTER
const categoryButtons = document.querySelectorAll('.category-button');
const inventoryRows = document.querySelectorAll('.inventory-row');
const filterResult = document.getElementById('filterResult');
const noFilterResults = document.querySelector('.no-filter-results');

categoryButtons.forEach(function(button) {

    button.addEventListener('click', function() {

        const selectedCategory = this.dataset.category;
        let visibleCount = 0;

        categoryButtons.forEach(function(categoryButton) {
            const isActive = categoryButton === button;

            categoryButton.classList.toggle('active', isActive);
            categoryButton.setAttribute(
                'aria-pressed',
                isActive ? 'true' : 'false'
            );
        });

        inventoryRows.forEach(function(row) {

            const matches = selectedCategory === 'all' ||
                row.dataset.category === selectedCategory;

            row.style.display = matches ? '' : 'none';

            if (matches) {
                visibleCount++;
            }
        });

        if (noFilterResults) {
            noFilterResults.style.display =
                visibleCount === 0 ? 'table-row' : 'none';
        }

        const selectedName =
            this.querySelector('.category-name').textContent;

        if (selectedCategory === 'all') {

            filterResult.textContent =
                'Showing all ' + visibleCount +
                (visibleCount === 1 ? ' product.' : ' products.');

        } else {

            filterResult.textContent =
                'Showing ' + visibleCount +
                (visibleCount === 1 ? ' product' : ' products') +
                ' in ' + selectedName + '.';
        }
    });
});


// SHOW CUSTOM REMARK INPUT WHEN SELECTED
document.querySelectorAll('.remark-select').forEach(function(select) {

    function toggleCustomRemark() {

        const form = select.closest('form');
        const customInput = form.querySelector('.custom-remark');

        if (select.value === 'Custom Remark') {

            customInput.style.display = 'inline-block';
            customInput.required = true;

        } else {

            customInput.style.display = 'none';
            customInput.required = false;
            customInput.value = '';

        }
    }

    select.addEventListener('change', toggleCustomRemark);

    toggleCustomRemark();

});
</script>

</body>
</html>
