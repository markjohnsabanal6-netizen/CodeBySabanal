
<?php
// Set the same timezone as the Windows computer (Pacific Time).
date_default_timezone_set('America/Los_Angeles');

session_start();
require_once '../config/database.php';

// Synchronize MySQL timezone with Pacific Time.
$pacificOffset = (
    new DateTime(
        'now',
        new DateTimeZone('America/Los_Angeles')
    )
)->format('P');

$conn->exec(
    "SET time_zone = " . $conn->quote($pacificOffset)
);

// Protect dashboard from unauthorized access.
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Logged-in user information.
$name = htmlspecialchars(
    $_SESSION['full_name'] ?? 'User',
    ENT_QUOTES,
    'UTF-8'
);

$role = $_SESSION['role'] ?? 'staff';
$isAdmin = ($role === 'admin');

// FETCH LOGGED-IN USER PROFILE PICTURE
$userId = (int) $_SESSION['user_id'];

$profileStmt = $conn->prepare("
    SELECT profile_picture
    FROM users
    WHERE user_id = :user_id
    LIMIT 1
");
$profileStmt->execute(['user_id' => $userId]);
$profilePicture = $profileStmt->fetchColumn();

$profileImage = !empty($profilePicture)
    ? '../' . $profilePicture
    : '';

$profileInitial = strtoupper(
    substr($_SESSION['full_name'] ?? 'U', 0, 1)
);

// Dashboard statistics.
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

// Recent sales transactions.
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

// Low stock products.
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

// Sales for the last 7 days.
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

// Convert weekly sales into an associative array.
$salesByDate = [];

foreach ($weeklySales as $sale) {
    $salesByDate[$sale['sale_day']] = (float) $sale['daily_total'];
}

// Generate all 7 dates.
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

// Prevent division by zero.
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

body {
    margin: 0;
    min-height: 100vh;
    font-family: 'DM Sans', Arial, sans-serif;
    background: var(--cream);
    color: var(--text);
}

/* =================================
   SIDEBAR - EXACT CATEGORIES DESIGN
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
    overflow: hidden;
}

.user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.user-info {
    text-decoration: none;
    color: inherit;
    cursor: pointer;
}

.user-info:hover .user-details strong {
    color: var(--brown);
}

.sidebar-profile-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 10px;
    margin-bottom: 12px;
    color: #fff;
    text-decoration: none;
    border-radius: 12px;
    transition: .2s ease;
}

.sidebar-profile-link:hover {
    background: rgba(255,255,255,.10);
}

.sidebar-profile-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #e9d8c5;
    color: var(--brown);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 800;
    overflow: hidden;
    flex-shrink: 0;
}

.sidebar-profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.sidebar-profile-details {
    min-width: 0;
}

.sidebar-profile-details strong {
    display: block;
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sidebar-profile-details span {
    display: block;
    margin-top: 3px;
    color: #d3b69c;
    font-size: 10px;
    text-transform: capitalize;
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
}

.icon-brown { background: #f2e7db; }
.icon-green { background: #e3f2e8; }
.icon-orange { background: #fff0db; }
.icon-red { background: #fce8e6; }

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

/* QUICK ACTIONS */

.quick-actions {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 13px;
}

.action-card {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 17px;

    color: var(--text);
    background: white;
    border: 1px solid var(--border);
    border-radius: 11px;
    text-decoration: none;
    transition: .2s;
}

.action-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(55,35,18,.08);
    border-color: #d7b999;
}

.action-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    background: #f5eee6;
    border-radius: 10px;
    font-size: 19px;
}

.action-card strong {
    display: block;
    font-size: 12px;
}

.action-card small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 10px;
}

/* CONTENT GRID */

.content-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 18px;
    margin-top: 25px;
}

.panel {
    min-width: 0;
    padding: 21px;
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
    color: var(--brown);
    font-size: 14px;
}

.panel-header span {
    color: var(--muted);
    font-size: 10px;
}

/* SALES CHART */

