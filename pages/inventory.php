
<?php

date_default_timezone_set('America/Los_Angeles');

session_start();
require_once '../config/database.php';

// SYNCHRONIZE DATABASE TIMEZONE
$pacificOffset = (
    new DateTime('now', new DateTimeZone('America/Los_Angeles'))
)->format('P');

$conn->exec("SET time_zone = " . $conn->quote($pacificOffset));

// CHECK LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// USER INFORMATION
$rawName = $_SESSION['full_name'] ?? 'User';
$name = htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8');
$initial = strtoupper(substr($rawName, 0, 1));

$role = $_SESSION['role'] ?? 'staff';
$isAdmin = ($role === 'admin');
$userId = (int)$_SESSION['user_id'];

$todayLabel = date('l, F d, Y');

$message = '';
$messageType = 'success';

// HTML ESCAPE
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// CATEGORY ICON FALLBACK
function getCategoryIcon($categoryName)
{
    $name = strtolower((string)$categoryName);

    if (strpos($name, 'bread') !== false) {
        return '🍞';
    }

    if (strpos($name, 'cake') !== false) {
        return '🎂';
    }

    if (strpos($name, 'cookie') !== false) {
        return '🍪';
    }

    if (strpos($name, 'pastry') !== false) {
        return '🥐';
    }

    if (strpos($name, 'drink') !== false) {
        return '🥤';
    }

    if (strpos($name, 'donut') !== false) {
        return '🍩';
    }

    if (strpos($name, 'muffin') !== false) {
        return '🧁';
    }

    if (strpos($name, 'ensaymada') !== false) {
        return '🥯';
    }

    return '🧁';
}

// CATEGORY IMAGE URL
function getCategoryImageUrl($image)
{
    if (empty($image)) {
        return '';
    }

    $image = str_replace('\\', '/', (string)$image);
    $fileName = basename($image);

    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return '';
    }

    return '../uploads/categories/' . rawurlencode($fileName);
}

// GET NOTIFICATIONS
if (isset($_GET['stock_in'])) {
    $message = "Stock added successfully!";
} elseif (isset($_GET['stock_out'])) {
    $message = "Stock deducted successfully!";
} elseif (isset($_GET['minimum_updated'])) {
    $message = "Minimum stock updated successfully!";
} elseif (isset($_GET['error'])) {
    $message = trim($_GET['error']);
    $messageType = 'error';
}

