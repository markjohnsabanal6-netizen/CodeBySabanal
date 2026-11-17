
<?php
// Set the same timezone as the Dashboard.
date_default_timezone_set('America/Los_Angeles');

session_start();
require_once '../config/database.php';

// Synchronize MySQL timezone with PHP.
$pacificOffset = (
    new DateTime('now', new DateTimeZone('America/Los_Angeles'))
)->format('P');

$conn->exec("SET time_zone = " . $conn->quote($pacificOffset));

// Protect page.
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

// Only admin can access Reports.
if (!$isAdmin) {
    header("Location: dashboard.php");
    exit;
}

// DATE FILTER
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$start = DateTime::createFromFormat('!Y-m-d', $start_date);
$end = DateTime::createFromFormat('!Y-m-d', $end_date);

if (
    !$start || $start->format('Y-m-d') !== $start_date ||
    !$end || $end->format('Y-m-d') !== $end_date ||
    $start_date > $end_date
) {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-d');
}

// SALES SUMMARY
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_transactions,
        COALESCE(SUM(total_amount), 0) AS total_sales
    FROM sales
    WHERE sale_date >= :start_date
      AND sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$sales_summary = $stmt->fetch(PDO::FETCH_ASSOC);

// TOTAL ITEMS SOLD
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(sd.quantity), 0)
    FROM sale_details sd
    INNER JOIN sales s ON sd.sale_id = s.sale_id
    WHERE s.sale_date >= :start_date
      AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$total_items_sold = $stmt->fetchColumn();