.chart {
    display: flex;
    align-items: flex-end;
    justify-content: space-around;
    gap: 12px;
    height: 190px;
    padding-top: 15px;
    border-bottom: 1px solid var(--border);
}

.chart-column {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    min-width: 0;
    height: 100%;
}

.bar-area {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    width: 100%;
    height: 145px;
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
    padding-bottom: 9px;
    color: var(--muted);
    font-size: 10px;
}

.chart-legend {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 13px;
    color: var(--muted);
    font-size: 10px;
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
    padding-bottom: 0;
    border-bottom: none;
}

.stock-name {
    font-size: 12px;
    font-weight: 600;
}

.stock-minimum {
    margin-top: 4px;
    color: var(--muted);
    font-size: 10px;
}

.stock-quantity {
    padding: 6px 9px;
    color: #a83e32;
    background: #fce8e6;
    border-radius: 7px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.empty-message {
    padding: 22px 10px;
    color: var(--muted);
    font-size: 12px;
    text-align: center;
}

/* RECENT SALES TABLE */

.table-panel {
    padding: 21px;
    margin-top: 20px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
}

.table-wrapper {
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
}

td {
    color: var(--text);
}

td.amount {
    color: var(--brown);
    font-weight: 700;
}

.sale-id {
    color: var(--brown);
    font-weight: 700;
}

.status {
    display: inline-block;
    padding: 5px 9px;
    color: #34734a;
    background: #e3f2e8;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 700;
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

/* =================================
   RESPONSIVE - SAME AS CATEGORIES
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

    .panel,
    .table-panel {
        padding: 18px;
    }

    .content-grid {
        grid-template-columns: 1fr;
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

    .quick-actions {
        grid-template-columns: 1fr;
    }

    .panel,
    .table-panel {
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

    .stats-grid {
        grid-template-columns: 1fr;
    }
}

</style>
</head>

<body>

<!-- SIDEBAR - EXACT CATEGORIES STRUCTURE -->

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

        <a href="dashboard.php" class="nav-link active" aria-current="page">
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

        <a href="profile.php" class="sidebar-profile-link" title="My Profile">

            <div class="sidebar-profile-avatar">
                <?php if (!empty($profileImage)): ?>
                    <img
                        src="<?= htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8') ?>"
                        alt="Profile picture"
                    >
                <?php else: ?>
                    <?= htmlspecialchars($profileInitial, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>

            <div class="sidebar-profile-details">
                <strong><?= $name ?></strong>
                <span><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?> · My Profile</span>
            </div>

        </a>

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
            <h1>Dashboard</h1>
            <p>Here's what's happening in your bakery today.</p>
        </div>

        <a href="profile.php" class="user-info" title="My Profile">

            <div class="user-avatar">
                <?php if (!empty($profileImage)): ?>
                    <img
                        src="<?= htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8') ?>"
                        alt="Profile picture"
                    >
                <?php else: ?>
                    <?= htmlspecialchars($profileInitial, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>

            <div class="user-details">

                <strong><?= $name ?></strong>

                <span>
                    <span class="role-badge">
                        <?= $isAdmin ? 'ADMINISTRATOR' : 'STAFF' ?>
                    </span>
                </span>

            </div>

        </a>

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

                <strong>
                    ₱<?= number_format(
                        array_sum(array_column($chartData, 'amount')),
                        2
                    ) ?>
                </strong>
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
                                    <?= htmlspecialchars(
                                        $item['product_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </div>

                                <div class="stock-minimum">
                                    Minimum stock:
                                    <?= number_format(
                                        (int)$item['minimum_stock']
                                    ) ?>
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
                    <div style="font-size: 28px; margin-bottom: 8px;">
                        ✅
                    </div>

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
                                ₱<?= number_format(
                                    (float)$sale['total_amount'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    date(
                                        'M d, Y h:i A',
                                        strtotime($sale['sale_date'])
                                    ),
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

    <!-- FOOTER -->

    <footer class="footer">
        Bakery Sales and Stock Monitoring System
        &copy; <?= date('Y') ?>. All rights reserved.
    </footer>

</div>
</main>

</body>
</html>