// INITIALIZE INVENTORY FOR ACTIVE PRODUCTS
try {
    $initializeInventory = $conn->prepare("
        INSERT IGNORE INTO inventory
            (product_id, quantity, minimum_stock)
        SELECT
            product_id,
            0,
            5
        FROM products
        WHERE is_archived = 0
    ");

    $initializeInventory->execute();

} catch (PDOException $e) {
    $message = "Unable to initialize inventory records.";
    $messageType = 'error';
}

// STOCK IN / STOCK OUT / SET MINIMUM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // STOCK IN AND STOCK OUT
    if ($action === 'stock_in' || $action === 'stock_out') {

        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = filter_var(
            $_POST['quantity'] ?? null,
            FILTER_VALIDATE_INT
        );

        $remarksChoice = trim($_POST['remarks_choice'] ?? '');
        $customRemarks = trim($_POST['custom_remarks'] ?? '');

        $remarks = $remarksChoice === 'Custom Remark'
            ? $customRemarks
            : $remarksChoice;

        try {

            if ($productId <= 0) {
                throw new Exception("Invalid product selected.");
            }

            if ($quantity === false || $quantity <= 0) {
                throw new Exception("Please enter a valid quantity.");
            }

            if ($remarks === '') {
                throw new Exception("Please select or enter remarks.");
            }

            $conn->beginTransaction();

            // CHECK PRODUCT AND LOCK INVENTORY RECORD
            $checkProduct = $conn->prepare("
                SELECT
                    p.product_id,
                    p.product_name,
                    p.is_archived
                FROM products p
                WHERE p.product_id = ?
                FOR UPDATE
            ");

            $checkProduct->execute([$productId]);
            $product = $checkProduct->fetch(PDO::FETCH_ASSOC);

            if (!$product || (int)$product['is_archived'] === 1) {
                throw new Exception("Product not found or archived.");
            }

            // LOCK CURRENT INVENTORY
            $inventoryStmt = $conn->prepare("
                SELECT quantity
                FROM inventory
                WHERE product_id = ?
                FOR UPDATE
            ");

            $inventoryStmt->execute([$productId]);
            $inventory = $inventoryStmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                throw new Exception("Inventory record not found.");
            }

            $currentQuantity = (int)$inventory['quantity'];

            if ($action === 'stock_out' && $quantity > $currentQuantity) {
                throw new Exception(
                    "Insufficient stock. Current stock is " .
                    $currentQuantity . "."
                );
            }

            // COMPUTE NEW STOCK
            if ($action === 'stock_in') {
                $newQuantity = $currentQuantity + $quantity;
                $movementType = 'IN';
            } else {
                $newQuantity = $currentQuantity - $quantity;
                $movementType = 'OUT';
            }

            // UPDATE INVENTORY QUANTITY
            $updateInventory = $conn->prepare("
                UPDATE inventory
                SET quantity = ?,
                    updated_at = NOW()
                WHERE product_id = ?
            ");

            $updateInventory->execute([
                $newQuantity,
                $productId
            ]);

            // RECORD STOCK MOVEMENT
            $movementStmt = $conn->prepare("
                INSERT INTO stock_movements
                    (
                        product_id,
                        user_id,
                        movement_type,
                        quantity,
                        remarks,
                        movement_date
                    )
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            $movementStmt->execute([
                $productId,
                $userId,
                $movementType,
                $quantity,
                $remarks
            ]);

            $conn->commit();

            if ($action === 'stock_in') {
                header("Location: inventory.php?stock_in=1");
            } else {
                header("Location: inventory.php?stock_out=1");
            }

            exit;

        } catch (Exception $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            header(
                "Location: inventory.php?error=" .
                urlencode($e->getMessage())
            );

            exit;
        }
    }

    // SET MINIMUM STOCK
    if ($action === 'set_minimum') {

        $productId = (int)($_POST['product_id'] ?? 0);

        $minimumStock = filter_var(
            $_POST['minimum_stock'] ?? null,
            FILTER_VALIDATE_INT
        );

        try {

            if ($productId <= 0) {
                throw new Exception("Invalid product selected.");
            }

            if ($minimumStock === false || $minimumStock < 0) {
                throw new Exception("Please enter a valid minimum stock.");
            }

            $checkProduct = $conn->prepare("
                SELECT product_id
                FROM products
                WHERE product_id = ?
                  AND is_archived = 0
            ");

            $checkProduct->execute([$productId]);

            if (!$checkProduct->fetchColumn()) {
                throw new Exception("Product not found or archived.");
            }

            // ONLY UPDATE MINIMUM STOCK.
            // CURRENT QUANTITY WILL NOT BE CHANGED.
            $updateMinimum = $conn->prepare("
                UPDATE inventory
                SET minimum_stock = ?,
                    updated_at = NOW()
                WHERE product_id = ?
            ");

            $updateMinimum->execute([
                $minimumStock,
                $productId
            ]);

            header("Location: inventory.php?minimum_updated=1");
            exit;

        } catch (Exception $e) {

            header(
                "Location: inventory.php?error=" .
                urlencode($e->getMessage())
            );

            exit;
        }
    }
}

// CATEGORY FILTER
$selectedCategory = (int)($_GET['category'] ?? 0);

// GET CATEGORIES, IMAGES AND PRODUCT COUNTS
$categoryCountStmt = $conn->query("
    SELECT
        c.category_id,
        c.category_name,
        c.category_image,
        COUNT(p.product_id) AS product_count
    FROM categories c
    LEFT JOIN products p
        ON c.category_id = p.category_id
        AND p.is_archived = 0
    GROUP BY
        c.category_id,
        c.category_name,
        c.category_image
    ORDER BY c.category_name ASC
");

$categoryCounts = $categoryCountStmt->fetchAll(PDO::FETCH_ASSOC);

// TOTAL ACTIVE PRODUCTS
$totalRegisteredProducts = (int)$conn->query("
    SELECT COUNT(*)
    FROM products
    WHERE is_archived = 0
")->fetchColumn();

// GET INVENTORY PRODUCTS
// ADDED: c.category_image
$inventorySql = "
    SELECT
        p.product_id,
        p.product_name,
        p.product_image,
        p.price,
        p.is_archived,
        c.category_id,
        c.category_name,
        c.category_image,
        i.quantity,
        i.minimum_stock
    FROM products p
    INNER JOIN inventory i
        ON p.product_id = i.product_id
    LEFT JOIN categories c
        ON p.category_id = c.category_id
    WHERE p.is_archived = 0
";

$inventoryParams = [];

if ($selectedCategory > 0) {
    $inventorySql .= " AND p.category_id = :category";
    $inventoryParams['category'] = $selectedCategory;
}

$inventorySql .= " ORDER BY p.product_name ASC";

$inventoryStmt = $conn->prepare($inventorySql);
$inventoryStmt->execute($inventoryParams);

$inventoryProducts = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

// INVENTORY STATISTICS
$statsStmt = $conn->query("
    SELECT
        COUNT(*) AS total_products,
        COALESCE(SUM(i.quantity), 0) AS total_stock,

        SUM(
            CASE
                WHEN i.quantity > 0
                 AND i.quantity <= i.minimum_stock
                THEN 1 ELSE 0
            END
        ) AS low_stock,

        SUM(
            CASE
                WHEN i.quantity <= 0
                THEN 1 ELSE 0
            END
        ) AS out_of_stock,

        SUM(
            CASE
                WHEN i.quantity > 0
                THEN 1 ELSE 0
            END
        ) AS available_products

    FROM products p
    INNER JOIN inventory i
        ON p.product_id = i.product_id
    WHERE p.is_archived = 0
");

$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$totalProducts = (int)($stats['total_products'] ?? 0);
$totalStock = (int)($stats['total_stock'] ?? 0);
$lowStock = (int)($stats['low_stock'] ?? 0);
$outOfStock = (int)($stats['out_of_stock'] ?? 0);
$availableProducts = (int)($stats['available_products'] ?? 0);

$availabilityPercentage = $totalProducts > 0
    ? round(($availableProducts / $totalProducts) * 100)
    : 0;

// RECENT STOCK MOVEMENTS
$movementStmt = $conn->query("
    SELECT
        sm.movement_type,
        sm.quantity,
        sm.remarks,
        sm.movement_date,
        p.product_name,
        u.full_name
    FROM stock_movements sm
    LEFT JOIN products p
        ON sm.product_id = p.product_id
    LEFT JOIN users u
        ON sm.user_id = u.user_id
    ORDER BY sm.movement_date DESC
    LIMIT 20
");

$recentMovements = $movementStmt->fetchAll(PDO::FETCH_ASSOC);

// CATEGORY FILTER DISPLAY NAME
$selectedCategoryName = 'All Products';

if ($selectedCategory > 0) {
    foreach ($categoryCounts as $category) {
        if ((int)$category['category_id'] === $selectedCategory) {
            $selectedCategoryName = $category['category_name'];
            break;
        }
    }
}

// CATEGORY URL
function categoryUrl($categoryId)
{
    if ((int)$categoryId === 0) {
        return 'inventory.php';
    }

    return 'inventory.php?category=' . (int)$categoryId;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Inventory | Bakery Management System</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

<style>

:root {
    --brown-dark: #54351f;
    --brown-deep: #422918;
    --brown: #805735;
    --brown-light: #a77b55;
    --cream: #f7f4ef;
    --text: #34271f;
    --muted: #88796e;
    --border: #eee5dc;
    --white: #ffffff;
    --sidebar-width: 260px;
}

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
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
    min-width: 22px;
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
}

.logout-link:hover {
    background: #8e3428;
}

/* MAIN */

.main {
    min-height: 100vh;
    margin-left: var(--sidebar-width);
    padding: 32px;
}

.content-container {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 28px;
}

.page-title h1 {
    margin: 0;
    color: var(--brown-deep);
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 29px;
}

.page-title p {
    margin-top: 5px;
    color: var(--muted);
    font-size: 13px;
}

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

/* WELCOME BANNER */

.welcome-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 27px 30px;
    margin-bottom: 25px;
    color: white;
    background: linear-gradient(115deg, #59391f, #805633);
    border-radius: 15px;
}

.welcome-banner h2 {
    margin: 0 0 7px;
    color: #fff;
    font-family: 'Playfair Display', serif;
    font-size: 23px;
}

.welcome-banner p {
    margin: 0;
    color: #e6d7c8;
    font-size: 12px;
    line-height: 1.7;
}

.banner-icon {
    font-size: 55px;
    opacity: .9;
}

/* SECTION HEADINGS */

.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin: 27px 0 15px;
}

.section-heading h2 {
    margin: 0;
    color: var(--brown);
    font-size: 16px;
}

.section-heading span {
    color: var(--muted);
    font-size: 11px;
}

/* OVERVIEW CARDS */

.overview-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.overview-card {
    min-width: 0;
    padding: 20px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(55,35,18,.035);
    transition: .2s ease;
}

.overview-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(55,35,18,.08);
}

.overview-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.overview-card-label {
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
}

.overview-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    font-size: 17px;
}

.icon-brown {
    background: #f2e7db;
}

.icon-green {
    background: #e3f2e8;
}

.icon-orange {
    background: #fff0db;
}

.icon-red {
    background: #fce8e6;
}

.overview-number {
    margin-top: 15px;
    color: var(--brown);
    font-size: 25px;
    font-weight: 800;
}

.overview-description {
    margin-top: 5px;
    color: var(--muted);
    font-size: 10px;
}

/* PANELS */

.panel {
    min-width: 0;
    padding: 21px;
    margin-bottom: 20px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}

.panel-header h2 {
    margin: 0;
    color: var(--brown);
    font-size: 14px;
}

.panel-header p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 10px;
}

.panel-header-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #f2e7db;
    font-size: 17px;
}

/* NOTIFICATIONS */

.message {
    padding: 11px 14px;
    margin-bottom: 16px;
    border-radius: 8px;
    font-size: 11px;
    transition: opacity .5s ease;
}

.success-message {
    background: #e3f2e8;
    color: #34734a;
    border: 1px solid #b9e2c1;
}

.error-message {
    background: #fce8e6;
    color: #a12626;
    border: 1px solid #f2baba;
}

/* INVENTORY STATUS */

.status-panel {
    padding: 21px;
    margin-bottom: 20px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
}

.status-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 13px;
}

.status-top h3 {
    margin: 0;
    color: var(--brown);
    font-size: 14px;
}

.status-percentage {
    color: var(--brown);
    font-size: 20px;
    font-weight: 800;
}

.progress-track {
    width: 100%;
    height: 11px;
    overflow: hidden;
    background: #eee7df;
    border-radius: 20px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #805735, #b68b63);
    border-radius: 20px;
    transition: width .3s ease;
}

.status-description {
    margin: 10px 0 0;
    color: var(--muted);
    font-size: 10px;
}

/* CATEGORY CARDS */

.category-section {
    margin-bottom: 22px;
    scroll-margin-top: 20px;
}

.category-title {
    margin: 0 0 17px;
    color: var(--brown-light);
    font-size: 15px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.category-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.category-card {
    display: flex;
    align-items: center;
    gap: 13px;
    min-width: 0;
    min-height: 87px;
    padding: 15px 18px;
    color: var(--brown-dark);
    background: #fffdfa;
    border: 1px solid #eee1d5;
    border-radius: 17px;
    text-decoration: none;
    transition: .2s ease;
}

.category-card:hover {
    transform: translateY(-2px);
    border-color: var(--brown-light);
    box-shadow: 0 5px 14px rgba(84,53,31,.08);
}

.category-card.active {
    color: white;
    background: linear-gradient(120deg, #805735, #54351f);
    border-color: var(--brown-dark);
    box-shadow: 0 6px 15px rgba(84,53,31,.15);
}

/* CATEGORY IMAGE */

.category-card-image,
.category-card-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 54px;
    height: 54px;
    flex-shrink: 0;
    border-radius: 15px;
    background: #f1e5d9;
}

.category-card-image {
    object-fit: cover;
    border: 1px solid #eee1d5;
}

.category-card-icon {
    font-size: 27px;
}

.category-card.active .category-card-icon {
    background: rgba(255,255,255,.17);
}

.category-card-info {
    min-width: 0;
}

.category-card-name {
    display: block;
    overflow: hidden;
    color: inherit;
    font-size: 14px;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.category-card-count {
    display: block;
    margin-top: 5px;
    color: var(--muted);
    font-size: 12px;
}

.category-card.active .category-card-count {
    color: #ead9c8;
}

.category-showing {
    margin-top: 17px;
    color: var(--muted);
    font-size: 12px;
}

/* BUTTONS */

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 9px 13px;
    border: none;
    border-radius: 7px;
    text-decoration: none;
    cursor: pointer;
    font-family: inherit;
    font-size: 10px;
    font-weight: 700;
    transition: .2s;
}

.primary-btn {
    background: var(--brown-dark);
    color: white;
}

.primary-btn:hover {
    background: var(--brown-light);
    transform: translateY(-1px);
}

.cancel-btn {
    background: #eee8e0;
    color: var(--brown);
}

.cancel-btn:hover {
    background: #e4d9cc;
}

.stock-in-btn {
    background: #e3f2e8;
    color: #34734a;
}

.stock-in-btn:hover {
    background: #cce8d4;
}

.stock-out-btn {
    background: #fce8e6;
    color: #b83232;
}

.stock-out-btn:hover {
    background: #f8d6d3;
}

.minimum-btn {
    background: #e6eff8;
    color: #2878b5;
}

.minimum-btn:hover {
    background: #d5e5f5;
}

/* STOCK FORMS */

.stock-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 190px;
}

.stock-form {
    display: grid;
    grid-template-columns: 75px 1fr;
    gap: 5px;
    align-items: center;
}

.stock-form input,
.stock-form select {
    width: 100%;
    min-width: 0;
    padding: 7px 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    outline: none;
    background: #fff;
    color: var(--text);
    font-family: inherit;
    font-size: 10px;
}

.stock-form input:focus,
.stock-form select:focus {
    border-color: var(--brown-light);
    box-shadow: 0 0 0 3px rgba(130,85,47,.09);
}

.stock-form .btn {
    grid-column: 1 / -1;
    width: 100%;
    padding: 7px;
}

.custom-remark {
    display: none;
    grid-column: 1 / -1;
}

.custom-remark.show {
    display: block;
}

.minimum-form {
    display: flex;
    align-items: center;
    gap: 5px;
    min-width: 125px;
}

.minimum-form input {
    width: 65px;
    padding: 7px;
    border: 1px solid var(--border);
    border-radius: 6px;
    outline: none;
    font-family: inherit;
    font-size: 10px;
}

.minimum-form .btn {
    padding: 7px 8px;
}

/* STATUS BADGES */

.status-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 700;
    white-space: nowrap;
}

.status-in {
    background: #e3f2e8;
    color: #34734a;
}

.status-low {
    background: #fff0db;
    color: #a66a18;
}

.status-out {
    background: #fce8e6;
    color: #b83232;
}

/* TABLE */

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
}

th,
td {
    padding: 13px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    font-size: 11px;
    vertical-align: middle;
}

th {
    color: var(--muted);
    background: #faf8f5;
    font-weight: 700;
}

td {
    color: var(--text);
}

.product-cell {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 180px;
}

.product-thumbnail {
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: #faf8f5;
}

.no-product-image {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    border-radius: 8px;
    background: #f2e7db;
    font-size: 22px;
}

.product-name {
    color: var(--brown);
    font-weight: 700;
}

/* CATEGORY IMAGE IN INVENTORY TABLE */

.product-category-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 150px;
}

