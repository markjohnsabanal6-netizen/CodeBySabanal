<?php
session_start();
require_once '../config/database.php';

// CHECK LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// USER INFORMATION
$name = htmlspecialchars(
    $_SESSION['full_name'] ?? 'User',
    ENT_QUOTES,
    'UTF-8'
);

$role = $_SESSION['role'] ?? 'staff';
$isAdmin = ($role === 'admin');

$message = '';
$messageType = 'success';

$formName = '';
$formDescription = '';

// NOTIFICATIONS AFTER REDIRECT
if (isset($_GET['added'])) {
    $message = "Category added successfully!";
} elseif (isset($_GET['updated'])) {
    $message = "Category updated successfully!";
} elseif (isset($_GET['deleted'])) {
    $message = "Category deleted successfully!";
}

// ADD CATEGORY
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'add'
) {
    $formName = trim($_POST['category_name'] ?? '');
    $formDescription = trim($_POST['description'] ?? '');

    if ($formName === '') {
        $message = "Category name is required!";
        $messageType = 'error';
    } else {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO categories (category_name, description)
                 VALUES (?, ?)"
            );

            $stmt->execute([$formName, $formDescription]);

            header("Location: categories.php?added=1");
            exit;

        } catch (PDOException $e) {
            $message = "Unable to add category. It may already exist.";
            $messageType = 'error';
        }
    }
}

// UPDATE CATEGORY
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update'
) {
    $id = (int)($_POST['category_id'] ?? 0);
    $formName = trim($_POST['category_name'] ?? '');
    $formDescription = trim($_POST['description'] ?? '');

    if ($id <= 0 || $formName === '') {
        $message = "Invalid category information!";
        $messageType = 'error';
    } else {
        try {
            $stmt = $conn->prepare(
                "UPDATE categories
                 SET category_name = ?,
                     description = ?
                 WHERE category_id = ?"
            );

            $stmt->execute([$formName, $formDescription, $id]);

            header("Location: categories.php?updated=1");
            exit;

        } catch (PDOException $e) {
            $message = "Unable to update category.";
            $messageType = 'error';
        }
    }
}

// DELETE CATEGORY
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    $id = (int)($_POST['category_id'] ?? 0);

    try {
        if ($id <= 0) {
            throw new Exception("Invalid category.");
        }

        $check = $conn->prepare(
            "SELECT COUNT(*) FROM products WHERE category_id = ?"
        );

        $check->execute([$id]);

        if ((int)$check->fetchColumn() > 0) {
            $message = "Cannot delete: This category is assigned to products.";
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare(
                "DELETE FROM categories WHERE category_id = ?"
            );

            $stmt->execute([$id]);

            header("Location: categories.php?deleted=1");
            exit;
        }

    } catch (Exception $e) {
        $message = "Unable to delete category.";
        $messageType = 'error';
    }
}

// GET CATEGORY FOR EDITING
$editCategory = null;

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];

    $stmt = $conn->prepare(
        "SELECT * FROM categories WHERE category_id = ?"
    );

    $stmt->execute([$id]);
    $editCategory = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editCategory) {
        $message = "Category not found.";
        $messageType = 'error';
    }
}

// PRESERVE FORM VALUES WHEN VALIDATION FAILS
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update' &&
    isset($_POST['category_id'])
) {
    $editId = (int)$_POST['category_id'];

    $stmt = $conn->prepare(
        "SELECT * FROM categories WHERE category_id = ?"
    );

    $stmt->execute([$editId]);
    $editCategory = $stmt->fetch(PDO::FETCH_ASSOC);
}

// GET ALL CATEGORIES WITH PRODUCT COUNTS
$stmt = $conn->query(
    "SELECT
        c.*,
        (
            SELECT COUNT(*)
            FROM products p
            WHERE p.category_id = c.category_id
        ) AS product_count
     FROM categories c
     ORDER BY c.category_id DESC"
);

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CATEGORY STATISTICS
$totalCategories = count($categories);
$totalAssignedProducts = 0;
$emptyCategories = 0;

