
<?php
session_start();
require_once '../config/database.php';

date_default_timezone_set('America/Los_Angeles');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'staff';
$isAdmin = ($role === 'admin');

function escape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fetchCurrentUser(PDO $conn, int $userId): array|false {
    $stmt = $conn->prepare(
        'SELECT user_id, full_name, username, role, status, profile_picture
         FROM users
         WHERE user_id = :user_id
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $userId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$user = fetchCurrentUser($conn, $userId);

if (!$user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

/* PROFILE PHOTO */
$databaseDirectory = 'uploads/profiles/';
$profilePicture = $user['profile_picture'] ?? '';
$profileImage = '';

if (
    $profilePicture !== '' &&
    strpos($profilePicture, $databaseDirectory) === 0 &&
    basename($profilePicture) === substr(
        $profilePicture,
        strlen($databaseDirectory)
    )
) {
    $absolutePicture = dirname(__DIR__) . '/' . $profilePicture;

    if (is_file($absolutePicture)) {
        $profileImage = '../' . $profilePicture . '?v=' . filemtime($absolutePicture);
    }
}

$initial = strtoupper(
    substr(
        trim($user['full_name'] ?? 'U'),
        0,
        1
    )
);

$roleLabel = ucfirst($user['role'] ?? 'Staff');
$statusLabel = ucfirst($user['status'] ?? 'Unknown');

$statusActive = strtolower(
    (string) ($user['status'] ?? '')
) === 'active';

$photoStatus = $profileImage
    ? 'Uploaded'
    : 'Default avatar';
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>My Profile | Bakery Management System</title>

<link rel="preconnect" href="https://fonts.googleapis.com">

<link
    rel="preconnect"
    href="https://fonts.gstatic.com"
    crossorigin
>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap"
    rel="stylesheet"
>

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

a {
    color: inherit;
    text-decoration: none;
}

/* =========================================
   SIDEBAR
========================================= */

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

    border-radius: 14px;
    border-left: 4px solid transparent;

    font-size: 15px;
    font-weight: 600;

    transition: .2s ease;
}

.nav-link:hover {
    background: rgba(255,255,255,.10);
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

.sidebar-profile-link {
    display: flex;
    align-items: center;

    gap: 12px;

    padding: 12px 10px;

    margin-bottom: 12px;

    color: #fff;

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
    justify-content: center;
    align-items: center;

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

.logout-link {
    display: flex;
    align-items: center;

    gap: 16px;

    min-height: 57px;

    padding: 12px 18px;

    color: #fff;

    background: #a13f30;

    border-radius: 13px;

    font-size: 15px;
    font-weight: 700;

    transition: .2s ease;
}

.logout-link:hover {
    background: #8e3428;
}

/* =========================================
   MAIN CONTENT
========================================= */

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

    color: inherit;
    cursor: pointer;
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

/* =========================================
   PROFILE LAYOUT
========================================= */

.profile-layout {
    display: grid;

    grid-template-columns: 1.5fr 1fr;

    gap: 18px;

    align-items: start;
}

.card,
.photo-card {
    min-width: 0;

    padding: 21px;

    background: white;

    border: 1px solid var(--border);
    border-radius: 13px;
}

/* =========================================
   PROFILE HERO
========================================= */

.profile-hero {
    position: relative;

    margin: -21px -21px 21px;

    padding: 27px 25px;

    overflow: hidden;

    color: white;

    background: linear-gradient(
        115deg,
        #59391f,
        #805633
    );

    border-radius: 13px 13px 0 0;
}

.profile-hero::before,
.profile-hero::after {
    content: '';

    position: absolute;

    border: 1px solid rgba(255,255,255,.10);

    border-radius: 50%;
}

.profile-hero::before {
    width: 220px;
    height: 220px;

    right: -65px;
    top: -105px;
}

.profile-hero::after {
    width: 160px;
    height: 160px;

    right: -30px;
    top: -70px;
}

.hero-kicker {
    position: relative;
    z-index: 1;

    margin-bottom: 19px;

    color: #e6d7c8;

    font-size: 10px;
    font-weight: 700;

    letter-spacing: .13em;
    text-transform: uppercase;
}

.hero-person {
    position: relative;
    z-index: 1;

    display: flex;
    align-items: center;

    gap: 18px;
}

.hero-avatar-wrap {
    position: relative;

    flex-shrink: 0;
}

/* HERO PROFILE PHOTO */

.hero-avatar {
    width: 92px;
    height: 92px;

    display: flex;
    justify-content: center;
    align-items: center;

    overflow: hidden;

    border: 4px solid rgba(255,255,255,.85);
    border-radius: 50%;

    background: #e9d8c5;
    color: var(--brown);

    font-size: 32px;
    font-weight: 800;

    box-shadow: 0 5px 15px rgba(0,0,0,.15);
}

.hero-avatar img {
    width: 100%;
    height: 100%;

    object-fit: cover;

    display: block;
}

.avatar-status {
    position: absolute;

    right: 4px;
    bottom: 4px;

    width: 15px;
    height: 15px;

    border: 3px solid white;
    border-radius: 50%;

    background: #4caf79;
}

.hero-name {
    margin: 0 0 6px;

    font-family: 'Playfair Display', Georgia, serif;
    font-size: 24px;
    line-height: 1.2;

    overflow-wrap: anywhere;
}

.hero-username {
    margin-bottom: 11px;

    color: #e6d7c8;

    font-size: 12px;
}

.hero-badges {
    display: flex;
    flex-wrap: wrap;

    gap: 7px;
}

.hero-badge {
    padding: 5px 9px;

    border: 1px solid rgba(255,255,255,.2);
    border-radius: 20px;

    background: rgba(255,255,255,.10);

    color: white;

    font-size: 10px;
}

.hero-badge.status {
    border: 0;

    background: #d5f0df;
    color: #256745;

    font-weight: 700;
}

/* =========================================
   ACCOUNT INFORMATION
========================================= */

.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 10px;

    margin-bottom: 17px;
}

.section-title {
    display: flex;
    align-items: center;

    gap: 9px;

    color: var(--brown);

    font-size: 14px;
    font-weight: 700;
}

.section-icon {
    display: flex;
    justify-content: center;
    align-items: center;

    width: 30px;
    height: 30px;

    border-radius: 9px;

    background: #f2e7db;

    font-size: 15px;
}

.section-caption {
    color: var(--muted);

    font-size: 10px;
}

.info-grid {
    display: grid;

    grid-template-columns: repeat(2, minmax(0,1fr));

    gap: 12px;
}

.info-item {
    min-width: 0;

    padding: 15px;

    background: #faf8f5;

    border: 1px solid var(--border);
    border-radius: 11px;

    transition: .2s;
}

.info-item:hover {
    border-color: #d7b999;
    background: #fffaf6;
}

.info-label {
    margin-bottom: 7px;

    color: var(--muted);

    font-size: 10px;
    font-weight: 700;
}

.info-value {
    color: var(--text);

    font-size: 12px;
    font-weight: 700;

    overflow-wrap: anywhere;
}

.info-value.id {
    color: var(--brown);

    font-family: monospace;
    font-size: 13px;
}

.account-note {
    display: flex;
    align-items: flex-start;

    gap: 10px;

    margin-top: 17px;

    padding: 13px 14px;

    border: 1px solid #eee3d9;
    border-radius: 10px;

    background: #f8f3ee;
}

.account-note-icon {
    color: #906b49;

    font-size: 15px;
}

.account-note-text {
    color: #897767;

    font-size: 10px;
    line-height: 1.65;
}

/* =========================================
   PROFILE PHOTO CARD
========================================= */

.photo-display {
    display: flex;
    flex-direction: column;
    align-items: center;

    text-align: center;

    padding: 10px 0 18px;
}

/* UPDATED: LARGER PROFILE PHOTO */

.photo-frame {
    width: 230px;
    height: 230px;

    display: grid;
    place-items: center;

    overflow: hidden;

    margin-bottom: 20px;

    border: 7px solid #f2e7db;
    border-radius: 50%;

    background: #e9d8c5;
    color: var(--brown);

    font-size: 75px;
    font-weight: 800;

    box-shadow: 0 8px 25px rgba(84, 53, 31, 0.12);

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.photo-frame:hover {
    transform: scale(1.03);

    box-shadow: 0 12px 30px rgba(84, 53, 31, 0.18);
}

.photo-frame img {
    width: 100%;
    height: 100%;

    object-fit: cover;

    display: block;
}

.photo-name {
    margin-bottom: 5px;

    color: var(--text);

    font-size: 13px;
    font-weight: 700;
}

.photo-status {
    color: var(--muted);

    font-size: 11px;
}

.photo-status strong {
    color: var(--brown);
}

.photo-divider {
    height: 1px;

    margin: 5px 0 17px;

    background: var(--border);
}

.photo-description {
    color: var(--muted);

    font-size: 11px;
    line-height: 1.7;

    text-align: center;
}

.footer-note {
    margin-top: 18px;

    color: var(--muted);

    font-size: 10px;

    text-align: center;
}

/* =========================================
   RESPONSIVE DESIGN
========================================= */

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

    .profile-layout {
        grid-template-columns: 1fr;
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
        padding: 20px;
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

        grid-template-columns: repeat(2, minmax(0,1fr));

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

    .profile-hero {
        padding: 22px;

        margin: -14px -14px 18px;
    }

    .hero-person {
        gap: 14px;
    }

    .hero-avatar {
        width: 78px;
        height: 78px;

        font-size: 27px;
    }

    .hero-name {
        font-size: 20px;
    }

    .card,
    .photo-card {
        padding: 14px;
    }

    .info-grid {
        grid-template-columns: 1fr;

        gap: 10px;
    }

    /* UPDATED MOBILE PROFILE PHOTO */

    .photo-frame {
        width: 190px;
        height: 190px;

        font-size: 65px;
    }
}

@media (max-width: 360px) {

    .nav-menu,
    .info-grid {
        grid-template-columns: 1fr;
    }
}

</style>

</head>

<body>

<!-- =========================================
     SIDEBAR
========================================= -->

<aside class="sidebar">

    <div class="brand">

        <span class="brand-icon">🥐</span>

        <div>

            <h2 class="brand-name">
                Bakery System
            </h2>

            <p class="brand-subtitle">
                SALES & INVENTORY
            </p>

        </div>

    </div>

    <p class="menu-label">
        MAIN MENU
    </p>

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

    <!-- SIDEBAR PROFILE -->

    <div class="sidebar-bottom">

        <a
            href="profile.php"
            class="sidebar-profile-link"
            title="My Profile"
        >

            <div class="sidebar-profile-avatar">

                <?php if (!empty($profileImage)): ?>

                    <img
                        src="<?= escape($profileImage) ?>"
                        alt="Profile picture"
                    >

                <?php else: ?>

                    <?= escape($initial) ?>

                <?php endif; ?>

            </div>

            <div class="sidebar-profile-details">

                <strong>
                    <?= escape($user['full_name']) ?>
                </strong>

                <span>
                    <?= escape($role) ?> · My Profile
                </span>

            </div>

        </a>

        <a
            href="../logout.php"
            class="logout-link"
        >

            <span class="nav-icon">↪</span>

            Logout

        </a>

    </div>

</aside>

<!-- =========================================
     MAIN CONTENT
========================================= -->

<main class="main">

    <div class="content-container">

        <!-- TOPBAR -->

        <header class="topbar">

            <div class="page-title">

                <h1>
                    My Profile
                </h1>

                <p>
                    Your personal account information and profile details.
                </p>

            </div>

            <a
                href="profile.php"
                class="user-info"
                title="My Profile"
            >

                <div class="user-avatar">

                    <?php if (!empty($profileImage)): ?>

                        <img
                            src="<?= escape($profileImage) ?>"
                            alt="Profile picture"
                        >

                    <?php else: ?>

                        <?= escape($initial) ?>

                    <?php endif; ?>

                </div>

                <div class="user-details">

                    <strong>
                        <?= escape($user['full_name']) ?>
                    </strong>

                    <span>

                        <span class="role-badge">

                            <?= $isAdmin
                                ? 'ADMINISTRATOR'
                                : 'STAFF'
                            ?>

                        </span>

                    </span>

                </div>

            </a>

        </header>

        <!-- PROFILE CONTENT -->

        <div class="profile-layout">

            <!-- ACCOUNT INFORMATION CARD -->

            <section class="card">

                <!-- PROFILE HERO -->

                <div class="profile-hero">

                    <div class="hero-kicker">
                        Bakery Shop · Account Overview
                    </div>

                    <div class="hero-person">

                        <div class="hero-avatar-wrap">

                            <div class="hero-avatar">

                                <?php if ($profileImage): ?>

                                    <img
                                        src="<?= escape($profileImage) ?>"
                                        alt="Profile picture"
                                    >

                                <?php else: ?>

                                    <?= escape($initial) ?>

                                <?php endif; ?>

                            </div>

                            <?php if ($statusActive): ?>

                                <span
                                    class="avatar-status"
                                    title="Active"
                                ></span>

                            <?php endif; ?>

                        </div>

                        <div>

                            <h2 class="hero-name">
                                <?= escape($user['full_name']) ?>
                            </h2>

                            <div class="hero-username">
                                @<?= escape($user['username']) ?>
                            </div>

                            <div class="hero-badges">

                                <span class="hero-badge">
                                    <?= escape($roleLabel) ?>
                                </span>

                                <span class="hero-badge status">
                                    <?= escape($statusLabel) ?>
                                </span>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- ACCOUNT INFORMATION -->

                <div>

                    <div class="section-heading">

                        <h3 class="section-title">

                            <span class="section-icon">
                                ▤
                            </span>

                            Account Information

                        </h3>

                        <span class="section-caption">
                            Your registered details
                        </span>

                    </div>

                    <div class="info-grid">

                        <div class="info-item">

                            <div class="info-label">
                                ♙ Full Name
                            </div>

                            <div class="info-value">
                                <?= escape($user['full_name']) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                ＠ Username
                            </div>

                            <div class="info-value">
                                <?= escape($user['username']) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                ✦ Account Role
                            </div>

                            <div class="info-value">
                                <?= escape($roleLabel) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                ● Account Status
                            </div>

                            <div class="info-value">
                                <?= escape($statusLabel) ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                ⌗ User ID
                            </div>

                            <div class="info-value id">
                                #<?= (int) $user['user_id'] ?>
                            </div>

                        </div>

                        <div class="info-item">

                            <div class="info-label">
                                ◉ Profile Photo
                            </div>

                            <div class="info-value">
                                <?= escape($photoStatus) ?>
                            </div>

                        </div>

                    </div>

                    <!-- ACCOUNT NOTE -->

                    <div class="account-note">

                        <span class="account-note-icon">
                            ✧
                        </span>

                        <div class="account-note-text">

                            <strong>
                                Account details
                            </strong>

                            <br>

                            Your username and role are managed by the system.
                            If you need account details changed, contact
                            your system administrator.

                        </div>

                    </div>

                </div>

            </section>

            <!-- PROFILE PHOTO CARD -->

            <section class="photo-card">

                <div class="section-heading">

                    <h3 class="section-title">

                        <span class="section-icon">
                            ▧
                        </span>

                        Profile Photo

                    </h3>

                    <span class="section-caption">
                        Display only
                    </span>

                </div>

                <!-- LARGER PROFILE PHOTO -->

                <div class="photo-display">

                    <div class="photo-frame">

                        <?php if ($profileImage): ?>

                            <img
                                src="<?= escape($profileImage) ?>"
                                alt="Current profile photo"
                            >

                        <?php else: ?>

                            <?= escape($initial) ?>

                        <?php endif; ?>

                    </div>

                    <div class="photo-name">
                        <?= escape($user['full_name']) ?>
                    </div>

                    <div class="photo-status">

                        Photo status:

                        <strong>
                            <?= escape($photoStatus) ?>
                        </strong>

                    </div>

                </div>

                <div class="photo-divider"></div>

                <p class="photo-description">

                    Your profile photo is displayed in your account,
                    dashboard, and sidebar. Profile photo changes
                    are managed through User Management by an administrator.

                </p>

                <div class="account-note">

                    <span class="account-note-icon">
                        ⓘ
                    </span>

                    <div class="account-note-text">

                        Need to change your photo?

                        Please contact your system administrator
                        to update it through User Management.

                    </div>

                </div>

                <div class="footer-note">

                    Bakery Shop · Account Profile

                </div>

            </section>

        </div>

    </div>

</main>

</body>

</html>