.category-thumbnail {
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    object-fit: cover;
    border-radius: 7px;
    border: 1px solid var(--border);
    background: #faf8f5;
}

.category-fallback-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    border-radius: 7px;
    background: #f2e7db;
    font-size: 17px;
}

.category-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #f2e7db;
    color: var(--brown);
    font-size: 9px;
    font-weight: 700;
}

.stock-number {
    color: var(--brown);
    font-size: 14px;
    font-weight: 800;
}

.minimum-number {
    color: var(--muted);
    font-weight: 700;
}

.empty {
    padding: 26px !important;
    color: var(--muted);
    text-align: center;
}

.product-count {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 10px;
    border-radius: 20px;
    background: #f2e7db;
    color: var(--brown);
    font-size: 9px;
    font-weight: 700;
    white-space: nowrap;
}

/* MOVEMENT LABELS */

.movement-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 800;
}

.movement-in {
    background: #e3f2e8;
    color: #34734a;
}

.movement-out {
    background: #fce8e6;
    color: #b83232;
}

.quantity-in {
    color: #34734a;
    font-weight: 800;
}

.quantity-out {
    color: #b83232;
    font-weight: 800;
}

/* FOOTER */

.footer {
    margin-top: 30px;
    padding: 15px 0;
    color: var(--muted);
    border-top: 1px solid var(--border);
    font-size: 10px;
    text-align: center;
}