foreach ($categories as $category) {
    $count = (int)$category['product_count'];

    $totalAssignedProducts += $count;

    if ($count === 0) {
        $emptyCategories++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Categories | Bakery Management System</title>

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
   SIDEBAR - EXACT INVENTORY DESIGN
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

/* SECTION HEADING */

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
    grid-template-columns: repeat(3, minmax(0, 1fr));
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
    padding: 23px;
    margin-bottom: 20px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(55,35,18,.035);
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
    font-size: 15px;
}

.panel-header p {
    margin-top: 4px;
    color: var(--muted);
    font-size: 11px;
}

/* NOTIFICATIONS */

.message {
    padding: 14px 17px;
    margin-bottom: 18px;
    border-radius: 9px;
    font-size: 13px;
    transition: opacity .5s ease;
}

.success-message {
    color: #34734a;
    background: #e3f2e8;
    border: 1px solid #b9e2c1;
}

.error-message {
    color: #a12626;
    background: #fce8e6;
    border: 1px solid #f2baba;
}

/* FORM */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.form-group {
    min-width: 0;
}

.form-group.full {
    grid-column: 1 / -1;
}

label {
    display: block;
    margin-bottom: 8px;
    color: var(--text);
    font-size: 12px;
    font-weight: 700;
}

input,
textarea {
    width: 100%;
    padding: 12px 14px;
    color: var(--text);
    background: white;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: inherit;
    font-size: 13px;
    outline: none;
    transition: .2s;
}

input:focus,
textarea:focus {
    border-color: var(--brown-light);
    box-shadow: 0 0 0 3px rgba(130,85,47,.09);
}

textarea {
    min-height: 95px;
    resize: vertical;
}

.form-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 9px;
    margin-top: 18px;
}

/* BUTTONS */

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 11px 17px;

    border: none;
    border-radius: 8px;
    text-decoration: none;
    cursor: pointer;

    font-family: inherit;
    font-size: 12px;
    font-weight: 700;
    transition: .2s;
}

.primary-btn {
    color: white;
    background: var(--brown);
}

.primary-btn:hover {
    background: var(--brown-light);
    transform: translateY(-1px);
}

.cancel-btn {
    color: var(--brown);
    background: #eee8e0;
}

.cancel-btn:hover {
    background: #e4d9cc;
}

.edit-btn {
    color: #2878b5;
    background: #e6eff8;
}

.edit-btn:hover {
    background: #d5e5f5;
}

.delete-btn {
    color: #b83232;
    background: #fce8e6;
}

.delete-btn:hover {
    background: #f8d6d3;
}

/* CATEGORY COUNT */

.category-count {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 12px;
    color: var(--brown);
    background: #f2e7db;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

/* TABLE */

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 650px;
    border-collapse: collapse;
}

th,
td {
    padding: 14px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
}

th {
    color: var(--muted);
    background: #faf8f5;
    font-size: 11px;
    font-weight: 700;
}

td {
    color: var(--text);
}

.category-name {
    color: var(--brown);
    font-weight: 700;
}

.category-id {
    color: var(--muted);
    font-weight: 600;
}

.product-count {
    display: inline-block;
    padding: 6px 10px;
    color: var(--brown);
    background: #f2e7db;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}

.no-products {
    color: #9a672d;
    background: #fff0db;
}

.description {
    max-width: 300px;
    color: var(--muted);
    line-height: 1.5;
}

.actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}

.actions form {
    margin: 0;
}

.actions .btn {
    padding: 8px 11px;
    font-size: 11px;
}

