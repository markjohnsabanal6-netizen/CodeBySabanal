
<?php
session_start();
require_once '../config/database.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Admin-only access
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit("Access Denied. Admins only.");
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($message) {
    header("Location: user_management.php?message=" . urlencode($message));
    exit;
}

$error = "";

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($_SESSION['csrf_token'], $postedToken)
    ) {
        exit("Invalid request token.");
    }

    $action = $_POST['action'] ?? '';

    try {

        // ADD USER
        if ($action === 'add') {

            $full_name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'staff';

            if ($full_name === '' || $username === '' || $password === '') {
                throw new Exception("Please complete all required fields.");
            }

            if (!in_array($role, ['admin', 'staff'], true)) {
                throw new Exception("Invalid user role.");
            }

            if (strlen($password) < 8) {
                throw new Exception("Password must be at least 8 characters.");
            }

            $check = $conn->prepare("
                SELECT user_id FROM users WHERE username = ?
            ");
            $check->execute([$username]);

            if ($check->fetch()) {
                throw new Exception("Username already exists.");
            }

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare("
                INSERT INTO users
                (full_name, username, password, role, status)
                VALUES (?, ?, ?, ?, 'active')
            ");

            $stmt->execute([
                $full_name,
                $username,
                $hashedPassword,
                $role
            ]);

            redirectWithMessage("User added successfully.");
        }

        // EDIT USER
        elseif ($action === 'edit') {

            $user_id = (int)($_POST['user_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $role = $_POST['role'] ?? '';
            $password = $_POST['password'] ?? '';

            if ($user_id <= 0 || $full_name === '' || $username === '') {
                throw new Exception("Please complete all required fields.");
            }

            if (!in_array($role, ['admin', 'staff'], true)) {
                throw new Exception("Invalid user role.");
            }

            $check = $conn->prepare("
                SELECT user_id, role, status
                FROM users
                WHERE user_id = ?
            ");
            $check->execute([$user_id]);

            $target = $check->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new Exception("User not found.");
            }

            // Prevent removing the last active admin
            if (
                $target['role'] === 'admin' &&
                $target['status'] === 'active' &&
                $role !== 'admin'
            ) {
                $count = $conn->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE role = 'admin'
                    AND status = 'active'
                ")->fetchColumn();

                if ($count <= 1) {
                    throw new Exception(
                        "You cannot remove the last active admin."
                    );
                }
            }

            // Check duplicate username
            $checkUsername = $conn->prepare("
                SELECT user_id
                FROM users
                WHERE username = ?
                AND user_id != ?
            ");
            $checkUsername->execute([$username, $user_id]);

            if ($checkUsername->fetch()) {
                throw new Exception(
                    "Username is already used by another account."
                );
            }

            if ($password !== '') {

                if (strlen($password) < 8) {
                    throw new Exception(
                        "New password must be at least 8 characters."
                    );
                }

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare("
                    UPDATE users
                    SET full_name = ?,
                        username = ?,
                        role = ?,
                        password = ?
                    WHERE user_id = ?
                ");

                $stmt->execute([
                    $full_name,
                    $username,
                    $role,
                    $hashedPassword,
                    $user_id
                ]);

            } else {

                $stmt = $conn->prepare("
                    UPDATE users
                    SET full_name = ?,
                        username = ?,
                        role = ?
                    WHERE user_id = ?
                ");

                $stmt->execute([
                    $full_name,
                    $username,
                    $role,
                    $user_id
                ]);
            }

            redirectWithMessage("User updated successfully.");
        }

        // ACTIVATE / DEACTIVATE
        elseif ($action === 'toggle_status') {

            $user_id = (int)($_POST['user_id'] ?? 0);

            if ($user_id <= 0) {
                throw new Exception("Invalid user.");
            }

            // Prevent changing own status
            if ($user_id === (int)$_SESSION['user_id']) {
                throw new Exception(
                    "You cannot deactivate or change your own account status."
                );
            }

            $stmt = $conn->prepare("
                SELECT role, status
                FROM users
                WHERE user_id = ?
            ");
            $stmt->execute([$user_id]);

            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new Exception("User not found.");
            }

            $newStatus = $target['status'] === 'active'
                ? 'inactive'
                : 'active';

            // Prevent deactivating the last active admin
            if (
                $target['role'] === 'admin' &&
                $target['status'] === 'active' &&
                $newStatus === 'inactive'
            ) {
                $count = $conn->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE role = 'admin'
                    AND status = 'active'
                ")->fetchColumn();

                if ($count <= 1) {
                    throw new Exception(
                        "You cannot deactivate the last active admin."
                    );
                }
            }

            $update = $conn->prepare("
                UPDATE users
                SET status = ?
                WHERE user_id = ?
            ");

            $update->execute([$newStatus, $user_id]);

            redirectWithMessage(
                "Account status updated successfully."
            );
        }

        else {
            throw new Exception("Invalid action.");
        }

    } catch (PDOException $e) {

        error_log($e->getMessage());
        $error = "Database error. Please check the information and try again.";

    } catch (Exception $e) {

        $error = $e->getMessage();
    }
}

// Get all users
$stmt = $conn->query("
    SELECT user_id, full_name, username, role, status, created_at
    FROM users
    ORDER BY user_id DESC
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// User summary
$totalUsers = count($users);
$activeUsers = 0;
$inactiveUsers = 0;
$adminUsers = 0;
$staffUsers = 0;

foreach ($users as $user) {

    if ($user['status'] === 'active') {
        $activeUsers++;
    } else {
        $inactiveUsers++;
    }

    if ($user['role'] === 'admin') {
        $adminUsers++;
    } elseif ($user['role'] === 'staff') {
        $staffUsers++;
    }
}

$message = $_GET['message'] ?? '';

$name = e($_SESSION['full_name'] ?? 'User');
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>User Management | Bakery System</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

<style>

:root {
    --brown: #68452f;
    --brown-dark: #503321;
    --brown-light: #805735;
    --cream: #f7f3ee;
    --cream-dark: #eee4d9;
    --text: #30271f;
    --muted: #8b8178;
    --white: #ffffff;
    --green: #198754;
    --red: #dc3545;
    --blue: #416d9e;
}

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
    scroll-padding-top: 110px;
}

body {
    margin: 0;
    font-family: 'DM Sans', Arial, sans-serif;
    background: #f5f2ee;
    color: var(--text);
}

/* =========================
   STICKY TOP HEADER
========================= */

.top-header {
    position: sticky;
    top: 0;
    z-index: 1000;
    width: 100%;
    background: rgba(255,255,255,.97);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid #e9e1d8;
    box-shadow: 0 3px 15px rgba(55,35,20,.06);
}

.header-inner {
    max-width: 1500px;
    margin: auto;
    min-height: 82px;
    padding: 12px 35px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 13px;
}

.brand-icon {
    width: 45px;
    height: 45px;
    min-width: 45px;
    border-radius: 13px;
    background: #eee0d1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.brand-text h2 {
    margin: 0;
    font-family: 'Playfair Display', serif;
    font-size: 22px;
    color: #583a27;
}

.brand-text p {
    margin: 4px 0 0;
    color: #98806c;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .13em;
    text-transform: uppercase;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-user {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-right: 15px;
    border-right: 1px solid #e9e1d8;
}

.user-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #eee0d1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}

.user-info strong {
    display: block;
    color: #493426;
    font-size: 13px;
}

.user-info span {
    display: block;
    margin-top: 3px;
    color: #988b80;
    font-size: 11px;
}

.header-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    padding: 11px 16px;
    border-radius: 9px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    transition: .2s ease;
    white-space: nowrap;
}

.dashboard-btn {
    background: #68452f;
    color: white;
}

.dashboard-btn:hover {
    background: #503321;
}

.logout-btn {
    background: #f5e9e5;
    color: #a13f30;
}

.logout-btn:hover {
    background: #eed8d2;
}

/* =========================
   MAIN CONTAINER
========================= */

.main-content {
    max-width: 1500px;
    margin: auto;
    padding: 35px;
}

.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 28px;
}

.page-heading h1 {
    margin: 0;
    font-family: 'Playfair Display', serif;
    font-size: 31px;
    color: #493426;
}

.page-heading p {
    margin: 8px 0 0;
    color: #8b8178;
    font-size: 14px;
}

.date-label {
    color: #8b8178;
    font-size: 12px;
    background: white;
    border: 1px solid #eee6dd;
    padding: 10px 14px;
    border-radius: 9px;
}

/* =========================
   ALERTS
========================= */

.alert {
    padding: 14px 17px;
    border-radius: 10px;
    margin-bottom: 22px;
    font-size: 13px;
    font-weight: 600;
}

.success {
    background: #e1f1e6;
    color: #23613c;
    border: 1px solid #c8e5d1;
}

.error {
    background: #f9e3e1;
    color: #9b342d;
    border: 1px solid #f0ceca;
}

/* =========================
   SUMMARY CARDS
========================= */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 17px;
    margin-bottom: 28px;
}

.summary-card {
    background: white;
    padding: 20px;
    border-radius: 14px;
    border: 1px solid #eee7df;
    box-shadow: 0 3px 12px rgba(65,43,26,.035);
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.summary-icon {
    width: 46px;
    height: 46px;
    min-width: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.icon-brown {
    background: #f0e5da;
}

.icon-green {
    background: #e1f0e5;
}

.icon-red {
    background: #f8e5e2;
}

.icon-blue {
    background: #e4edf7;
}

.icon-gold {
    background: #f8f0dc;
}

.summary-info {
    min-width: 0;
}

.summary-info span {
    display: block;
    color: #8b8178;
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 5px;
}

.summary-info h3 {
    margin: 0;
    color: #443326;
    font-size: 25px;
    font-weight: 800;
}

/* =========================
   CARDS
========================= */

.card {
    background: white;
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 25px;
    border: 1px solid #eee8e1;
    box-shadow: 0 3px 12px rgba(65,43,26,.045);
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.card-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
    color: #493426;
}

.card-description {
    color: #988b80;
    font-size: 12px;
    margin: 6px 0 0;
}

.section-tag {
    display: inline-block;
    padding: 7px 11px;
    border-radius: 20px;
    background: #f5eee7;
    color: #79583e;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .04em;
}

/* =========================
   FORM
========================= */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 19px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-size: 12px;
    font-weight: 700;
    color: #514235;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid #ded5cb;
    border-radius: 9px;
    font-family: inherit;
    font-size: 13px;
    color: #382d24;
    background: #fff;
    outline: none;
    transition: .2s ease;
}

.form-group input:focus,
.form-group select:focus {
    border-color: #9b704c;
    box-shadow: 0 0 0 3px rgba(128,87,53,.10);
}

.form-group input::placeholder {
    color: #aaa19a;
}

.form-hint {
    margin-top: 7px;
    color: #988b80;
    font-size: 11px;
}

.form-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 22px;
}

button {
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-weight: 700;
    transition: .2s ease;
}

.add-btn {
    background: #6d8b52;
    color: white;
    padding: 12px 21px;
    border-radius: 9px;
    font-size: 13px;
}

.add-btn:hover {
    background: #58733f;
}

.edit-btn {
    background: #416d9e;
    color: white;
    padding: 9px 13px;
    border-radius: 7px;
    font-size: 11px;
}

.edit-btn:hover {
    background: #31577f;
}

.activate-btn {
    background: #198754;
    color: white;
    padding: 9px 12px;
    border-radius: 7px;
    font-size: 11px;
}

.activate-btn:hover {
    background: #146c43;
}

.deactivate-btn {
    background: #bd5148;
    color: white;
    padding: 9px 12px;
    border-radius: 7px;
    font-size: 11px;
}

.deactivate-btn:hover {
    background: #9e3d35;
}

.cancel-btn {
    background: #77716b;
    color: white;
    padding: 11px 17px;
    border-radius: 8px;
    font-size: 12px;
}

.cancel-btn:hover {
    background: #625d58;
}

/* =========================
   USERS TABLE
========================= */

.table-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}

.table-count {
    color: #8b8178;
    font-size: 12px;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th, td {
    padding: 15px 13px;
    text-align: left;
    border-bottom: 1px solid #eee9e3;
    font-size: 12px;
}

th {
    background: #f8f5f1;
    color: #72563f;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
    white-space: nowrap;
}

td {
    color: #51483f;
}

tbody tr:hover {
    background: #fcfaf7;
}

.user-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-table-avatar {
    width: 35px;
    height: 35px;
    min-width: 35px;
    border-radius: 50%;
    background: #eee2d6;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #77543a;
    font-size: 14px;
    font-weight: 800;
}

.user-cell-info strong {
    display: block;
    color: #493426;
    font-size: 12px;
}

.user-cell-info span {
    display: block;
    color: #a0958b;
    font-size: 10px;
    margin-top: 3px;
}

.role-badge {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
    text-transform: capitalize;
}

.role-admin {
    background: #eee5f7;
    color: #74519a;
}

.role-staff {
    background: #e5edf5;
    color: #416d9e;
}

.status {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
}

.status.active {
    background: #e0f0e5;
    color: #287345;
}

.status.inactive {
    background: #f8e3e1;
    color: #a33c35;
}

.actions {
    display: flex;
    gap: 7px;
    align-items: center;
    flex-wrap: wrap;
}

.actions form {
    margin: 0;
}

/* =========================
   EDIT SECTION
========================= */

#editSection {
    border: 1px solid #dfcbb8;
    background: #fffdfb;
    scroll-margin-top: 110px;
}

.edit-actions {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
    margin-top: 22px;
}

/* =========================
   EMPTY STATE
========================= */

.empty-state {
    text-align: center;
    padding: 40px 15px;
    color: #8b8178;
}

.empty-state-icon {
    display: block;
    font-size: 38px;
    margin-bottom: 12px;
}

.empty-state strong {
    display: block;
    color: #594738;
    font-size: 14px;
}

/* =========================
   FOOTER
========================= */

.page-footer {
    text-align: center;
    padding: 10px 0 25px;
    color: #a0958b;
    font-size: 11px;
}

/* =========================
   RESPONSIVE
========================= */

@media(max-width: 1100px) {

    .summary-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    .main-content {
        padding: 28px 24px;
    }
}

@media(max-width: 750px) {

    .header-inner {
        padding: 12px 18px;
    }

    .main-content {
        padding: 22px 17px;
    }

    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .header-user {
        display: none;
    }

    .page-heading h1 {
        font-size: 26px;
    }
}

@media(max-width: 550px) {

    .header-inner {
        min-height: 70px;
        padding: 10px 13px;
        gap: 8px;
    }

    .brand {
        gap: 8px;
    }

    .brand-icon {
        width: 37px;
        height: 37px;
        min-width: 37px;
        font-size: 19px;
    }

    .brand-text h2 {
        font-size: 16px;
    }

    .brand-text p {
        font-size: 8px;
    }

    .header-actions {
        gap: 6px;
    }

    .header-btn {
        padding: 9px 10px;
        font-size: 10px;
        gap: 5px;
    }

    .main-content {
        padding: 20px 13px;
    }

    .page-heading {
        align-items: flex-start;
    }

    .page-heading h1 {
        font-size: 24px;
    }

    .page-heading p {
        font-size: 12px;
    }

    .summary-grid {
        gap: 10px;
    }

    .summary-card {
        padding: 13px;
        gap: 9px;
    }

    .summary-icon {
        width: 36px;
        height: 36px;
        min-width: 36px;
        font-size: 17px;
    }

    .summary-info span {
        font-size: 9px;
    }

    .summary-info h3 {
        font-size: 20px;
    }

    .card {
        padding: 17px;
        border-radius: 12px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .card-header h3 {
        font-size: 16px;
    }

    .date-label {
        font-size: 10px;
    }
}

@media(max-width: 350px) {

    .brand-text h2 {
        font-size: 14px;
    }

    .brand-text p {
        font-size: 7px;
    }

    .header-btn {
        padding: 8px;
        font-size: 9px;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }
}

</style>
</head>

<body>

<!-- =========================
     STICKY HEADER
========================= -->

<header class="top-header">

    <div class="header-inner">

        <div class="brand">

            <div class="brand-icon">🥐</div>

            <div class="brand-text">
                <h2>Bakery System</h2>
                <p>Sales & Inventory</p>
            </div>

        </div>

        <div class="header-actions">

            <div class="header-user">

                <div class="user-avatar">👤</div>

                <div class="user-info">
                    <strong><?= $name ?></strong>
                    <span>Administrator</span>
                </div>

            </div>

            <a href="dashboard.php" class="header-btn dashboard-btn">
                ← Back to Dashboard
            </a>

            <a href="../logout.php"
               class="header-btn logout-btn"
               onclick="return confirm('Are you sure you want to logout?');">
                ↪ Logout
            </a>

        </div>

    </div>

</header>


<!-- =========================
     MAIN CONTENT
========================= -->

<main class="main-content">

    <!-- PAGE HEADING -->
    <div class="page-heading">

        <div>
            <h1>User Management</h1>

            <p>
                Manage system accounts, roles, and user access.
            </p>
        </div>

        <div class="date-label">
            📅 <?= date('F d, Y') ?>
        </div>

    </div>


    <!-- SUCCESS MESSAGE -->
    <?php if ($message): ?>

        <div class="alert success">
            ✓ <?= e($message) ?>
        </div>

    <?php endif; ?>


    <!-- ERROR MESSAGE -->
    <?php if ($error): ?>

        <div class="alert error">
            ⚠ <?= e($error) ?>
        </div>

    <?php endif; ?>


    <!-- =========================
         USER SUMMARY
    ========================= -->

    <div class="summary-grid">

        <div class="summary-card">

            <div class="summary-icon icon-brown">👥</div>

            <div class="summary-info">
                <span>Total Users</span>
                <h3><?= number_format($totalUsers) ?></h3>
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-icon icon-green">✓</div>

            <div class="summary-info">
                <span>Active Users</span>
                <h3><?= number_format($activeUsers) ?></h3>
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-icon icon-red">⊘</div>

            <div class="summary-info">
                <span>Inactive Users</span>
                <h3><?= number_format($inactiveUsers) ?></h3>
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-icon icon-gold">♙</div>

            <div class="summary-info">
                <span>Administrators</span>
                <h3><?= number_format($adminUsers) ?></h3>
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-icon icon-blue">♧</div>

            <div class="summary-info">
                <span>Staff Members</span>
                <h3><?= number_format($staffUsers) ?></h3>
            </div>

        </div>

    </div>


    <!-- =========================
         ADD NEW USER
    ========================= -->

    <div class="card">

        <div class="card-header">

            <div>
                <h3>+ Add New User</h3>

                <p class="card-description">
                    Create a new account for your bakery system.
                </p>
            </div>

            <span class="section-tag">ACCOUNT CREATION</span>

        </div>

        <form method="POST"
              id="addUserForm"
              autocomplete="off">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden"
                   name="action"
                   value="add">

            <!-- Autofill decoy fields -->
            <input type="text"
                   name="dummy_username"
                   autocomplete="username"
                   tabindex="-1"
                   aria-hidden="true"
                   style="position:absolute;left:-9999px;">

            <input type="password"
                   name="dummy_password"
                   autocomplete="current-password"
                   tabindex="-1"
                   aria-hidden="true"
                   style="position:absolute;left:-9999px;">

            <div class="form-grid">

                <div class="form-group">

                    <label>Full Name</label>

                    <input type="text"
                           name="full_name"
                           placeholder="Enter full name"
                           autocomplete="off"
                           required>

                </div>

                <div class="form-group">

                    <label>Username</label>

                    <input type="text"
                           name="username"
                           placeholder="Enter username"
                           autocomplete="off"
                           readonly
                           onfocus="this.removeAttribute('readonly')"
                           required>

                </div>

                <div class="form-group">

                    <label>Password</label>

                    <input type="password"
                           name="password"
                           minlength="8"
                           placeholder="At least 8 characters"
                           autocomplete="new-password"
                           readonly
                           onfocus="this.removeAttribute('readonly')"
                           required>

                    <div class="form-hint">
                        Minimum of 8 characters.
                    </div>

                </div>

                <div class="form-group">

                    <label>Account Role</label>

                    <select name="role" required>
                        <option value="staff" selected>Staff</option>
                        <option value="admin">Admin</option>
                    </select>

                    <div class="form-hint">
                        Select the access level for this account.
                    </div>

                </div>

            </div>

            <div class="form-actions">

                <button type="submit" class="add-btn">
                    + Create Account
                </button>

            </div>

        </form>

    </div>


    <!-- =========================
         REGISTERED USERS
    ========================= -->

    <div class="card">

        <div class="card-header">

            <div>
                <h3>Registered Users</h3>

                <p class="card-description">
                    View and manage all registered system accounts.
                </p>
            </div>

            <span class="section-tag">
                <?= number_format($totalUsers) ?> ACCOUNTS
            </span>

        </div>

        <div class="table-toolbar">

            <div class="table-count">
                Showing <?= number_format($totalUsers) ?> registered account(s)
            </div>

        </div>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>User Information</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Date Created</th>
                        <th>Actions</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (count($users) > 0): ?>

                    <?php foreach ($users as $user): ?>

                    <tr>

                        <td>
                            #<?= e($user['user_id']) ?>
                        </td>

                        <td>

                            <div class="user-cell">

                                <div class="user-table-avatar">
                                    <?= e(strtoupper(substr($user['full_name'], 0, 1))) ?>
                                </div>

                                <div class="user-cell-info">

                                    <strong>
                                        <?= e($user['full_name']) ?>
                                    </strong>

                                    <span>
                                        User Account
                                    </span>

                                </div>

                            </div>

                        </td>

                        <td>
                            <?= e($user['username']) ?>
                        </td>

                        <td>

                            <span class="role-badge <?= $user['role'] === 'admin' ? 'role-admin' : 'role-staff' ?>">
                                <?= e($user['role']) ?>
                            </span>

                        </td>

                        <td>

                            <span class="status <?= e($user['status']) ?>">
                                <?= e(ucfirst($user['status'])) ?>
                            </span>

                        </td>

                        <td>
                            <?= e($user['created_at']) ?>
                        </td>

                        <td>

                            <div class="actions">

                                <button
                                    type="button"
                                    class="edit-btn"
                                    onclick='editUser(
                                        <?= json_encode([
                                            "user_id" => $user["user_id"],
                                            "full_name" => $user["full_name"],
                                            "username" => $user["username"],
                                            "role" => $user["role"]
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                                    )'>

                                    Edit

                                </button>

                                <?php if ((int)$user['user_id'] !== (int)$_SESSION['user_id']): ?>

                                    <form method="POST"
                                          onsubmit="return confirm('Are you sure you want to change this account status?');">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="toggle_status">

                                        <input type="hidden"
                                               name="user_id"
                                               value="<?= e($user['user_id']) ?>">

                                        <?php if ($user['status'] === 'active'): ?>

                                            <button type="submit"
                                                    class="deactivate-btn">
                                                Deactivate
                                            </button>

                                        <?php else: ?>

                                            <button type="submit"
                                                    class="activate-btn">
                                                Activate
                                            </button>

                                        <?php endif; ?>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="7">

                            <div class="empty-state">

                                <span class="empty-state-icon">👥</span>

                                <strong>No users found.</strong>

                                <p>
                                    No registered accounts are available.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- =========================
         EDIT USER
    ========================= -->

    <div class="card" id="editSection" style="display:none;">

        <div class="card-header">

            <div>
                <h3>Edit User Account</h3>

                <p class="card-description">
                    Update the selected user's account information.
                </p>
            </div>

            <span class="section-tag">ACCOUNT SETTINGS</span>

        </div>

        <form method="POST" autocomplete="off">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden"
                   name="action"
                   value="edit">

            <input type="hidden"
                   name="user_id"
                   id="edit_user_id">

            <div class="form-grid">

                <div class="form-group">

                    <label>Full Name</label>

                    <input type="text"
                           name="full_name"
                           id="edit_full_name"
                           required>

                </div>

                <div class="form-group">

                    <label>Username</label>

                    <input type="text"
                           name="username"
                           id="edit_username"
                           autocomplete="off"
                           required>

                </div>

                <div class="form-group">

                    <label>New Password</label>

                    <input type="password"
                           name="password"
                           minlength="8"
                           autocomplete="new-password"
                           placeholder="Leave blank to keep current password">

                    <div class="form-hint">
                        Leave empty if you do not want to change the password.
                    </div>

                </div>

                <div class="form-group">

                    <label>Account Role</label>

                    <select name="role" id="edit_role" required>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>

                </div>

            </div>

            <div class="edit-actions">

                <button type="submit" class="edit-btn">
                    Save Changes
                </button>

                <button type="button"
                        class="cancel-btn"
                        onclick="closeEdit()">
                    Cancel
                </button>

            </div>

        </form>

    </div>


    <!-- FOOTER -->
    <div class="page-footer">
        Bakery System &copy; <?= date('Y') ?> | User Management
    </div>

</main>


<script>

// Edit user
function editUser(user) {

    document.getElementById('edit_user_id').value = user.user_id;

    document.getElementById('edit_full_name').value = user.full_name;

    document.getElementById('edit_username').value = user.username;

    document.getElementById('edit_role').value = user.role;

    const section = document.getElementById('editSection');

    section.style.display = 'block';

    section.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });

}

// Close edit form
function closeEdit() {

    document.getElementById('editSection').style.display = 'none';

}

// Clear Add User form after submission
document.getElementById('addUserForm').addEventListener('submit', function() {

    const form = this;

    setTimeout(function() {
        form.reset();
    }, 0);

});

</script>

</body>
</html>