/* RESPONSIVE */

@media (max-width: 1200px) {
    .overview-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .category-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 1100px) {

    :root {
        --sidebar-width: 225px;
    }

    .sidebar {
        padding-left: 18px;
        padding-right: 18px;
    }

    .main {
        padding: 24px;
    }

    .category-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 800px) {

    :root {
        --sidebar-width: 190px;
    }

    .sidebar {
        padding: 20px 12px;
    }

    .brand {
        gap: 8px;
        padding-left: 4px;
        margin-bottom: 40px;
    }

    .brand-icon {
        font-size: 30px;
    }

    .brand-name {
        font-size: 18px;
    }

    .brand-subtitle {
        font-size: 9px;
    }

    .nav-link {
        gap: 10px;
        padding: 10px;
        font-size: 13px;
    }

    .main {
        padding: 20px 15px;
    }

    .overview-grid {
        gap: 10px;
    }

    .overview-card {
        padding: 16px;
    }

    .panel {
        padding: 18px;
    }

    .category-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 620px) {

    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
        padding: 16px;
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
        min-width: 17px;
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

    .main {
        margin-left: 0;
        padding: 17px 12px;
    }

    .topbar {
        align-items: flex-start;
    }

    .page-title h1 {
        font-size: 27px;
    }

    .welcome-banner {
        padding: 22px;
    }

    .welcome-banner h2 {
        font-size: 19px;
    }

    .banner-icon {
        font-size: 38px;
    }

    .overview-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .overview-card {
        padding: 17px;
    }

    .panel {
        padding: 14px;
    }

    .section-heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .category-grid {
        grid-template-columns: 1fr;
    }

    .category-card {
        min-height: 75px;
    }

    .stock-actions {
        min-width: 180px;
    }
}