.empty {
    padding: 30px !important;
    color: var(--muted);
    text-align: center;
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
   RESPONSIVE - SAME AS INVENTORY
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

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full {
        grid-column: auto;
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

<!-- SIDEBAR - SAME AS INVENTORY -->

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
            <a href="categories.php" class="nav-link active" aria-current="page">
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

            <h1>Category Management</h1>

            <p>
                Organize and manage your bakery product categories.
            </p>

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

            <h2>Category Management 🧁</h2>

            <p>
                Manage your bakery product categories and keep
                your products properly organized.
                <br>
                <?= date('l, F d, Y') ?>
            </p>

        </div>

        <div class="banner-icon">🥐</div>

    </section>

    <!-- NOTIFICATIONS -->

    <?php if ($message): ?>

        <div
            class="message <?= $messageType === 'success'
                ? 'success-message'
                : 'error-message' ?>"
            id="categoryMessage"
        >
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php endif; ?>

    <!-- CATEGORY OVERVIEW -->

    <div class="section-heading">

        <h2>Category Overview</h2>

        <span>Summary of your bakery categories</span>

    </div>

    <section class="stats-grid">

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Total Categories</span>
                <span class="stat-icon icon-brown">▤</span>
            </div>

            <div class="stat-value">
                <?= number_format($totalCategories) ?>
            </div>

            <div class="stat-note">
                Registered product categories
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Assigned Products</span>
                <span class="stat-icon icon-green">🍞</span>
            </div>

            <div class="stat-value">
                <?= number_format($totalAssignedProducts) ?>
            </div>

            <div class="stat-note">
                Products under all categories
            </div>

        </div>

        <div class="stat-card">

            <div class="stat-top">
                <span class="stat-label">Empty Categories</span>
                <span class="stat-icon icon-orange">📦</span>
            </div>

            <div class="stat-value">
                <?= number_format($emptyCategories) ?>
            </div>

            <div class="stat-note">
                Categories without products
            </div>

        </div>

    </section>

    <!-- CATEGORY FORM -->

    <div class="section-heading">

        <h2>
            <?= $editCategory ? 'Edit Category' : 'Add New Category' ?>
        </h2>

        <span>Category Information</span>

    </div>

    <section class="panel">

        <div class="panel-header">

            <div>

                <h2>
                    <?= $editCategory
                        ? 'Update Category Details'
                        : 'Register a New Category' ?>
                </h2>

                <p>
                    <?= $editCategory
                        ? 'Modify the information of your selected category.'
                        : 'Enter the category details to organize your bakery products.' ?>
                </p>

            </div>

            <span style="font-size:25px;">🧁</span>

        </div>

        <?php if ($editCategory): ?>

            <form method="POST">

                <input type="hidden" name="action" value="update">

                <input
                    type="hidden"
                    name="category_id"
                    value="<?= (int)$editCategory['category_id'] ?>"
                >

                <div class="form-grid">

                    <div class="form-group full">

                        <label for="category_name">Category Name</label>

                        <input
                            type="text"
                            id="category_name"
                            name="category_name"
                            value="<?= htmlspecialchars(
                                $formName !== ''
                                    ? $formName
                                    : $editCategory['category_name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            placeholder="Example: Bread"
                            maxlength="100"
                            required
                        >

                    </div>

                    <div class="form-group full">

                        <label for="description">Description</label>

                        <textarea
                            id="description"
                            name="description"
                            placeholder="Enter category description"
                            rows="3"
                        ><?= htmlspecialchars(
                            $formDescription !== ''
                                ? $formDescription
                                : ($editCategory['description'] ?? ''),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></textarea>

                    </div>

                </div>

                <div class="form-actions">

                    <button type="submit" class="btn primary-btn">
                        ✓ Update Category
                    </button>

                    <a href="categories.php" class="btn cancel-btn">
                        Cancel
                    </a>

                </div>

            </form>

        <?php else: ?>

            <form method="POST">

                <input type="hidden" name="action" value="add">

                <div class="form-grid">

                    <div class="form-group full">

                        <label for="category_name">Category Name</label>

                        <input
                            type="text"
                            id="category_name"
                            name="category_name"
                            value="<?= htmlspecialchars(
                                $formName,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            placeholder="Example: Bread"
                            maxlength="100"
                            required
                        >

                    </div>

                    <div class="form-group full">

                        <label for="description">Description</label>

                        <textarea
                            id="description"
                            name="description"
                            placeholder="Enter category description"
                            rows="3"
                        ><?= htmlspecialchars(
                            $formDescription,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></textarea>

                    </div>

                </div>

                <div class="form-actions">

                    <button type="submit" class="btn primary-btn">
                        + Add Category
                    </button>

                </div>

            </form>

        <?php endif; ?>

    </section>

    <!-- CATEGORY LIST -->

    <div class="section-heading">

        <h2>Category List</h2>

        <span>Manage your registered categories</span>

    </div>

    <section class="panel">

        <div class="panel-header">

            <div>

                <h2>Registered Categories</h2>

                <p>
                    View, edit, and manage your bakery categories.
                </p>

            </div>

            <span class="category-count">
                ▤ <?= number_format($totalCategories) ?> Categories
            </span>

        </div>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>Category Name</th>
                        <th>Description</th>
                        <th>Products</th>
                        <th>Actions</th>
                    </tr>

                </thead>

                <tbody>

                <?php if ($categories): ?>

                    <?php foreach ($categories as $category): ?>

                        <tr>

                            <td class="category-id">
                                #<?= (int)$category['category_id'] ?>
                            </td>

                            <td class="category-name">
                                <?= htmlspecialchars(
                                    $category['category_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td class="description">
                                <?= htmlspecialchars(
                                    $category['description'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>

                                <span class="product-count <?= (int)$category['product_count'] === 0
                                    ? 'no-products'
                                    : '' ?>">

                                    <?= number_format((int)$category['product_count']) ?>

                                    <?= (int)$category['product_count'] === 1
                                        ? 'Product'
                                        : 'Products' ?>

                                </span>

                            </td>

                            <td>

                                <div class="actions">

                                    <a
                                        class="btn edit-btn"
                                        href="categories.php?edit=<?= (int)$category['category_id'] ?>"
                                    >
                                        ✎ Edit
                                    </a>

                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Are you sure you want to delete this category?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="category_id"
                                            value="<?= (int)$category['category_id'] ?>"
                                        >

                                        <button type="submit" class="btn delete-btn">
                                            🗑 Delete
                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="5" class="empty">
                            No categories found. Add your first category above.
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
const message = document.getElementById("categoryMessage");

if (message) {

    setTimeout(() => {

        message.style.opacity = "0";

        setTimeout(() => {
            message.remove();
        }, 500);

    }, 3000);

}

</script>

</body>
</html>