// TOTAL PRODUCTS
$total_products = $conn->query("
    SELECT COUNT(*) FROM products
")->fetchColumn();

// BEST-SELLING PRODUCTS
$stmt = $conn->prepare("
    SELECT
        COALESCE(p.product_name, 'Deleted Product') AS product_name,
        SUM(sd.quantity) AS total_quantity,
        SUM(sd.subtotal) AS total_revenue
    FROM sale_details sd
    INNER JOIN sales s ON sd.sale_id = s.sale_id
    LEFT JOIN products p ON sd.product_id = p.product_id
    WHERE s.sale_date >= :start_date
      AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
    GROUP BY sd.product_id, p.product_name
    ORDER BY total_quantity DESC, total_revenue DESC
    LIMIT 5
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$best_sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// DAILY SALES
$stmt = $conn->prepare("
    SELECT
        DATE(sale_date) AS sale_day,
        COUNT(*) AS transactions,
        SUM(total_amount) AS daily_sales
    FROM sales
    WHERE sale_date >= :start_date
      AND sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
    GROUP BY DATE(sale_date)
    ORDER BY sale_day DESC
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$daily_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CHART CALCULATIONS
$max_daily_sales = 0;

foreach ($daily_sales as $day) {
    $max_daily_sales = max(
        $max_daily_sales,
        (float)$day['daily_sales']
    );
}

// INVENTORY SUMMARY
$inventory_summary = $conn->query("
    SELECT
        COUNT(*) AS total_stock_items,
        COALESCE(SUM(CASE WHEN quantity > minimum_stock THEN 1 ELSE 0 END), 0) AS in_stock,
        COALESCE(SUM(CASE WHEN quantity > 0 AND quantity <= minimum_stock THEN 1 ELSE 0 END), 0) AS low_stock,
        COALESCE(SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock
    FROM inventory
")->fetch(PDO::FETCH_ASSOC);

// INVENTORY DETAILS
$inventory_details = $conn->query("
    SELECT
        p.product_name,
        i.quantity,
        i.minimum_stock,
        CASE
            WHEN i.quantity <= 0 THEN 'Out of Stock'
            WHEN i.quantity <= i.minimum_stock THEN 'Low Stock'
            ELSE 'In Stock'
        END AS stock_status
    FROM inventory i
    INNER JOIN products p ON i.product_id = p.product_id
    ORDER BY p.product_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// SALES TRANSACTIONS
$stmt = $conn->prepare("
    SELECT
        s.sale_id,
        s.sale_date,
        u.full_name AS cashier,
        s.total_amount,
        s.payment_amount,
        s.change_amount
    FROM sales s
    INNER JOIN users u ON s.user_id = u.user_id
    WHERE s.sale_date >= :start_date
      AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
    ORDER BY s.sale_date DESC
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$sales_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SAFE OUTPUT
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reports | Bakery Management System</title>

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

    --green: #27834c;
    --red: #c64b43;
    --orange: #c58a35;

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

a {
    text-decoration: none;
}

/* =================================
   SIDEBAR - MATCH DASHBOARD
================================= */

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

/* =================================
   MAIN CONTENT
================================= */

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

/* CARDS */

.card {
    min-width: 0;
    padding: 21px;
    margin-bottom: 20px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(55,35,18,.035);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
}

.card-header h2 {
    margin: 0;
    color: var(--brown);
    font-size: 14px;
}

.card-header span {
    color: var(--muted);
    font-size: 10px;
}

.card-title {
    color: var(--brown-dark);
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 18px;
}

.card-subtitle {
    margin-top: 5px;
    color: var(--muted);
    font-size: 11px;
}

.section-tag {
    padding: 7px 11px;
    color: var(--brown);
    background: #f7f0e9;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
}

/* FILTERS */

.filter-form {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 13px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.filter-group label {
    color: #6f6258;
    font-size: 11px;
    font-weight: 800;
}

input[type="date"] {
    min-height: 43px;
    min-width: 165px;
    padding: 10px 12px;

    border: 1px solid #e4ddd5;
    border-radius: 9px;

    background: white;
    color: #444;

    font-family: 'DM Sans', sans-serif;
    font-size: 12px;
}

input[type="date"]:focus {
    outline: none;
    border-color: var(--brown-light);
    box-shadow: 0 0 0 3px rgba(128,87,53,.10);
}

/* BUTTONS */

.btn {
    min-height: 43px;

    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 7px;

    padding: 10px 15px;

    border: none;
    border-radius: 9px;

    color: white;
    font-family: 'DM Sans', sans-serif;
    font-size: 11px;
    font-weight: 800;

    cursor: pointer;
    transition: .2s ease;
    white-space: nowrap;
}

.btn:hover {
    transform: translateY(-2px);
    filter: brightness(.94);
}

.filter-btn {
    background: var(--brown);
}

.csv-btn {
    background: #27834c;
}

.excel-btn {
    background: #217346;
}

.print-btn {
    background: #77716b;
}

/* SUMMARY CARDS */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.summary-card {
    min-width: 0;
    padding: 20px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(55,35,18,.035);
}

.summary-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.summary-label {
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
}

.summary-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    font-size: 17px;
}

.accent-brown { background: #f2e7db; }
.accent-green { background: #e3f2e8; }
.accent-orange { background: #fff0db; }
.accent-purple { background: #eee9f8; }

.summary-value {
    margin-top: 15px;
    color: var(--brown);
    font-size: 25px;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.summary-caption {
    margin-top: 5px;
    color: var(--muted);
    font-size: 10px;
}

/* ANALYTICS GRID */

.analytics-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 18px;
    margin-top: 25px;
}

.analytics-grid .card {
    margin-bottom: 0;
}

/* DAILY SALES CHART */

.chart-list {
    display: flex;
    flex-direction: column;
    gap: 17px;
}

.chart-item {
    display: grid;
    grid-template-columns: 85px minmax(0, 1fr) 95px;
    align-items: center;
    gap: 12px;
}

.chart-date {
    color: #6f6258;
    font-size: 10px;
    font-weight: 700;
}

.chart-track {
    height: 11px;
    overflow: hidden;
    background: #f1ece6;
    border-radius: 20px;
}

.chart-bar {
    height: 100%;
    min-width: 0;
    background: linear-gradient(90deg, #805735, #bd936e);
    border-radius: 20px;
}

.chart-amount {
    color: var(--brown-dark);
    font-size: 10px;
    font-weight: 800;
    text-align: right;
}

.chart-empty,
.best-empty {
    padding: 28px 10px;
    color: var(--muted);
    font-size: 12px;
    text-align: center;
}

/* BEST SELLERS */

.best-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.best-item {
    display: flex;
    align-items: center;
    gap: 12px;
}

.best-rank {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 31px;
    height: 31px;
    flex-shrink: 0;
    color: var(--brown);
    background: #f4ece4;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 800;
}

.best-info {
    flex: 1;
    min-width: 0;
}

.best-name {
    margin-bottom: 5px;
    color: #4b3b30;
    font-size: 11px;
    font-weight: 800;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.best-meta {
    color: #95877c;
    font-size: 10px;
}

.best-revenue {
    color: var(--brown-dark);
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.note {
    margin-top: 15px;
    padding: 12px 14px;
    color: #83766b;
    background: #faf7f3;
    border-left: 3px solid #b58b66;
    border-radius: 7px;
    font-size: 10px;
    line-height: 1.7;
}

/* TABLES */

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 550px;
    border-collapse: collapse;
}

th,
td {
    padding: 13px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    font-size: 11px;
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

tbody tr:hover {
    background: #fcfaf7;
}

.amount-cell {
    color: var(--brown);
    font-weight: 700;
}

.sale-id {
    color: var(--brown);
    font-weight: 700;
}

.empty {
    padding: 28px;
    color: var(--muted);
    text-align: center;
}

/* STATUS BADGES */

.status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
}

.status::before {
    content: "";
    width: 6px;
    height: 6px;
    background: currentColor;
    border-radius: 50%;
}

.in-stock {
    color: #24723c;
    background: #e0f2e5;
}

.low-stock {
    color: #956313;
    background: #fff1d5;
}

.out-stock {
    color: #a33c3c;
    background: #fbe3e3;
}

/* INVENTORY SUMMARY */

.inventory-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}

.inventory-stat {
    padding: 16px;
    background: #fbf9f6;
    border: 1px solid #f0e9e1;
    border-radius: 11px;
}

.inventory-stat span {
    display: block;
    margin-bottom: 8px;
    color: var(--muted);
    font-size: 10px;
    font-weight: 700;
}

.inventory-stat strong {
    color: var(--brown-dark);
    font-size: 21px;
    font-weight: 800;
}

/* REPORT PERIOD */

.report-period {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 17px 20px;
    margin-top: 20px;
    color: var(--muted);
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    font-size: 11px;
}

.report-period strong {
    color: var(--brown-dark);
}

.footer {
    margin-top: 30px;
    padding: 15px 0;
    color: var(--muted);
    border-top: 1px solid var(--border);
    font-size: 10px;
    text-align: center;
}

/* =================================
   RESPONSIVE - SAME AS DASHBOARD
================================= */

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

    .summary-grid {
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

    .analytics-grid {
        grid-template-columns: 1fr;
    }

    .summary-grid {
        gap: 10px;
    }

    .summary-card {
        padding: 16px;
    }

    .summary-value {
        font-size: 27px;
    }

    .card {
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

    .summary-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .summary-card {
        padding: 17px;
    }

    .summary-value {
        font-size: 28px;
    }

    .inventory-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .filter-form {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-group,
    .filter-group input,
    .filter-form .btn {
        width: 100%;
    }

    input[type="date"] {
        min-width: 0;
    }

    .chart-item {
        grid-template-columns: 70px minmax(0, 1fr) 78px;
        gap: 7px;
    }

    .chart-date,
    .chart-amount {
        font-size: 9px;
    }

    .card {
        padding: 14px;
    }

    .section-heading {
        align-items: flex-start;
        flex-direction: column;
    }
}

@media (max-width: 360px) {
    .nav-menu {
        grid-template-columns: 1fr;
    }

    .summary-grid,
    .inventory-summary {
        grid-template-columns: 1fr;
    }

    .main {
        padding: 15px 10px;
    }
}

/* PRINT */

@media print {
    @page {
        margin: 12mm;
    }

    body {
        background: white;
        color: #222;
    }

    .sidebar,
    .no-print {
        display: none !important;
    }

    .main {
        width: 100%;
        margin: 0;
        padding: 0;
    }

    .topbar {
        display: none;
    }

    .welcome-banner {
        padding: 15px;
        margin-bottom: 15px;
        background: #54351f !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }

    .card,
    .summary-card,
    .report-period {
        box-shadow: none;
        border: 1px solid #ddd;
        break-inside: avoid;
    }

    .summary-grid {
        grid-template-columns: repeat(4, 1fr);
    }

    .analytics-grid {
        grid-template-columns: 1.5fr 1fr;
    }

    .inventory-summary {
        grid-template-columns: repeat(4, 1fr);
    }

    table {
        min-width: 0;
    }

    th,
    td {
        padding: 7px;
        font-size: 9px;
    }

    .chart-bar,
    .summary-icon,
    .status {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
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

        <a href="sales.php" class="nav-link">
            <span class="nav-icon">🛒</span>
            Sales / POS
        </a>

        <?php if ($isAdmin): ?>

            <a href="reports.php" class="nav-link active" aria-current="page">
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
            <h1>Reports & Analytics</h1>
            <p>Review your bakery sales performance and inventory records.</p>
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
            <h2>Business Reports & Analytics 📊</h2>

            <p>
                Track your bakery sales, monitor inventory,
                and review your business performance.
                <br>
                <?= date('l, F d, Y') ?>
            </p>
        </div>

        <div class="banner-icon">🥐</div>

    </section>

    <!-- REPORT FILTERS -->

    <section class="card no-print">

        <div class="card-header">

            <div>
                <h2>Report Filters</h2>
                <span>Select a date range to generate your sales report.</span>
            </div>

            <span class="section-tag">DATE RANGE</span>

        </div>

        <form method="GET" class="filter-form">

            <div class="filter-group">
                <label for="start_date">Start Date</label>

                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= e($start_date) ?>"
                    max="<?= date('Y-m-d') ?>"
                    required
                >
            </div>

            <div class="filter-group">
                <label for="end_date">End Date</label>

                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= e($end_date) ?>"
                    max="<?= date('Y-m-d') ?>"
                    required
                >
            </div>

            <button type="submit" class="btn filter-btn">
                🔍 Apply Filter
            </button>

            <a
                href="export_sales.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                class="btn csv-btn"
            >
                📄 Export CSV
            </a>

            <a
                href="export_sales_excel.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                class="btn excel-btn"
            >
                📊 Export Excel
            </a>

            <button
                type="button"
                class="btn print-btn"
                onclick="window.print()"
            >
                🖨 Print Report
            </button>

        </form>

    </section>

    <!-- SALES OVERVIEW -->

    <div class="section-heading">
        <h2>Sales Overview</h2>
        <span>Based on selected report period</span>
    </div>

    <section class="summary-grid">

        <div class="summary-card">
            <div class="summary-top">
                <span class="summary-label">Total Sales</span>
                <span class="summary-icon accent-green">₱</span>
            </div>

            <div class="summary-value">
                ₱<?= number_format((float)$sales_summary['total_sales'], 2) ?>
            </div>

            <div class="summary-caption">
                Revenue for selected period
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-top">
                <span class="summary-label">Total Transactions</span>
                <span class="summary-icon accent-brown">🧾</span>
            </div>

            <div class="summary-value">
                <?= number_format((int)$sales_summary['total_transactions']) ?>
            </div>

            <div class="summary-caption">
                Completed sales transactions
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-top">
                <span class="summary-label">Items Sold</span>
                <span class="summary-icon accent-orange">🛍️</span>
            </div>

            <div class="summary-value">
                <?= number_format((int)$total_items_sold) ?>
            </div>

            <div class="summary-caption">
                Total quantity sold
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-top">
                <span class="summary-label">Total Products</span>
                <span class="summary-icon accent-purple">🍞</span>
            </div>

            <div class="summary-value">
                <?= number_format((int)$total_products) ?>
            </div>

            <div class="summary-caption">
                Registered bakery products
            </div>
        </div>

    </section>

    <!-- SALES ANALYTICS -->

    <div class="section-heading">
        <h2>Sales Analytics</h2>
        <span>Daily performance and top-selling products</span>
    </div>

    <section class="analytics-grid">

        <!-- DAILY SALES CHART -->

        <div class="card">

            <div class="card-header">
                <h2>Daily Sales Performance</h2>
                <span>Sales chart</span>
            </div>

            <div class="chart-list">

                <?php if ($daily_sales): ?>

                    <?php foreach ($daily_sales as $day): ?>

                        <?php
                        $percentage = $max_daily_sales > 0
                            ? ((float)$day['daily_sales'] / $max_daily_sales) * 100
                            : 0;
                        ?>

                        <div class="chart-item">

                            <span class="chart-date">
                                <?= e(date('M d', strtotime($day['sale_day']))) ?>
                            </span>

                            <div class="chart-track">
                                <div
                                    class="chart-bar"
                                    style="width: <?= number_format($percentage, 2, '.', '') ?>%;"
                                ></div>
                            </div>

                            <span class="chart-amount">
                                ₱<?= number_format((float)$day['daily_sales'], 2) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="chart-empty">
                        No sales data available for this date range.
                    </div>

                <?php endif; ?>

            </div>

            <p class="note">
                The chart compares each day's sales against the highest
                recorded daily sales within the selected period.
            </p>

        </div>

        <!-- BEST SELLERS -->

        <div class="card">

            <div class="card-header">
                <h2>Top Selling Products</h2>
                <span>Top 5 products</span>
            </div>

            <div class="best-list">

                <?php if ($best_sellers): ?>

                    <?php foreach ($best_sellers as $index => $product): ?>

                        <div class="best-item">

                            <div class="best-rank">
                                <?= $index + 1 ?>
                            </div>

                            <div class="best-info">

                                <div class="best-name">
                                    <?= e($product['product_name']) ?>
                                </div>

                                <div class="best-meta">
                                    <?= number_format((int)$product['total_quantity']) ?> items sold
                                </div>

                            </div>

                            <div class="best-revenue">
                                ₱<?= number_format((float)$product['total_revenue'], 2) ?>
                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="best-empty">
                        No best-selling products found.
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </section>

    <!-- DAILY SALES SUMMARY -->

    <div class="section-heading">
        <h2>Daily Sales Summary</h2>
        <span>Detailed daily transaction records</span>
    </div>

    <section class="card">

        <div class="card-header">
            <h2>Daily Sales Records</h2>
            <span>Daily totals</span>
        </div>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transactions</th>
                        <th>Total Sales</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($daily_sales): ?>

                    <?php foreach ($daily_sales as $day): ?>

                        <tr>
                            <td>
                                <?= e(date('F d, Y', strtotime($day['sale_day']))) ?>
                            </td>

                            <td>
                                <?= number_format((int)$day['transactions']) ?>
                            </td>

                            <td class="amount-cell">
                                ₱<?= number_format((float)$day['daily_sales'], 2) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="3" class="empty">
                            No daily sales records found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- INVENTORY OVERVIEW -->

    <div class="section-heading">
        <h2>Inventory Overview</h2>
        <span>Current stock monitoring</span>
    </div>

    <section class="card">

        <div class="card-header">
            <h2>Current Inventory Status</h2>
            <span>Live inventory</span>
        </div>

        <div class="inventory-summary">

            <div class="inventory-stat">
                <span>Total Inventory Items</span>
                <strong>
                    <?= number_format((int)$inventory_summary['total_stock_items']) ?>
                </strong>
            </div>

            <div class="inventory-stat">
                <span>In Stock</span>
                <strong style="color:#27834c;">
                    <?= number_format((int)$inventory_summary['in_stock']) ?>
                </strong>
            </div>

            <div class="inventory-stat">
                <span>Low Stock</span>
                <strong style="color:#c58a35;">
                    <?= number_format((int)$inventory_summary['low_stock']) ?>
                </strong>
            </div>

            <div class="inventory-stat">
                <span>Out of Stock</span>
                <strong style="color:#c64b43;">
                    <?= number_format((int)$inventory_summary['out_of_stock']) ?>
                </strong>
            </div>

        </div>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Current Stock</th>
                        <th>Minimum Stock</th>
                        <th>Stock Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($inventory_details): ?>

                    <?php foreach ($inventory_details as $item): ?>

                        <?php
                        $status_class = 'in-stock';

                        if ($item['stock_status'] === 'Low Stock') {
                            $status_class = 'low-stock';
                        } elseif ($item['stock_status'] === 'Out of Stock') {
                            $status_class = 'out-stock';
                        }
                        ?>

                        <tr>

                            <td><?= e($item['product_name']) ?></td>

                            <td>
                                <?= number_format((int)$item['quantity']) ?>
                            </td>

                            <td>
                                <?= number_format((int)$item['minimum_stock']) ?>
                            </td>

                            <td>
                                <span class="status <?= $status_class ?>">
                                    <?= e($item['stock_status']) ?>
                                </span>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="4" class="empty">
                            No inventory records found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p class="note">
            Inventory status displays the current stock condition.
            It does not represent historical stock during the selected report period.
        </p>

    </section>

    <!-- SALES TRANSACTION RECORDS -->

    <div class="section-heading">
        <h2>Sales Transactions</h2>
        <span>Individual sales and payment details</span>
    </div>

    <section class="card">

        <div class="card-header">
            <h2>Sales Transaction Records</h2>
            <span><?= number_format(count($sales_records)) ?> records</span>
        </div>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Sale ID</th>
                        <th>Date & Time</th>
                        <th>Cashier</th>
                        <th>Total Amount</th>
                        <th>Payment</th>
                        <th>Change</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($sales_records): ?>

                    <?php foreach ($sales_records as $sale): ?>

                        <tr>

                            <td class="sale-id">
                                #<?= (int)$sale['sale_id'] ?>
                            </td>

                            <td>
                                <?= e(date('M d, Y h:i A', strtotime($sale['sale_date']))) ?>
                            </td>

                            <td>
                                <?= e($sale['cashier']) ?>
                            </td>

                            <td class="amount-cell">
                                ₱<?= number_format((float)$sale['total_amount'], 2) ?>
                            </td>

                            <td>
                                ₱<?= number_format((float)$sale['payment_amount'], 2) ?>
                            </td>

                            <td>
                                ₱<?= number_format((float)$sale['change_amount'], 2) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No sales transactions found for this date range.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- REPORT PERIOD -->

    <div class="report-period">

        <div>
            <strong>Report Period:</strong>
            <?= e(date('F d, Y', strtotime($start_date))) ?>
            to
            <?= e(date('F d, Y', strtotime($end_date))) ?>
        </div>

        <div>
            <strong>Generated:</strong>
            <?= date('F d, Y | h:i A') ?>
        </div>

    </div>

    <!-- FOOTER -->

    <footer class="footer">
        Bakery Sales and Stock Monitoring System
        &copy; <?= date('Y') ?>. All rights reserved.
    </footer>

</div>
</main>

</body>
</html>