@media (max-width: 360px) {

    .nav-menu {
        grid-template-columns: 1fr;
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

        <a href="inventory.php" class="nav-link active" aria-current="page">
            <span class="nav-icon">📦</span>
            Inventory
        </a>

        <a href="sales.php" class="nav-link">
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

<main class="main">

<div class="content-container">

    <!-- TOPBAR -->

    <header class="topbar">

        <div class="page-title">
            <h1>Inventory Management</h1>
            <p>Monitor and manage your bakery stock.</p>
        </div>

        <div class="user-info">

            <div class="user-avatar">
                <?= e($initial) ?>
            </div>

            <div class="user-details">

                <strong><?= $name ?></strong>

                <span>
                    <span class="role-badge">
                        <?= $isAdmin ? 'ADMINISTRATOR' : 'STAFF' ?>
                    </span>
                </span>

            </div>

        </div>

    </header>

    <!-- WELCOME BANNER -->

    <section class="welcome-banner">

        <div>

            <h2>Inventory Overview 📦</h2>

            <p>
                Track your bakery products, monitor stock levels, and manage stock movements.
                <br>
                <?= e($todayLabel) ?>
            </p>

        </div>

        <div class="banner-icon">🥐</div>

    </section>

    <!-- NOTIFICATIONS -->

    <?php if ($message): ?>

        <div
            class="message <?= $messageType === 'success' ? 'success-message' : 'error-message' ?>"
            id="inventoryMessage"
        >
            <?= e($message) ?>
        </div>

    <?php endif; ?>

    <!-- INVENTORY OVERVIEW -->

    <div class="section-heading">

        <h2>Inventory Overview</h2>

        <span>Summary of your current stock</span>

    </div>

    <section class="overview-grid">

        <div class="overview-card">

            <div class="overview-card-top">

                <span class="overview-card-label">Total Products</span>

                <span class="overview-icon icon-brown">🍞</span>

            </div>

            <div class="overview-number">
                <?= number_format($totalProducts) ?>
            </div>

            <p class="overview-description">
                Registered active products
            </p>

        </div>

        <div class="overview-card">

            <div class="overview-card-top">

                <span class="overview-card-label">Available Stock</span>

                <span class="overview-icon icon-green">📦</span>

            </div>

            <div class="overview-number">
                <?= number_format($totalStock) ?>
            </div>

            <p class="overview-description">
                Total quantity of all products
            </p>

        </div>

        <div class="overview-card">

            <div class="overview-card-top">

                <span class="overview-card-label">Low Stock Products</span>

                <span class="overview-icon icon-orange">⚠️</span>

            </div>

            <div class="overview-number">
                <?= number_format($lowStock) ?>
            </div>

            <p class="overview-description">
                Products at or below minimum stock
            </p>

        </div>

        <div class="overview-card">

            <div class="overview-card-top">

                <span class="overview-card-label">Out of Stock</span>

                <span class="overview-icon icon-red">❌</span>

            </div>

            <div class="overview-number">
                <?= number_format($outOfStock) ?>
            </div>

            <p class="overview-description">
                Products with zero stock
            </p>

        </div>

    </section>

    <!-- INVENTORY STATUS -->

    <div class="section-heading">

        <h2>Inventory Status</h2>

        <span>Product availability summary</span>

    </div>

    <section class="status-panel">

        <div class="status-top">

            <h3>Available Products</h3>

            <span class="status-percentage">
                <?= $availabilityPercentage ?>%
            </span>

        </div>

        <div class="progress-track">

            <div
                class="progress-fill"
                style="width: <?= $availabilityPercentage ?>%;"
            ></div>

        </div>

        <p class="status-description">
            <?= number_format($availableProducts) ?>
            out of
            <?= number_format($totalProducts) ?>
            products currently have available stock.
        </p>

    </section>

    <!-- PRODUCT INVENTORY -->

    <div class="section-heading" id="category-products">

        <h2>Product Inventory</h2>

        <span>Manage stock levels by product category</span>

    </div>

    <section class="panel">

        <div class="panel-header">

            <div>

                <h2>Choose a Product Category</h2>

                <p>
                    Select a category to view and manage its inventory.
                </p>

            </div>

            <div class="panel-header-icon">📦</div>

        </div>

        <!-- CATEGORY CARDS -->

        <div class="category-section">

            <h3 class="category-title">
                Choose a Product Category
            </h3>

            <div class="category-grid">

                <!-- ALL PRODUCTS -->

                <a
                    href="<?= e(categoryUrl(0)) ?>#category-products"
                    class="category-card <?= $selectedCategory === 0 ? 'active' : '' ?>"
                >

                    <div class="category-card-icon">📦</div>

                    <div class="category-card-info">

                        <span class="category-card-name">
                            All Products
                        </span>

                        <span class="category-card-count">
                            <?= number_format($totalRegisteredProducts) ?> products
                        </span>

                    </div>

                </a>

                <!-- INDIVIDUAL CATEGORIES -->

                <?php foreach ($categoryCounts as $cat): ?>

                    <a
                        href="<?= e(categoryUrl($cat['category_id'])) ?>#category-products"
                        class="category-card <?= $selectedCategory === (int)$cat['category_id'] ? 'active' : '' ?>"
                    >

                        <?php if (!empty($cat['category_image'])): ?>

                            <img
                                src="<?= e(getCategoryImageUrl($cat['category_image'])) ?>"
                                class="category-card-image"
                                alt="<?= e($cat['category_name']) ?>"
                                loading="lazy"
                            >

                        <?php else: ?>

                            <div class="category-card-icon">
                                <?= getCategoryIcon($cat['category_name']) ?>
                            </div>

                        <?php endif; ?>

                        <div class="category-card-info">

                            <span class="category-card-name">
                                <?= e($cat['category_name']) ?>
                            </span>

                            <span class="category-card-count">
                                <?= number_format((int)$cat['product_count']) ?> products
                            </span>

                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

            <div class="category-showing">

                <?php if ($selectedCategory > 0): ?>

                    Showing
                    <strong><?= number_format(count($inventoryProducts)) ?></strong>
                    products in
                    <strong><?= e($selectedCategoryName) ?></strong>.

                <?php else: ?>

                    Showing all
                    <strong><?= number_format(count($inventoryProducts)) ?></strong>
                    inventory products.

                <?php endif; ?>

            </div>

        </div>

        <!-- INVENTORY TABLE HEADER -->

        <div class="panel-header">

            <div>

                <h2>
                    <?= e($selectedCategoryName) ?> Inventory
                </h2>

                <p>
                    Manage stock in, stock out, and minimum stock levels.
                </p>

            </div>

            <span class="product-count">
                📦 <?= number_format(count($inventoryProducts)) ?> Products
            </span>

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
                        <th>Stock In / Out</th>
                        <th>Set Minimum</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if ($inventoryProducts): ?>

                        <?php foreach ($inventoryProducts as $product): ?>

                            <?php

                            $quantity = (int)$product['quantity'];
                            $minimum = (int)$product['minimum_stock'];

                            if ($quantity <= 0) {
                                $status = 'Out of Stock';
                                $statusClass = 'status-out';
                            } elseif ($quantity <= $minimum) {
                                $status = 'Low Stock';
                                $statusClass = 'status-low';
                            } else {
                                $status = 'In Stock';
                                $statusClass = 'status-in';
                            }

                            ?>

                            <tr>

                                <!-- PRODUCT -->

                                <td>

                                    <div class="product-cell">

                                        <?php if (!empty($product['product_image'])): ?>

                                            <img
                                                src="../uploads/products/<?= rawurlencode(basename($product['product_image'])) ?>"
                                                class="product-thumbnail"
                                                alt="<?= e($product['product_name']) ?>"
                                                loading="lazy"
                                            >

                                        <?php else: ?>

                                            <div class="no-product-image">
                                                <?= getCategoryIcon($product['category_name'] ?? '') ?>
                                            </div>

                                        <?php endif; ?>

                                        <span class="product-name">
                                            <?= e($product['product_name']) ?>
                                        </span>

                                    </div>

                                </td>

                                <!-- CATEGORY WITH IMAGE -->

                                <td>

                                    <div class="product-category-cell">

                                        <?php if (!empty($product['category_image'])): ?>

                                            <img
                                                src="<?= e(getCategoryImageUrl($product['category_image'])) ?>"
                                                class="category-thumbnail"
                                                alt="<?= e($product['category_name'] ?? 'Category') ?>"
                                                loading="lazy"
                                            >

                                        <?php else: ?>

                                            <span class="category-fallback-icon">
                                                <?= getCategoryIcon($product['category_name'] ?? '') ?>
                                            </span>

                                        <?php endif; ?>

                                        <span class="category-badge">
                                            <?= e($product['category_name'] ?? 'Uncategorized') ?>
                                        </span>

                                    </div>

                                </td>

                                <!-- CURRENT STOCK -->

                                <td>

                                    <span class="stock-number">
                                        <?= number_format($quantity) ?>
                                    </span>

                                </td>

                                <!-- MINIMUM STOCK -->

                                <td>

                                    <span class="minimum-number">
                                        <?= number_format($minimum) ?>
                                    </span>

                                </td>

                                <!-- STATUS -->

                                <td>

                                    <span class="status-badge <?= $statusClass ?>">
                                        <?= e($status) ?>
                                    </span>

                                </td>

                                <!-- STOCK IN / OUT -->

                                <td>

                                    <div class="stock-actions">

                                        <!-- STOCK IN FORM -->

                                        <form
                                            method="POST"
                                            class="stock-form"
                                            onsubmit="return confirmStockIn(this);"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="stock_in"
                                            >

                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?= (int)$product['product_id'] ?>"
                                            >

                                            <input
                                                type="number"
                                                name="quantity"
                                                min="1"
                                                step="1"
                                                placeholder="Qty"
                                                required
                                            >

                                            <select
                                                name="remarks_choice"
                                                class="remarks-choice"
                                                required
                                            >

                                                <option value="">
                                                    Select Remarks
                                                </option>

                                                <option value="New Delivery">
                                                    New Delivery
                                                </option>

                                                <option value="Restocking">
                                                    Restocking
                                                </option>

                                                <option value="Supplier Delivery">
                                                    Supplier Delivery
                                                </option>

                                                <option value="Returned Products">
                                                    Returned Products
                                                </option>

                                                <option value="Stock Adjustment">
                                                    Stock Adjustment
                                                </option>

                                                <option value="Custom Remark">
                                                    Custom Remark
                                                </option>

                                            </select>

                                            <input
                                                type="text"
                                                name="custom_remarks"
                                                class="custom-remark"
                                                placeholder="Enter custom remarks"
                                                maxlength="255"
                                            >

                                            <button
                                                type="submit"
                                                class="btn stock-in-btn"
                                            >
                                                + Stock In
                                            </button>

                                        </form>

                                        <!-- STOCK OUT FORM -->

                                        <form
                                            method="POST"
                                            class="stock-form"
                                            onsubmit="return confirmStockOut(this, <?= $quantity ?>);"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="stock_out"
                                            >

                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?= (int)$product['product_id'] ?>"
                                            >

                                            <input
                                                type="number"
                                                name="quantity"
                                                min="1"
                                                max="<?= $quantity ?>"
                                                step="1"
                                                placeholder="Qty"
                                                required
                                            >

                                            <select
                                                name="remarks_choice"
                                                class="remarks-choice"
                                                required
                                            >

                                                <option value="">
                                                    Select Remarks
                                                </option>

                                                <option value="Damaged Products">
                                                    Damaged Products
                                                </option>

                                                <option value="Expired Products">
                                                    Expired Products
                                                </option>

                                                <option value="Used for Production">
                                                    Used for Production
                                                </option>

                                                <option value="Customer Order">
                                                    Customer Order
                                                </option>

                                                <option value="Stock Correction">
                                                    Stock Correction
                                                </option>

                                                <option value="Custom Remark">
                                                    Custom Remark
                                                </option>

                                            </select>

                                            <input
                                                type="text"
                                                name="custom_remarks"
                                                class="custom-remark"
                                                placeholder="Enter custom remarks"
                                                maxlength="255"
                                            >

                                            <button
                                                type="submit"
                                                class="btn stock-out-btn"
                                                <?= $quantity <= 0 ? 'disabled' : '' ?>
                                            >
                                                − Stock Out
                                            </button>

                                        </form>

                                    </div>

                                </td>

                                <!-- SET MINIMUM STOCK -->

                                <td>

                                    <form method="POST" class="minimum-form">

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="set_minimum"
                                        >

                                        <input
                                            type="hidden"
                                            name="product_id"
                                            value="<?= (int)$product['product_id'] ?>"
                                        >

                                        <input
                                            type="number"
                                            name="minimum_stock"
                                            min="0"
                                            step="1"
                                            value="<?= $minimum ?>"
                                            required
                                        >

                                        <button
                                            type="submit"
                                            class="btn minimum-btn"
                                            onclick="return confirm('Update minimum stock for <?= e($product['product_name']) ?>?');"
                                        >
                                            Save
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="7" class="empty">
                                No inventory products found in this category.
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- RECENT STOCK MOVEMENTS -->

    <div class="section-heading">

        <h2>Recent Stock Movements</h2>

        <span>Latest inventory transactions</span>

    </div>

    <section class="panel">

        <div class="panel-header">

            <div>

                <h2>Stock Movement History</h2>

                <p>
                    Track recent stock additions and deductions.
                </p>

            </div>

            <div class="panel-header-icon">📋</div>

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
                        <th>Recorded By</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if ($recentMovements): ?>

                        <?php foreach ($recentMovements as $movement): ?>

                            <?php

                            $isStockIn = strtoupper($movement['movement_type']) === 'IN';

                            ?>

                            <tr>

                                <td>
                                    <?= e(date(
                                        'M d, Y h:i A',
                                        strtotime($movement['movement_date'])
                                    )) ?>
                                </td>

                                <td>

                                    <span class="product-name">
                                        <?= e($movement['product_name'] ?? 'Deleted Product') ?>
                                    </span>

                                </td>

                                <td>

                                    <span class="movement-badge <?= $isStockIn ? 'movement-in' : 'movement-out' ?>">
                                        <?= $isStockIn ? 'STOCK IN' : 'STOCK OUT' ?>
                                    </span>

                                </td>

                                <td>

                                    <span class="<?= $isStockIn ? 'quantity-in' : 'quantity-out' ?>">
                                        <?= $isStockIn ? '+' : '-' ?>
                                        <?= number_format((int)$movement['quantity']) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= e($movement['remarks'] ?? 'No remarks') ?>
                                </td>

                                <td>
                                    <?= e($movement['full_name'] ?? 'Unknown User') ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="6" class="empty">
                                No stock movements recorded yet.
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- FOOTER -->

    <footer class="footer">

        Bakery Sales and Stock Monitoring System
        &copy; <?= date('Y') ?>. All rights reserved.

    </footer>

</div>

</main>

<script>

// AUTO-HIDE NOTIFICATION

const inventoryMessage = document.getElementById("inventoryMessage");

if (inventoryMessage) {

    setTimeout(() => {

        inventoryMessage.style.opacity = "0";

        setTimeout(() => {
            inventoryMessage.remove();
        }, 500);

    }, 3500);

}


// CUSTOM REMARK TOGGLE

document.querySelectorAll(".stock-form").forEach(function(form) {

    const remarksSelect = form.querySelector(".remarks-choice");
    const customRemarks = form.querySelector(".custom-remark");

    if (!remarksSelect || !customRemarks) {
        return;
    }

    remarksSelect.addEventListener("change", function() {

        if (this.value === "Custom Remark") {

            customRemarks.classList.add("show");
            customRemarks.required = true;
            customRemarks.focus();

        } else {

            customRemarks.classList.remove("show");
            customRemarks.required = false;
            customRemarks.value = "";

        }

    });

});


// STOCK IN CONFIRMATION

function confirmStockIn(form) {

    const quantity = parseInt(
        form.querySelector('[name="quantity"]').value,
        10
    );

    const remarksChoice = form.querySelector(
        '[name="remarks_choice"]'
    ).value;

    const customRemarks = form.querySelector(
        '[name="custom_remarks"]'
    ).value.trim();

    if (!quantity || quantity <= 0) {
        alert("Please enter a valid quantity.");
        return false;
    }

    if (!remarksChoice) {
        alert("Please select remarks.");
        return false;
    }

    if (
        remarksChoice === "Custom Remark" &&
        customRemarks === ""
    ) {
        alert("Please enter your custom remarks.");
        return false;
    }

    return confirm(
        "Are you sure you want to add " +
        quantity +
        " item(s) to the inventory?"
    );

}


// STOCK OUT CONFIRMATION

function confirmStockOut(form, currentStock) {

    const quantity = parseInt(
        form.querySelector('[name="quantity"]').value,
        10
    );

    const remarksChoice = form.querySelector(
        '[name="remarks_choice"]'
    ).value;

    const customRemarks = form.querySelector(
        '[name="custom_remarks"]'
    ).value.trim();

    if (!quantity || quantity <= 0) {
        alert("Please enter a valid quantity.");
        return false;
    }

    if (quantity > currentStock) {
        alert(
            "Insufficient stock. Current stock is " +
            currentStock +
            "."
        );

        return false;
    }

    if (!remarksChoice) {
        alert("Please select remarks.");
        return false;
    }

    if (
        remarksChoice === "Custom Remark" &&
        customRemarks === ""
    ) {
        alert("Please enter your custom remarks.");
        return false;
    }

    return confirm(
        "Are you sure you want to deduct " +
        quantity +
        " item(s) from the inventory?"
    );

}


// CATEGORY FILTER SCROLL POSITION

window.addEventListener("load", function() {

    if (window.location.hash === "#category-products") {

        const categorySection = document.getElementById(
            "category-products"
        );

        if (categorySection) {

            categorySection.scrollIntoView({
                behavior: "instant",
                block: "start"
            });

        }

    }

});

</script>

</body>
</html>
