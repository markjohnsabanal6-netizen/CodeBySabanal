
<?php
session_start();
require_once '../config/database.php';

// Protect dashboard from unauthorized access
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Logged-in user information
$name = htmlspecialchars($_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$role = $_SESSION['role'] ?? 'staff';
$isAdmin = ($role === 'admin');

// Dashboard statistics
$totalProducts = (int) $conn->query("
    SELECT COUNT(*) FROM products
")->fetchColumn();

$todaySales = (float) $conn->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM sales
    WHERE DATE(sale_date) = CURDATE()
")->fetchColumn();

$monthlySales = (float) $conn->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM sales
    WHERE YEAR(sale_date) = YEAR(CURDATE())
    AND MONTH(sale_date) = MONTH(CURDATE())
")->fetchColumn();

$todayTransactions = (int) $conn->query("
    SELECT COUNT(*)
    FROM sales
    WHERE DATE(sale_date) = CURDATE()
")->fetchColumn();

$totalStock = (int) $conn->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM inventory
")->fetchColumn();

$lowStock = (int) $conn->query("
    SELECT COUNT(*)
    FROM inventory
    WHERE quantity <= minimum_stock
    AND quantity > 0
")->fetchColumn();

$outOfStock = (int) $conn->query("
    SELECT COUNT(*)
    FROM inventory
    WHERE quantity <= 0
")->fetchColumn();

// Recent sales transactions
$recentSales = $conn->query("
    SELECT
        s.sale_id,
        s.total_amount,
        s.sale_date,
        u.full_name
    FROM sales s
    LEFT JOIN users u
        ON s.user_id = u.user_id
    ORDER BY s.sale_date DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// Low stock products
$lowStockItems = $conn->query("
    SELECT
        p.product_name,
        i.quantity,
        i.minimum_stock
    FROM inventory i
    INNER JOIN products p
        ON i.product_id = p.product_id
    WHERE i.quantity <= i.minimum_stock
    ORDER BY i.quantity ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Sales for the last 7 days
$weeklySales = $conn->query("
    SELECT
        DATE(sale_date) AS sale_day,
        SUM(total_amount) AS daily_total
    FROM sales
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      AND sale_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    GROUP BY DATE(sale_date)
    ORDER BY sale_day ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Convert weekly sales into an associative array
$salesByDate = [];

foreach ($weeklySales as $sale) {
    $salesByDate[$sale['sale_day']] = (float) $sale['daily_total'];
}

// Generate all 7 dates, including days with no sales
$chartData = [];
$chartMax = 0;

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));

    $amount = $salesByDate[$date] ?? 0;

    $chartData[] = [
        'label' => date('D', strtotime($date)),
        'date' => $date,
        'amount' => $amount
    ];

    if ($amount > $chartMax) {
        $chartMax = $amount;
    }
}

// Prevent division by zero
$chartMax = max($chartMax, 1);

?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Dashboard | Bakery Management System</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

:root {
    --brown: #59391f;
    --brown-light: #82552f;
    --cream: #f7f4ef;
    --text: #33291f;
    --muted: #8a8178;
    --border: #eee8e0;
    --white: #ffffff;
}

body {
    font-family: 'DM Sans', Arial, sans-serif;
    background: var(--cream);
    color: var(--text);
}

/* SIDEBAR */

.sidebar {
    width: 250px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: var(--brown);
    color: white;
    padding: 25px 17px;
    display: flex;
    flex-direction: column;
    z-index: 100;
}

.brand {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 5px 8px 30px;
}

.brand-icon {
    font-size: 29px;
}

.brand h2 {
    font-family: 'Playfair Display', serif;
    font-size: 19px;
}

.brand small {
    display: block;
    color: #d7c5b3;
    font-size: 10px;
    margin-top: 3px;
    letter-spacing: 1px;
}

.nav-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.5px;
    color: #c5ad98;
    padding: 12px 12px;
}

.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 14px;
    margin-bottom: 5px;
    color: #f5ece4;
    text-decoration: none;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 500;
    transition: .2s;
}

.sidebar a:hover,
.sidebar a.active {
    background: var(--brown-light);
    color: white;
}

.sidebar a.active {
    box-shadow: inset 3px 0 #f3c78f;
}

.nav-icon {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.sidebar-bottom {
    margin-top: auto;
    border-top: 1px solid rgba(255,255,255,.15);
    padding-top: 15px;
}

.logout {
    background: rgba(168, 62, 50, .8);
}

.logout:hover {
    background: #a83e32 !important;
}

/* MAIN CONTENT */

.main {
    margin-left: 250px;
    padding: 30px 35px;
    min-height: 100vh;
    max-width: 1700px;
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
    font-family: 'Playfair Display', serif;
    font-size: 29px;
    color: var(--brown);
}

.page-title p {
    font-size: 13px;
    color: var(--muted);
    margin-top: 5px;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar {
    width: 43px;
    height: 43px;
    border-radius: 50%;
    background: #e9d8c5;
    color: var(--brown);
    display: flex;
    justify-content: center;
    align-items: center;
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
    background: #eee3d5;
    color: var(--brown);
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
}

/* WELCOME BANNER */

.welcome-banner {
    background: linear-gradient(115deg, #59391f, #805633);
    color: white;
    padding: 27px 30px;
    border-radius: 15px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    overflow: hidden;
}

.welcome-banner h2 {
    font-family: 'Playfair Display', serif;
    font-size: 23px;
    margin-bottom: 7px;
}

.welcome-banner p {
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
    margin: 27px 0 15px;
    gap: 10px;
}

.section-heading h2 {
    font-size: 16px;
    color: var(--brown);
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
    background: white;
    padding: 20px;
    border-radius: 13px;
    border: 1px solid var(--border);
    box-shadow: 0 3px 12px rgba(55, 35, 18, .035);
    min-width: 0;
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
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
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

.stat-value {
    color: var(--brown);
    font-size: 25px;
    font-weight: 800;
    margin-top: 15px;
    overflow-wrap: anywhere;
}

.stat-note {
    font-size: 10px;
    color: var(--muted);
    margin-top: 5px;
}

/* QUICK ACTIONS */

.quick-actions {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 13px;
}

.action-card {
    background: white;
    border: 1px solid var(--border);
    padding: 17px;
    border-radius: 11px;
    text-decoration: none;
    color: var(--text);
    transition: .2s;
    display: flex;
    align-items: center;
    gap: 13px;
}

.action-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(55, 35, 18, .08);
    border-color: #d7b999;
}

.action-icon {
    width: 40px;
    height: 40px;
    background: #f5eee6;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
}

.action-card strong {
    font-size: 12px;
    display: block;
}

.action-card small {
    display: block;
    color: var(--muted);
    font-size: 10px;
    margin-top: 4px;
}

/* CONTENT GRID */

.content-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 18px;
    margin-top: 25px;
}

.panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 21px;
    min-width: 0;
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}

.panel-header h2 {
    color: var(--brown);
    font-size: 14px;
}

.panel-header span {
    font-size: 10px;
    color: var(--muted);
}

/* SALES CHART */

.chart {
    height: 190px;
    display: flex;
    align-items: flex-end;
    justify-content: space-around;
    gap: 12px;
    padding-top: 15px;
    border-bottom: 1px solid var(--border);
}

.chart-column {
    flex: 1;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    min-width: 0;
}

.bar-area {
    height: 145px;
    width: 100%;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.bar {
    width: 55%;
    max-width: 38px;
    min-height: 3px;
    background: #c9a47f;
    border-radius: 6px 6px 0 0;
    transition: .2s;
}

.bar:hover {
    background: var(--brown);
}

.bar.today {
    background: var(--brown);
}

.chart-day {
    font-size: 10px;
    color: var(--muted);
    padding-bottom: 9px;
}

.chart-legend {
    margin-top: 13px;
    font-size: 10px;
    color: var(--muted);
    display: flex;
    justify-content: space-between;
}

/* LOW STOCK */

.stock-list {
    display: flex;
    flex-direction: column;
    gap: 13px;
}

.stock-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
}

.stock-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.stock-name {
    font-size: 12px;
    font-weight: 600;
}

.stock-minimum {
    color: var(--muted);
    font-size: 10px;
    margin-top: 4px;
}

.stock-quantity {
    font-size: 11px;
    font-weight: 700;
    color: #a83e32;
    background: #fce8e6;
    padding: 6px 9px;
    border-radius: 7px;
    white-space: nowrap;
}

.empty-message {
    text-align: center;
    padding: 22px 10px;
    color: var(--muted);
    font-size: 12px;
}

/* RECENT SALES TABLE */

.table-panel {
    margin-top: 20px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 21px;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 550px;
}

th, td {
    text-align: left;
    padding: 13px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 11px;
}

th {
    color: var(--muted);
    font-weight: 700;
    background: #faf8f5;
}

td {
    color: var(--text);
}

td.amount {
    font-weight: 700;
    color: var(--brown);
}

.sale-id {
    color: var(--brown);
    font-weight: 700;
}

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #e3f2e8;
    color: #34734a;
    font-size: 9px;
    font-weight: 700;
}

/* FOOTER */

.footer {
    margin-top: 30px;
    padding: 15px 0;
    border-top: 1px solid var(--border);
    color: var(--muted);
    font-size: 10px;
    text-align: center;
}

/* RESPONSIVE */

@media (max-width: 1150px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .main {
        padding: 25px;
    }
}

@media (max-width: 850px) {
    .sidebar {
        width: 210px;
    }

    .main {
        margin-left: 210px;
    }

    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 650px) {
    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
        padding: 15px;
    }

    .brand {
        padding-bottom: 12px;
    }

    .sidebar .nav-label {
        display: none;
    }

    .sidebar nav {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .sidebar nav a {
        flex: 1 1 135px;
        margin: 0;
        padding: 11px;
    }

    .sidebar-bottom {
        margin-top: 12px;
    }

    .sidebar-bottom a {
        margin: 0;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }

    .topbar {
        align-items: flex-start;
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
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .stat-card {
        padding: 14px;
    }

    .stat-value {
        font-size: 20px;
    }

    .quick-actions {
        grid-template-columns: 1fr;
    }

    .panel,
    .table-panel {
        padding: 15px;
    }
}

@media (max-width: 370px) {
    .stats-grid {
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
            <h2>Bakery System</h2>
            <small>SALES & INVENTORY</small>
        </div>
    </div>

    <div class="nav-label">MAIN MENU</div>

    <nav>
        <a href="dashboard.php" class="active">
            <span class="nav-icon">▦</span>
            Dashboard
        </a>

        <a href="products.php">
            <span class="nav-icon">🍞</span>
            Products
        </a>

        <?php if ($isAdmin): ?>
        <a href="categories.php">
            <span class="nav-icon">▤</span>
            Categories
        </a>
        <?php endif; ?>

        <a href="inventory.php">
            <span class="nav-icon">📦</span>
            Inventory
        </a>

        <a href="sales.php">
            <span class="nav-icon">🛒</span>
            Sales / POS
        </a>

        <?php if ($isAdmin): ?>
        <a href="reports.php">
            <span class="nav-icon">📊</span>
            Reports
        </a>

        <a href="user_management.php">
            <span class="nav-icon">👥</span>
            User Management
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-bottom">
        <a href="../logout.php" class="logout">
            <span class="nav-icon">↪</span>
            Logout
        </a>
    </div>

</aside>

<!-- MAIN CONTENT -->

<main class="main">

    <!-- TOPBAR -->

    <header class="topbar">

        <div class="page-title">
            <h1>Dashboard</h1>
            <p>Here's what's happening in your bakery today.</p>
        </div>

        <div class="user-info">

            <div class="user-avatar">
                <?= strtoupper(substr($name, 0, 1)) ?>
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
            <h2>Welcome back, <?= $name ?>! 👋</h2>

            <p>
                Manage your bakery operations, monitor your inventory,
                and keep track of your daily sales.
                <br>
                <?= date('l, F d, Y') ?>
            </p>
        </div>

        <div class="banner-icon">🥐</div>

    </section>

    <!-- SALES OVERVIEW -->

    <div class="section-heading">
        <h2>Sales Overview</h2>
        <span>Updated from your sales records</span>
    </div>

    <section class="stats-grid">

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Today's Sales</span>
                <span class="stat-icon icon-green">₱</span>
            </div>

            <div class="stat-value">
                ₱<?= number_format($todaySales, 2) ?>
            </div>

            <div class="stat-note">
                Total revenue for today
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Monthly Sales</span>
                <span class="stat-icon icon-brown">📈</span>
            </div>

            <div class="stat-value">
                ₱<?= number_format($monthlySales, 2) ?>
            </div>

            <div class="stat-note">
                Sales this month
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Today's Transactions</span>
                <span class="stat-icon icon-orange">🧾</span>
            </div>

            <div class="stat-value">
                <?= number_format($todayTransactions) ?>
            </div>

            <div class="stat-note">
                Completed sales recorded today
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Total Products</span>
                <span class="stat-icon icon-brown">🍞</span>
            </div>

            <div class="stat-value">
                <?= number_format($totalProducts) ?>
            </div>

            <div class="stat-note">
                Registered bakery products
            </div>

        </div>

    </section>

    <!-- INVENTORY OVERVIEW -->

    <div class="section-heading">
        <h2>Inventory Overview</h2>
        <span>Current stock monitoring</span>
    </div>

    <section class="stats-grid">

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Available Stock</span>
                <span class="stat-icon icon-green">📦</span>
            </div>

            <div class="stat-value">
                <?= number_format($totalStock) ?>
            </div>

            <div class="stat-note">
                Total units currently in inventory
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Low Stock Products</span>
                <span class="stat-icon icon-orange">⚠️</span>
            </div>

            <div class="stat-value">
                <?= number_format($lowStock) ?>
            </div>

            <div class="stat-note">
                Products at or below minimum stock
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Out of Stock</span>
                <span class="stat-icon icon-red">❌</span>
            </div>

            <div class="stat-value">
                <?= number_format($outOfStock) ?>
            </div>

            <div class="stat-note">
                Products with no available stock
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Inventory Status</span>
                <span class="stat-icon icon-brown">📋</span>
            </div>

            <div class="stat-value">
                <?= $totalProducts > 0
                    ? number_format(($totalProducts - $outOfStock) / $totalProducts * 100, 1) . '%'
                    : '0%' ?>
            </div>

            <div class="stat-note">
                Products with available stock
            </div>

        </div>

    </section>

    <!-- QUICK ACTIONS -->

    <div class="section-heading">
        <h2>Quick Actions</h2>
        <span>Frequently used features</span>
    </div>

    <section class="quick-actions">

        <a href="sales.php" class="action-card">
            <div class="action-icon">🛒</div>
            <div>
                <strong>New Sale</strong>
                <small>Process a customer purchase</small>
            </div>
        </a>

        <a href="products.php" class="action-card">
            <div class="action-icon">🍞</div>
            <div>
                <strong>Manage Products</strong>
                <small>View and manage bakery products</small>
            </div>
        </a>

        <a href="inventory.php" class="action-card">
            <div class="action-icon">📦</div>
            <div>
                <strong>Manage Inventory</strong>
                <small>Monitor and update stock levels</small>
            </div>
        </a>

    </section>

    <!-- CHART AND LOW STOCK -->

    <section class="content-grid">

        <!-- SALES CHART -->

        <div class="panel">

            <div class="panel-header">
                <h2>Sales Performance</h2>
                <span>Last 7 days</span>
            </div>

            <div class="chart">

                <?php foreach ($chartData as $index => $day): ?>

                    <?php
                        $height = ($day['amount'] / $chartMax) * 100;
                        $isToday = ($day['date'] === date('Y-m-d'));
                    ?>

                    <div class="chart-column">

                        <div class="bar-area">

                            <div
                                class="bar <?= $isToday ? 'today' : '' ?>"
                                style="height: <?= max($height, 2) ?>%;"
                                title="<?= htmlspecialchars($day['date']) ?>: ₱<?= number_format($day['amount'], 2) ?>"
                            ></div>

                        </div>

                        <span class="chart-day">
                            <?= htmlspecialchars($day['label']) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

            <div class="chart-legend">
                <span>Daily sales revenue</span>
                <strong>₱<?= number_format(array_sum(array_column($chartData, 'amount')), 2) ?></strong>
            </div>

        </div>

        <!-- LOW STOCK ALERTS -->

        <div class="panel">

            <div class="panel-header">
                <h2>⚠️ Low Stock Alerts</h2>
                <span><?= count($lowStockItems) ?> shown</span>
            </div>

            <?php if (count($lowStockItems) > 0): ?>

                <div class="stock-list">

                    <?php foreach ($lowStockItems as $item): ?>

                        <div class="stock-item">

                            <div>
                                <div class="stock-name">
                                    <?= htmlspecialchars($item['product_name']) ?>
                                </div>

                                <div class="stock-minimum">
                                    Minimum stock:
                                    <?= number_format((int)$item['minimum_stock']) ?>
                                </div>
                            </div>

                            <div class="stock-quantity">
                                <?= (int)$item['quantity'] ?> left
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="empty-message">
                    <div style="font-size: 28px; margin-bottom: 8px;">✅</div>
                    All products have sufficient stock!
                </div>

            <?php endif; ?>

        </div>

    </section>

    <!-- RECENT TRANSACTIONS -->

    <section class="table-panel">

        <div class="panel-header">
            <h2>Recent Sales Transactions</h2>
            <span>Latest 6 transactions</span>
        </div>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>SALE ID</th>
                        <th>CASHIER</th>
                        <th>TOTAL AMOUNT</th>
                        <th>DATE & TIME</th>
                        <th>STATUS</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($recentSales) > 0): ?>

                    <?php foreach ($recentSales as $sale): ?>

                        <tr>

                            <td class="sale-id">
                                #<?= (int)$sale['sale_id'] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $sale['full_name'] ?? 'Unknown',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td class="amount">
                                ₱<?= number_format((float)$sale['total_amount'], 2) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    date('M d, Y h:i A', strtotime($sale['sale_date'])),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <span class="status">Recorded</span>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="5" class="empty-message">
                            No sales transactions recorded yet.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <footer class="footer">
        Bakery Sales and Stock Monitoring System
        &copy; <?= date('Y') ?>. All rights reserved.
    </footer>

</main>

</body>
</html>
