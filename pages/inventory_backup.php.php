<?php



date_default_timezone_set('America/Los_Angeles');



session_start();

require_once '../config/database.php';



// Synchronize MySQL timezone with PHP.

$pacificOffset = (

    new DateTime(

        'now',

        new DateTimeZone('America/Los_Angeles')

    )

)->format('P');



$conn->exec("SET time_zone = " . $conn->quote($pacificOffset));



// Protect page.

if (!isset($_SESSION['user_id'])) {

    header("Location: ../login.php");

    exit;

}



// USER INFORMATION

$rawName = $_SESSION['full_name'] ?? 'User';

$userName = htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8');

$userRole = $_SESSION['role'] ?? 'staff';

$isAdmin = ($userRole === 'admin');

$initial = strtoupper(substr($rawName, 0, 1));



$displayRole = $isAdmin ? 'ADMINISTRATOR' : 'STAFF';



// Initialize missing inventory records.

try {

    $conn->exec("

        INSERT INTO inventory (product_id, quantity, minimum_stock)

        SELECT p.product_id, 0, 5

        FROM products p

        LEFT JOIN inventory i ON p.product_id = i.product_id

        WHERE i.product_id IS NULL
          AND p.is_archived = 0

    ");

} catch (PDOException $e) {

    error_log($e->getMessage());

    die("Unable to initialize inventory.");

}



// HANDLE STOCK ACTIONS

if ($_SERVER['REQUEST_METHOD'] === 'POST') {



    $action = $_POST['action'] ?? '';

    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);



    try {



        if (!$product_id || $product_id < 1) {

            throw new Exception("Invalid product selected.");

        }

        $activeProductCheck = $conn->prepare("
            SELECT COUNT(*) FROM products
            WHERE product_id = ? AND is_archived = 0
        ");
        $activeProductCheck->execute([$product_id]);

        if (!(int)$activeProductCheck->fetchColumn()) {
            throw new Exception("This product is archived and cannot be updated in inventory.");
        }



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



            $current_quantity = (int)$inventory['quantity'];



            if ($action === 'stock_out' && $amount > $current_quantity) {

                throw new Exception(

                    "Not enough stock available. Current stock: " . $current_quantity

                );

            }



            if ($action === 'stock_in') {

                $new_quantity = $current_quantity + $amount;

                $movement_type = 'stock_in';

            } else {

                $new_quantity = $current_quantity - $amount;

                $movement_type = 'stock_out';

            }



            $update = $conn->prepare("

                UPDATE inventory

                SET quantity = ?,

                    updated_at = CURRENT_TIMESTAMP

                WHERE product_id = ?

            ");



            $update->execute([$new_quantity, $product_id]);



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



        error_log($e->getMessage());



        header("Location: inventory.php?error=" . urlencode(

            "A database error occurred. Please try again."

        ));

        exit;



    } catch (Throwable $e) {



        if ($conn->inTransaction()) {

            $conn->rollBack();

        }



        header("Location: inventory.php?error=" . urlencode($e->getMessage()));

        exit;

    }

}



// GET INVENTORY PRODUCTS

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

    LEFT JOIN categories c ON p.category_id = c.category_id

    INNER JOIN inventory i ON p.product_id = i.product_id

    WHERE p.is_archived = 0

    ORDER BY p.product_name ASC

");



$products = $stmt->fetchAll(PDO::FETCH_ASSOC);



// GET CATEGORIES

$categoryStmt = $conn->query("

    SELECT category_id, category_name

    FROM categories

    ORDER BY category_name ASC

");



$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);



// COUNT PRODUCTS PER CATEGORY

$category_counts = [];



foreach ($products as $product) {



    $category_id = $product['category_id'] === null

        ? 'uncategorized'

        : (string)$product['category_id'];



    $category_counts[$category_id] =

        ($category_counts[$category_id] ?? 0) + 1;

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

    INNER JOIN products p ON sm.product_id = p.product_id

    INNER JOIN users u ON sm.user_id = u.user_id

    ORDER BY sm.movement_date DESC

    LIMIT 20

");



$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);



// INVENTORY SUMMARY

$total_products = count($products);

$total_stock = 0;

$low_stock_count = 0;

$out_of_stock_count = 0;



foreach ($products as $product) {



    $quantity = (int)$product['quantity'];

    $minimum = (int)$product['minimum_stock'];



    $total_stock += $quantity;



    if ($quantity <= $minimum) {

        $low_stock_count++;

    }



    if ($quantity === 0) {

        $out_of_stock_count++;

    }

}



// INVENTORY AVAILABILITY PERCENTAGE

$available_percentage = $total_products > 0

    ? (($total_products - $out_of_stock_count) / $total_products) * 100

    : 0;



// SECURE OUTPUT

function e($value) {

    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

}



/*

\|--------------------------------------------------------------------------

\| AUTOMATIC PRODUCT IMAGE MATCHING

\|--------------------------------------------------------------------------

\| Images are selected using the product name and category.

\| This works for existing products and newly added products.

\| No database image column is required.

*/



function getProductImage($productName, $categoryName = '') {



    $name = strtolower(trim($productName));

    $category = strtolower(trim($categoryName));



    // Cake products

    if (

        strpos($name, 'cake') !== false ||

        strpos($name, 'cheesecake') !== false ||

        strpos($name, 'cupcake') !== false ||

        strpos($name, 'tiramisu') !== false ||

        strpos($name, 'black forest') !== false ||

        strpos($name, 'red velvet') !== false ||

        strpos($category, 'cake') !== false

    ) {

        return 'https\://images.unsplash.com/photo-1578985545062-69928b1d9587?w=300&h=220&fit=crop';

    }



    // Bread products

    if (

        strpos($name, 'bread') !== false ||

        strpos($name, 'loaf') !== false ||

        strpos($name, 'pandesal') !== false ||

        strpos($name, 'baguette') !== false ||

        strpos($name, 'toast') !== false ||

        strpos($name, 'bun') !== false ||

        strpos($category, 'bread') !== false

    ) {

        return 'https\://images.unsplash.com/photo-1509440159596-0249088772ff?w=300&h=220&fit=crop';

    }



    // Croissant products

    if (

        strpos($name, 'croissant') !== false ||

        strpos($name, 'danish') !== false ||

        strpos($name, 'puff') !== false

    ) {

        return 'https\://images.unsplash.com/photo-1555507036-ab1f4038808a?w=300&h=220&fit=crop';

    }



    // Cookie and biscuit products

    if (

        strpos($name, 'cookie') !== false ||

        strpos($name, 'cookies') !== false ||

        strpos($name, 'biscuit') !== false ||

        strpos($name, 'cracker') !== false ||

        strpos($category, 'cookie') !== false

    ) {

        return 'https\://images.unsplash.com/photo-1499636136210-6f4ee915583e?w=300&h=220&fit=crop';

    }



    // Donut products

    if (

        strpos($name, 'donut') !== false ||

        strpos($name, 'doughnut') !== false

    ) {

        return 'https\://images.unsplash.com/photo-1551024506-0?auto=format&fit=crop&w=300&h=220';

    }



    // Muffin products

    if (

        strpos($name, 'muffin') !== false

    ) {

        return 'https\://images.unsplash.com/photo-1607958996333-41aef7caefaa?w=300&h=220&fit=crop';

    }



    // Pastry products

    if (

        strpos($name, 'pastry') !== false ||

        strpos($name, 'tart') !== false ||

        strpos($name, 'pie') !== false ||

        strpos($name, 'eclair') !== false ||

        strpos($category, 'pastry') !== false

    ) {

        return 'https\://images.unsplash.com/photo-1509365465985-25d11c17e812?w=300&h=220&fit=crop';

    }



    // Default bakery image

    return 'https\://images.unsplash.com/photo-1509440159596-0249088772ff?w=300&h=220&fit=crop';

}



$messages = [

    'stock_in_success' => 'Stock successfully added!',

    'stock_out_success' => 'Stock successfully deducted!',

    'minimum_updated' => 'Minimum stock level updated!'

];



$status = $_GET['status'] ?? '';

$todayLabel = date('l, F d, Y');



?>



\<!DOCTYPE html>

\<html lang="en">



\<head>



\<meta charset="UTF-8">

\<meta name="viewport" content="width=device-width, initial-scale=1.0">



\<title>Inventory Management | Bakery Management System\</title>



\<link rel="preconnect" href="https\://fonts.googleapis.com">

\<link rel="preconnect" href="https\://fonts.gstatic.com" crossorigin>



\<link href="https\://fonts.googleapis.com/css2?family=DM+Sans:wght\@400;500;600;700;800&family=Playfair+Display:wght\@600;700&display=swap" rel="stylesheet">



\<style>



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



    --green: #27834c;

    --red: #c64b43;



    --sidebar-width: 260px;

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



/* TOPBAR */



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

    flex-shrink: 0;

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

    overflow: hidden;



    color: white;

    background: linear-gradient(115deg, #59391f, #805633);

    border-radius: 15px;

}



.welcome-banner h2 {

    margin: 0 0 7px;

    font-family: 'Playfair Display', serif;

    font-size: 23px;

}



.welcome-banner p {

    color: #e6d7c8;

    font-size: 12px;

    line-height: 1.7;

    margin: 5px 0;

}



.banner-icon {

    font-size: 55px;

    opacity: .9;

}



/* NOTIFICATIONS */



.message {

    padding: 13px 16px;

    margin-bottom: 18px;

    border-radius: 9px;

    font-size: 12px;

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



/* STAT CARDS */



.stats-grid {

    display: grid;

    grid-template-columns: repeat(4, minmax(0, 1fr));

    gap: 16px;

}



.stat-card {

    min-width: 0;

    padding: 20px;

    background: white;

    border: 1px solid var(--border);

    border-radius: 13px;

    box-shadow: 0 3px 12px rgba(55,35,18,.035);

}



.stat-top {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 8px;

}



.stat-label {

    color: var(--muted);

    font-size: 11px;

    font-weight: 600;

}



.stat-icon {

    display: flex;

    align-items: center;

    justify-content: center;

    width: 36px;

    height: 36px;

    border-radius: 10px;

    font-size: 17px;

    flex-shrink: 0;

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



.stat-value {

    margin-top: 15px;

    color: var(--brown);

    font-size: 25px;

    font-weight: 800;

    overflow-wrap: anywhere;

}



.stat-note {

    margin-top: 5px;

    color: var(--muted);

    font-size: 10px;

}



/* PANELS */



.panel {

    min-width: 0;

    padding: 21px;

    margin-top: 20px;

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



.panel-header span {

    color: var(--muted);

    font-size: 10px;

}



.panel-description {

    margin: 6px 0 0;

    color: var(--muted);

    font-size: 11px;

    line-height: 1.6;

}



.panel-tag {

    padding: 6px 10px;

    color: var(--brown);

    background: #eee3d5;

    border-radius: 20px;

    font-size: 10px;

    font-weight: 700;

    white-space: nowrap;

}



/* CATEGORY FILTER */



.category-filter {

    margin-bottom: 20px;

}



.category-filter-title {

    margin: 0 0 12px;

    color: var(--muted);

    font-size: 11px;

    font-weight: 700;

}



.category-buttons {

    display: flex;

    flex-wrap: wrap;

    gap: 10px;

}



.category-button {

    display: flex;

    align-items: center;

    gap: 9px;



    min-width: 145px;

    padding: 10px 13px;



    color: var(--brown-dark);

    text-align: left;

    background: #fffdf9;

    border: 1px solid var(--border);

    border-radius: 11px;



    cursor: pointer;

    transition: .2s ease;

    font-family: inherit;

}



.category-button:hover {

    border-color: #c9a98f;

    transform: translateY(-2px);

}



.category-button.active {

    color: white;

    background: linear-gradient(135deg, #805735, #54351f);

    border-color: var(--brown-dark);

    box-shadow: 0 5px 13px rgba(75,46,29,.12);

}



.category-icon {

    display: grid;

    place-items: center;



    width: 36px;

    height: 36px;

    flex: 0 0 36px;



    background: #f1e5d9;

    border-radius: 10px;

    font-size: 18px;

}



.category-button.active .category-icon {

    background: rgba(255,255,255,.16);

}



.category-button-text {

    display: flex;

    flex-direction: column;

    gap: 3px;

    min-width: 0;

}



.category-name {

    font-size: 11px;

    font-weight: 800;

    overflow-wrap: anywhere;

}



.category-count {

    color: #9a8879;

    font-size: 10px;

}



.category-button.active .category-count {

    color: #f0dfd1;

}



.filter-result {

    margin: 12px 0 0;

    color: var(--muted);

    font-size: 10px;

}



/* TABLES */



.table-wrapper {

    width: 100%;

    overflow-x: auto;

}



table {

    width: 100%;

    min-width: 1100px;

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

    white-space: nowrap;

}



td {

    color: var(--text);

}



tbody tr:last-child td {

    border-bottom: none;

}



tbody tr.inventory-row:hover {

    background: #fffcf8;

}



td:first-child {

    color: var(--brown-deep);

    font-weight: 700;

}



.empty {

    padding: 22px 10px;

    color: var(--muted);

    font-size: 12px;

    text-align: center;

}



/* PRODUCT IMAGE */



.product-cell {

    display: flex;

    align-items: center;

    gap: 12px;

    min-width: 210px;

}



.product-image {

    width: 58px;

    height: 58px;

    flex: 0 0 58px;

    object-fit: cover;

    border-radius: 10px;

    border: 1px solid var(--border);

    background: #f5eee6;

    box-shadow: 0 2px 6px rgba(55,35,18,.08);

}



.product-name {

    color: var(--brown-deep);

    font-size: 12px;

    font-weight: 800;

    line-height: 1.5;

}



/* STOCK STATUS */



.low-stock,

.normal-stock,

.movement-badge {

    display: inline-block;

    padding: 5px 9px;

    border-radius: 20px;

    font-size: 9px;

    font-weight: 700;

    white-space: nowrap;

}



.low-stock,

.movement-out {

    color: #a83e32;

    background: #fce8e6;

}



.normal-stock,

.movement-in {

    color: #34734a;

    background: #e3f2e8;

}



/* STOCK ACTION FORMS */



.action-form {

    display: flex;

    flex-wrap: wrap;

    align-items: center;

    gap: 7px;



    width: 100%;

    min-width: 0;



    margin: 0 0 8px;

    padding: 8px;



    background: #fcfaf7;

    border: 1px solid #f1e9e1;

    border-radius: 9px;

}



.action-form:last-child {

    margin-bottom: 0;

}



.action-form input,

.action-form select {

    min-height: 38px;

    padding: 8px 10px;



    color: var(--text);

    background: white;



    border: 1px solid #e6ddd4;

    border-radius: 7px;



    font-family: inherit;

    font-size: 11px;

    outline: none;

}



.action-form input:focus,

.action-form select:focus {

    border-color: #a77c5b;

    box-shadow: 0 0 0 3px rgba(167,124,91,.12);

}



.action-form input[name="quantity"],

.action-form input[name="minimum_stock"] {

    flex: 0 0 115px;

    width: 115px;

    min-width: 100px;

}



.action-form select[name="remarks_choice"] {

    flex: 1 1 150px;

    width: 150px;

    min-width: 125px;

}



.action-form input[name="custom_remarks"] {

    flex: 1 1 150px;

    width: 150px;

    min-width: 125px;

}



.action-form button {

    min-height: 38px;

    padding: 8px 11px;



    border: 0;

    border-radius: 7px;



    color: white;

    font-family: inherit;

    font-size: 10px;

    font-weight: 800;

    white-space: nowrap;



    cursor: pointer;

    transition: .2s ease;

}



.action-form button:hover {

    filter: brightness(.93);

    transform: translateY(-1px);

}



.action-form button:disabled {

    opacity: .5;

    cursor: not-allowed;

    transform: none;

}



.stock-in-btn {

    background: #27834c;

}



.stock-out-btn {

    background: #c64b43;

}



.minimum-btn {

    background: var(--brown);

}



.custom-remark {

    display: none;

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



    .stats-grid {

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



    .stats-grid {

        gap: 10px;

    }



    .stat-card {

        padding: 16px;

    }



    .stat-value {

        font-size: 27px;

    }



    .panel {

        padding: 18px;

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



    .stats-grid {

        grid-template-columns: 1fr;

        gap: 10px;

    }



    .stat-card {

        padding: 17px;

    }



    .stat-value {

        font-size: 28px;

    }



    .panel {

        padding: 14px;

    }



    .section-heading {

        align-items: flex-start;

        flex-direction: column;

    }



    .panel-header {

        align-items: flex-start;

    }



    .category-buttons {

        display: grid;

        grid-template-columns: repeat(2, minmax(0, 1fr));

        gap: 8px;

    }



    .category-button {

        min-width: 0;

        padding: 8px;

        gap: 7px;

    }



    .category-icon {

        width: 31px;

        height: 31px;

        flex-basis: 31px;

        font-size: 16px;

    }



    .category-name {

        font-size: 10px;

    }



    .category-count {

        font-size: 9px;

    }

}



@media (max-width: 360px) {



    .nav-menu,

    .category-buttons,

    .stats-grid {

        grid-template-columns: 1fr;

    }



    .welcome-banner h2 {

        font-size: 18px;

    }

}



\</style>



\</head>



\<body>



\<!-- SIDEBAR -->



\<aside class="sidebar">



    \<div class="brand">



        \<span class="brand-icon">🥐\</span>



        \<div>

            \<h2 class="brand-name">Bakery System\</h2>

            \<p class="brand-subtitle">SALES & INVENTORY\</p>

        \</div>



    \</div>



    \<p class="menu-label">MAIN MENU\</p>



    \<nav class="nav-menu">



        \<a href="dashboard.php" class="nav-link">

            \<span class="nav-icon">▦\</span>

            Dashboard

        \</a>



        \<a href="products.php" class="nav-link">

            \<span class="nav-icon">📦\</span>

            Products

        \</a>



        <?php if ($isAdmin): ?>

            \<a href="categories.php" class="nav-link">

                \<span class="nav-icon">▤\</span>

                Categories

            \</a>

        <?php endif; ?>



        \<a href="inventory.php" class="nav-link active" aria-current="page">

            \<span class="nav-icon">📦\</span>

            Inventory

        \</a>



        \<a href="sales.php" class="nav-link">

            \<span class="nav-icon">🛒\</span>

            Sales / POS

        \</a>



        <?php if ($isAdmin): ?>



            \<a href="reports.php" class="nav-link">

                \<span class="nav-icon">📊\</span>

                Reports

            \</a>



            \<a href="user_management.php" class="nav-link">

                \<span class="nav-icon">👥\</span>

                User Management

            \</a>



        <?php endif; ?>



    \</nav>



    \<div class="sidebar-bottom">



        \<a href="../logout.php" class="logout-link">

            \<span class="nav-icon">↪\</span>

            Logout

        \</a>



    \</div>



\</aside>





\<!-- MAIN CONTENT -->



\<main class="main">



\<div class="content-container">



    \<!-- TOPBAR -->



    \<header class="topbar">



        \<div class="page-title">



            \<h1>Inventory Management\</h1>



            \<p>

                Manage your bakery stock and monitor product availability.

            \</p>



        \</div>



        \<div class="user-info">



            \<div class="user-avatar">

                \<?= e($initial) ?>

            \</div>



            \<div class="user-details">



                \<strong>\<?= $userName ?>\</strong>



                \<span>

                    \<span class="role-badge">

                        \<?= e($displayRole) ?>

                    \</span>

                \</span>



            \</div>



        \</div>



    \</header>





    \<!-- WELCOME BANNER -->



    \<section class="welcome-banner">



        \<div>



            \<h2>Inventory Overview 📦\</h2>



            \<p>

                Monitor your bakery products, manage stock levels,

                and keep track of every inventory movement.

                \<br>

                \<?= e($todayLabel) ?>

            \</p>



        \</div>



        \<div class="banner-icon">🥐\</div>



    \</section>





    \<!-- NOTIFICATIONS -->



    <?php if (isset($messages[$status])): ?>



        \<div class="message success">

            ✓ \<?= e($messages[$status]) ?>

        \</div>



    <?php endif; ?>



    <?php if (isset($_GET['error'])): ?>



        \<div class="message error">

            ⚠ \<?= e($_GET['error']) ?>

        \</div>



    <?php endif; ?>





    \<!-- INVENTORY OVERVIEW -->



    \<div class="section-heading">



        \<h2>Inventory Overview\</h2>



        \<span>Current stock monitoring\</span>



    \</div>



    \<section class="stats-grid">



        \<div class="stat-card">



            \<div class="stat-top">



                \<span class="stat-label">

                    Total Products

                \</span>



                \<span class="stat-icon icon-brown">

                    🧁

                \</span>



            \</div>



            \<div class="stat-value">

                \<?= number_format($total_products) ?>

            \</div>



            \<div class="stat-note">

                Registered bakery products

            \</div>



        \</div>





        \<div class="stat-card">



            \<div class="stat-top">



                \<span class="stat-label">

                    Available Stock

                \</span>



                \<span class="stat-icon icon-green">

                    📦

                \</span>



            \</div>



            \<div class="stat-value">

                \<?= number_format($total_stock) ?>

            \</div>



            \<div class="stat-note">

                Total units currently in inventory

            \</div>



        \</div>





        \<div class="stat-card">



            \<div class="stat-top">



                \<span class="stat-label">

                    Low Stock Products

                \</span>



                \<span class="stat-icon icon-orange">

                    ⚠️

                \</span>



            \</div>



            \<div class="stat-value">

                \<?= number_format($low_stock_count) ?>

            \</div>



            \<div class="stat-note">

                Products at or below minimum stock

            \</div>



        \</div>





        \<div class="stat-card">



            \<div class="stat-top">



                \<span class="stat-label">

                    Out of Stock

                \</span>



                \<span class="stat-icon icon-red">

                    ❌

                \</span>



            \</div>



            \<div class="stat-value">

                \<?= number_format($out_of_stock_count) ?>

            \</div>



            \<div class="stat-note">

                Products with no available stock

            \</div>



        \</div>



    \</section>





    \<!-- INVENTORY STATUS -->



    \<div class="section-heading">



        \<h2>Inventory Status\</h2>



        \<span>Product availability summary\</span>



    \</div>



    \<section class="stats-grid">



        \<div class="stat-card">



            \<div class="stat-top">



                \<span class="stat-label">

                    Inventory Availability

                \</span>



                \<span class="stat-icon icon-brown">

                    📋

                \</span>



            \</div>



            \<div class="stat-value">

                \<?= number_format($available_percentage, 1) ?>%

            \</div>



            \<div class="stat-note">

                Products with available stock

            \</div>



        \</div>



    \</section>





    \<!-- PRODUCT INVENTORY -->



    \<section class="panel">



        \<div class="panel-header">



            \<div>



                \<h2>Product Inventory\</h2>



                \<p class="panel-description">

                    Select a category, then update the stock for each product.

                \</p>



            \</div>



            \<span class="panel-tag">

                \<?= number_format($total_products) ?> items tracked

            \</span>



        \</div>





        \<!-- CATEGORY FILTER -->



        \<div class="category-filter">



            \<p class="category-filter-title">

                CHOOSE A PRODUCT CATEGORY

            \</p>



            \<div class="category-buttons" id="categoryButtons">



                \<button

                    type="button"

                    class="category-button active"

                    data-category="all"

                    aria-pressed="true">



                    \<span class="category-icon">🛍️\</span>



                    \<span class="category-button-text">



                        \<span class="category-name">

                            All Products

                        \</span>



                        \<span class="category-count">

                            \<?= $total_products ?> products

                        \</span>



                    \</span>



                \</button>





                <?php foreach ($categories as $category): ?>



                    <?php



                    $category_id = (string)$category['category_id'];



                    $category_count = $category_counts[$category_id] ?? 0;



                    $category_name_lower = strtolower($category['category_name']);



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



                    \<button

                        type="button"

                        class="category-button"

                        data-category="\<?= (int)$category['category_id'] ?>"

                        aria-pressed="false">



                        \<span class="category-icon">

                            \<?= $category_icon ?>

                        \</span>



                        \<span class="category-button-text">



                            \<span class="category-name">

                                \<?= e($category['category_name']) ?>

                            \</span>



                            \<span class="category-count">

                                \<?= $category_count ?>

                                \<?= $category_count === 1 ? 'product' : 'products' ?>

                            \</span>



                        \</span>



                    \</button>



                <?php endforeach; ?>





                <?php if (isset($category_counts['uncategorized'])): ?>



                    \<button

                        type="button"

                        class="category-button"

                        data-category="uncategorized"

                        aria-pressed="false">



                        \<span class="category-icon">📦\</span>



                        \<span class="category-button-text">



                            \<span class="category-name">

                                Uncategorized

                            \</span>



                            \<span class="category-count">

                                \<?= $category_counts['uncategorized'] ?> products

                            \</span>



                        \</span>



                    \</button>



                <?php endif; ?>



            \</div>



            \<p class="filter-result" id="filterResult">

                Showing all \<?= $total_products ?> products.

            \</p>



        \</div>





        \<!-- INVENTORY TABLE -->



        \<div class="table-wrapper">



            \<table>



                \<thead>



                    \<tr>

                        \<th>Product\</th>

                        \<th>Category\</th>

                        \<th>Current Stock\</th>

                        \<th>Minimum Stock\</th>

                        \<th>Status\</th>

                        \<th>Manage Stock\</th>

                    \</tr>



                \</thead>



                \<tbody>



                <?php if (empty($products)): ?>



                    \<tr>

                        \<td colspan="6" class="empty">

                            No products found. Add products first.

                        \</td>

                    \</tr>



                <?php else: ?>



                    <?php foreach ($products as $product): ?>



                        <?php



                        $quantity = (int)$product['quantity'];

                        $minimum = (int)$product['minimum_stock'];



                        $is_low = $quantity <= $minimum;



                        $category_data = $product['category_id'] === null

                            ? 'uncategorized'

                            : (string)$product['category_id'];



                        $product_image = getProductImage(

                            $product['product_name'],

                            $product['category_name'] ?? ''

                        );



                        ?>



                        \<tr

                            class="inventory-row"

                            data-category="\<?= e($category_data) ?>">



                            \<!-- PRODUCT NAME WITH AUTOMATIC IMAGE -->



                            \<td>



                                \<div class="product-cell">



                                    \<img

                                        src="\<?= e($product_image) ?>"

                                        alt="\<?= e($product['product_name']) ?>"

                                        class="product-image"

                                        loading="lazy"

                                        onerror="this.onerror=null;this.src='http&#x73;*:*//images.unsplash.com/photo-1509440159596-0249088772ff?w=300&h=220&fit=crop';">



                                    \<span class="product-name">

                                        \<?= e($product['product_name']) ?>

                                    \</span>



                                \</div>



                            \</td>



                            \<td>

                                \<?= e($product['category_name'] ?? 'Uncategorized') ?>

                            \</td>



                            \<td>

                                \<strong>\<?= $quantity ?>\</strong>

                            \</td>



                            \<td>

                                \<?= $minimum ?>

                            \</td>



                            \<td>



                                <?php if ($is_low): ?>



                                    \<span class="low-stock">

                                        \<?= $quantity === 0 ? 'Out of Stock' : 'Low Stock' ?>

                                    \</span>



                                <?php else: ?>



                                    \<span class="normal-stock">

                                        In Stock

                                    \</span>



                                <?php endif; ?>



                            \</td>



                            \<td>



                                \<!-- STOCK IN -->



                                \<form method="POST" class="action-form">



                                    \<input

                                        type="hidden"

                                        name="product_id"

                                        value="\<?= (int)$product['product_id'] ?>">



                                    \<input

                                        type="hidden"

                                        name="action"

                                        value="stock_in">



                                    \<input

                                        type="number"

                                        name="quantity"

                                        min="1"

                                        placeholder="Quantity"

                                        aria-label="Stock in quantity for \<?= e($product['product_name']) ?>"

                                        required>



                                    \<select

                                        name="remarks_choice"

                                        class="remark-select"

                                        required>



                                        \<option value="">Select Remark\</option>

                                        \<option value="New Production">New Production\</option>

                                        \<option value="Supplier Delivery">Supplier Delivery\</option>

                                        \<option value="Restocking">Restocking\</option>

                                        \<option value="Customer Return">Customer Return\</option>

                                        \<option value="Stock Adjustment">Stock Adjustment\</option>

                                        \<option value="Custom Remark">Custom Remark\</option>



                                    \</select>



                                    \<input

                                        type="text"

                                        name="custom_remarks"

                                        class="custom-remark"

                                        placeholder="Enter your remark..."

                                        aria-label="Custom stock in remark">



                                    \<button type="submit" class="stock-in-btn">

                                        + Stock In

                                    \</button>



                                \</form>





                                \<!-- STOCK OUT -->



                                \<form method="POST" class="action-form">



                                    \<input

                                        type="hidden"

                                        name="product_id"

                                        value="\<?= (int)$product['product_id'] ?>">



                                    \<input

                                        type="hidden"

                                        name="action"

                                        value="stock_out">



                                    \<input

                                        type="number"

                                        name="quantity"

                                        min="1"

                                        max="\<?= $quantity ?>"

                                        placeholder="Quantity"

                                        aria-label="Stock out quantity for \<?= e($product['product_name']) ?>"

                                        required

                                        \<?= $quantity < 1 ? 'disabled' : '' ?>>



                                    \<select

                                        name="remarks_choice"

                                        class="remark-select"

                                        required>



                                        \<option value="">Select Remark\</option>

                                        \<option value="Sold Products">Sold Products\</option>

                                        \<option value="Damaged Products">Damaged Products\</option>

                                        \<option value="Expired Products">Expired Products\</option>

                                        \<option value="Production Use">Production Use\</option>

                                        \<option value="Returned to Supplier">Returned to Supplier\</option>

                                        \<option value="Stock Adjustment">Stock Adjustment\</option>

                                        \<option value="Custom Remark">Custom Remark\</option>



                                    \</select>



                                    \<input

                                        type="text"

                                        name="custom_remarks"

                                        class="custom-remark"

                                        placeholder="Enter your remark..."

                                        aria-label="Custom stock out remark">



                                    \<button

                                        type="submit"

                                        class="stock-out-btn"

                                        \<?= $quantity < 1 ? 'disabled' : '' ?>>



                                        − Stock Out



                                    \</button>



                                \</form>





                                \<!-- SET MINIMUM STOCK -->



                                \<form method="POST" class="action-form">



                                    \<input

                                        type="hidden"

                                        name="product_id"

                                        value="\<?= (int)$product['product_id'] ?>">



                                    \<input

                                        type="hidden"

                                        name="action"

                                        value="set_minimum">



                                    \<input

                                        type="number"

                                        name="minimum_stock"

                                        min="0"

                                        value="\<?= $minimum ?>"

                                        aria-label="Minimum stock for \<?= e($product['product_name']) ?>"

                                        required>



                                    \<button type="submit" class="minimum-btn">

                                        Set Minimum

                                    \</button>



                                \</form>



                            \</td>



                        \</tr>



                    <?php endforeach; ?>



                <?php endif; ?>





                \<tr class="no-filter-results" style="display:none;">



                    \<td colspan="6" class="empty">

                        No products found in this category.

                    \</td>



                \</tr>



                \</tbody>



            \</table>



        \</div>



    \</section>





    \<!-- RECENT STOCK MOVEMENTS -->



    \<section class="panel">



        \<div class="panel-header">



            \<div>



                \<h2>Recent Stock Movements\</h2>



                \<p class="panel-description">

                    A record of the latest inventory changes.

                \</p>



            \</div>



            \<span class="panel-tag">

                Latest 20 records

            \</span>



        \</div>





        \<div class="table-wrapper">



            \<table>



                \<thead>



                    \<tr>

                        \<th>Date\</th>

                        \<th>Product\</th>

                        \<th>Movement\</th>

                        \<th>Quantity\</th>

                        \<th>Remarks\</th>

                        \<th>Processed By\</th>

                    \</tr>



                \</thead>



                \<tbody>



                <?php if (empty($movements)): ?>



                    \<tr>

                        \<td colspan="6" class="empty">

                            No stock movements recorded yet.

                        \</td>

                    \</tr>



                <?php else: ?>



                    <?php foreach ($movements as $movement): ?>



                        <?php



                        $movement_class = $movement['movement_type'] === 'stock_in'

                            ? 'movement-in'

                            : 'movement-out';



                        ?>



                        \<tr>



                            \<td>

                                \<?= e(date(

                                    'M d, Y h:i A',

                                    strtotime($movement['movement_date'])

                                )) ?>

                            \</td>



                            \<td>

                                \<?= e($movement['product_name']) ?>

                            \</td>



                            \<td>



                                \<span class="movement-badge \<?= $movement_class ?>">

                                    \<?= e(ucwords(str_replace(

                                        '_',

                                        ' ',

                                        $movement['movement_type']

                                    ))) ?>

                                \</span>



                            \</td>



                            \<td>

                                \<?= (int)$movement['quantity'] ?>

                            \</td>



                            \<td>

                                \<?= e($movement['remarks'] ?: '-') ?>

                            \</td>



                            \<td>

                                \<?= e($movement['full_name']) ?>

                            \</td>



                        \</tr>



                    <?php endforeach; ?>



                <?php endif; ?>



                \</tbody>



            \</table>



        \</div>



    \</section>





    \<!-- FOOTER -->



    \<footer class="footer">



        Bakery Sales and Stock Monitoring System

        &copy; \<?= date('Y') ?>. All rights reserved.



    \</footer>



\</div>

\</main>





\<script>



// CATEGORY FILTER



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



            const matches =

                selectedCategory === 'all' ||

                row\.dataset.category === selectedCategory;



            row\.style.display = matches ? '' : 'none';



            if (matches) {

                visibleCount++;

            }



        });



        if (noFilterResults) {



            noFilterResults.style.display =

                visibleCount === 0 ? 'table-row' : 'none';



        }



        const selectedName =

            this.querySelector('.category-name').textContent.trim();



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





// CUSTOM REMARK INPUT



document.querySelectorAll('.remark-select').forEach(function(select) {



    function toggleCustomRemark() {



        const form = select.closest('form');



        const customInput = form.querySelector('.custom-remark');



        if (select.value === 'Custom Remark') {



            customInput.style.display = 'block';

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





// CONFIRM STOCK ACTIONS



document.querySelectorAll('.action-form').forEach(function(form) {



    form.addEventListener('submit', function(event) {



        const action = form.querySelector('input[name="action"]').value;



        if (action === 'stock_in') {



            if (!confirm('Are you sure you want to add this stock?')) {

                event.preventDefault();

            }



        }



        if (action === 'stock_out') {



            if (!confirm('Are you sure you want to deduct this stock?')) {

                event.preventDefault();

            }



        }



    });



});



\</script>



\</body>

\</html>