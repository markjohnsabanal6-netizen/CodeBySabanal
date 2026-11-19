<?php
session_start();
require_once '../config/database.php';

date_default_timezone_set('America/Los_Angeles');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access Denied. Admins only.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectWithMessage($message)
{
    header(
        'Location: user_management.php?message=' .
        urlencode($message)
    );

    exit;
}

function profileUploadInfo(array $file): array
{
    if (
        !isset($file['error']) ||
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return [null, null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception(
            'The profile photo upload failed. Please try again.'
        );
    }

    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        throw new Exception(
            'The selected image could not be verified.'
        );
    }

    if (
        ($file['size'] ?? 0) <= 0 ||
        $file['size'] > 2 * 1024 * 1024
    ) {
        throw new Exception(
            'Profile photo must be no more than 2MB.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mime = $finfo->file($file['tmp_name']);

    $types = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    $info = @getimagesize($file['tmp_name']);

    if (
        !isset($types[$mime]) ||
        !$info ||
        ($info['mime'] ?? '') !== $mime
    ) {
        throw new Exception(
            'Only valid JPG, PNG, or WEBP images are allowed.'
        );
    }

    return [
        $types[$mime],
        $file['tmp_name']
    ];
}

function saveProfileUpload(array $file, int $userId): ?string
{
    [$extension, $tmp] = profileUploadInfo($file);

    if (!$extension) {
        return null;
    }

    $directory = dirname(__DIR__) . '/uploads/profiles/';

    if (
        !is_dir($directory) &&
        !mkdir($directory, 0755, true) &&
        !is_dir($directory)
    ) {
        throw new Exception(
            'Unable to create profile photo folder.'
        );
    }

    $filename = 'user_' .
        $userId . '_' .
        bin2hex(random_bytes(8)) .
        '.' . $extension;

    if (
        !move_uploaded_file(
            $tmp,
            $directory . $filename
        )
    ) {
        throw new Exception(
            'Could not save the profile photo. Check folder permissions.'
        );
    }

    return 'uploads/profiles/' . $filename;
}

function deleteOwnedProfilePhoto(?string $path): void
{
    if (
        !$path ||
        strpos($path, 'uploads/profiles/') !== 0
    ) {
        return;
    }

    $absolute = dirname(__DIR__) . '/' . $path;

    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function profilePhotoUrl(?string $path): string
{
    if (
        !$path ||
        strpos($path, 'uploads/profiles/') !== 0
    ) {
        return '';
    }

    $absolute = dirname(__DIR__) . '/' . $path;

    if (!is_file($absolute)) {
        return '';
    }

    return '../' . $path . '?v=' . filemtime($absolute);
}

/* =========================================================
   INITIAL VARIABLES
========================================================= */

$error = '';
$message = $_GET['message'] ?? '';

$uploadDirectory = dirname(__DIR__) . '/uploads/profiles/';

if (!is_dir($uploadDirectory)) {
    @mkdir($uploadDirectory, 0755, true);
}

/* =========================================================
   POST REQUEST HANDLING
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $postedToken
        )
    ) {
        exit('Invalid request token.');
    }

    $action = $_POST['action'] ?? '';

    try {

        /* =========================
           ADD USER
        ========================= */

        if ($action === 'add') {

            $full_name = trim(
                $_POST['full_name'] ?? ''
            );

            $username = trim(
                $_POST['username'] ?? ''
            );

            $password = $_POST['password'] ?? '';

            $role = $_POST['role'] ?? 'staff';

            if (
                $full_name === '' ||
                $username === '' ||
                $password === ''
            ) {
                throw new Exception(
                    'Please complete all required fields.'
                );
            }

            if (!in_array($role, ['admin', 'staff'], true)) {
                throw new Exception('Invalid user role.');
            }

            if (strlen($password) < 8) {
                throw new Exception(
                    'Password must be at least 8 characters.'
                );
            }

            $check = $conn->prepare(
                'SELECT user_id FROM users WHERE username = ?'
            );

            $check->execute([$username]);

            if ($check->fetch()) {
                throw new Exception(
                    'Username already exists.'
                );
            }

            /* Validate profile photo before account creation */

            if (
                isset($_FILES['profile_picture']) &&
                $_FILES['profile_picture']['error'] !==
                    UPLOAD_ERR_NO_FILE
            ) {
                profileUploadInfo(
                    $_FILES['profile_picture']
                );
            }

            $stmt = $conn->prepare(
                "INSERT INTO users
                (full_name, username, password, role, status)
                VALUES (?, ?, ?, ?, 'active')"
            );

            $stmt->execute([
                $full_name,
                $username,
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                $role
            ]);

            $newUserId = (int) $conn->lastInsertId();

            /* Save profile photo */

            if (
                isset($_FILES['profile_picture']) &&
                $_FILES['profile_picture']['error'] !==
                    UPLOAD_ERR_NO_FILE
            ) {
                $newPath = saveProfileUpload(
                    $_FILES['profile_picture'],
                    $newUserId
                );

                $up = $conn->prepare(
                    'UPDATE users
                     SET profile_picture = ?
                     WHERE user_id = ?'
                );

                $up->execute([
                    $newPath,
                    $newUserId
                ]);
            }

            redirectWithMessage(
                'User added successfully.'
            );

        /* =========================
           EDIT USER
        ========================= */

        } elseif ($action === 'edit') {

            $user_id = (int) (
                $_POST['user_id'] ?? 0
            );

            $full_name = trim(
                $_POST['full_name'] ?? ''
            );

            $username = trim(
                $_POST['username'] ?? ''
            );

            $role = $_POST['role'] ?? '';

            $password = $_POST['password'] ?? '';

            if (
                $user_id <= 0 ||
                $full_name === '' ||
                $username === ''
            ) {
                throw new Exception(
                    'Please complete all required fields.'
                );
            }

            if (!in_array($role, ['admin', 'staff'], true)) {
                throw new Exception('Invalid user role.');
            }

            /* Fetch target account */

            $check = $conn->prepare(
                'SELECT user_id, role, status, profile_picture
                 FROM users
                 WHERE user_id = ?'
            );

            $check->execute([$user_id]);

            $target = $check->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new Exception('User not found.');
            }

            /* Protect the last active admin */

            if (
                $target['role'] === 'admin' &&
                $target['status'] === 'active' &&
                $role !== 'admin'
            ) {
                $count = $conn->query(
                    "SELECT COUNT(*)
                     FROM users
                     WHERE role = 'admin'
                     AND status = 'active'"
                )->fetchColumn();

                if ($count <= 1) {
                    throw new Exception(
                        'You cannot remove the last active admin.'
                    );
                }
            }

            /* Check duplicate username */

            $checkUsername = $conn->prepare(
                'SELECT user_id
                 FROM users
                 WHERE username = ?
                 AND user_id != ?'
            );

            $checkUsername->execute([
                $username,
                $user_id
            ]);

            if ($checkUsername->fetch()) {
                throw new Exception(
                    'Username is already used by another account.'
                );
            }

            if (
                $password !== '' &&
                strlen($password) < 8
            ) {
                throw new Exception(
                    'New password must be at least 8 characters.'
                );
            }

            /* Validate profile photo */

            $hasUpload =
                isset($_FILES['profile_picture']) &&
                $_FILES['profile_picture']['error'] !==
                    UPLOAD_ERR_NO_FILE;

            if ($hasUpload) {
                profileUploadInfo(
                    $_FILES['profile_picture']
                );
            }

            $removePhoto =
                isset($_POST['remove_picture']) &&
                $_POST['remove_picture'] === '1';

            /* Update account details */

            if ($password !== '') {

                $stmt = $conn->prepare(
                    'UPDATE users
                     SET full_name = ?,
                         username = ?,
                         role = ?,
                         password = ?
                     WHERE user_id = ?'
                );

                $stmt->execute([
                    $full_name,
                    $username,
                    $role,
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    ),
                    $user_id
                ]);

            } else {

                $stmt = $conn->prepare(
                    'UPDATE users
                     SET full_name = ?,
                         username = ?,
                         role = ?
                     WHERE user_id = ?'
                );

                $stmt->execute([
                    $full_name,
                    $username,
                    $role,
                    $user_id
                ]);
            }

            /* Update profile photo */

            if ($hasUpload) {

                $newPath = saveProfileUpload(
                    $_FILES['profile_picture'],
                    $user_id
                );

                $up = $conn->prepare(
                    'UPDATE users
                     SET profile_picture = ?
                     WHERE user_id = ?'
                );

                $up->execute([
                    $newPath,
                    $user_id
                ]);

                deleteOwnedProfilePhoto(
                    $target['profile_picture']
                );

            } elseif ($removePhoto) {

                $up = $conn->prepare(
                    'UPDATE users
                     SET profile_picture = NULL
                     WHERE user_id = ?'
                );

                $up->execute([$user_id]);

                deleteOwnedProfilePhoto(
                    $target['profile_picture']
                );
            }

            redirectWithMessage(
                'User updated successfully.'
            );

        /* =========================
           TOGGLE ACCOUNT STATUS
        ========================= */

        } elseif ($action === 'toggle_status') {

            $user_id = (int) (
                $_POST['user_id'] ?? 0
            );

            if ($user_id <= 0) {
                throw new Exception('Invalid user.');
            }

            if (
                $user_id ===
                (int) $_SESSION['user_id']
            ) {
                throw new Exception(
                    'You cannot deactivate or change your own account status.'
                );
            }

            $stmt = $conn->prepare(
                'SELECT role, status
                 FROM users
                 WHERE user_id = ?'
            );

            $stmt->execute([$user_id]);

            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new Exception('User not found.');
            }

            $newStatus =
                $target['status'] === 'active'
                    ? 'inactive'
                    : 'active';

            /* Protect the last active admin */

            if (
                $target['role'] === 'admin' &&
                $target['status'] === 'active' &&
                $newStatus === 'inactive'
            ) {
                $count = $conn->query(
                    "SELECT COUNT(*)
                     FROM users
                     WHERE role = 'admin'
                     AND status = 'active'"
                )->fetchColumn();

                if ($count <= 1) {
                    throw new Exception(
                        'You cannot deactivate the last active admin.'
                    );
                }
            }

            $update = $conn->prepare(
                'UPDATE users
                 SET status = ?
                 WHERE user_id = ?'
            );

            $update->execute([
                $newStatus,
                $user_id
            ]);

            redirectWithMessage(
                'Account status updated successfully.'
            );

        } else {

            throw new Exception('Invalid action.');
        }

    } catch (PDOException $ex) {

        error_log($ex->getMessage());

        $error =
            'Database error. Please check the information and try again.';

    } catch (Exception $ex) {

        $error = $ex->getMessage();
    }
}

/* =========================================================
   FETCH USERS
========================================================= */

$stmt = $conn->query(
    'SELECT user_id,
            full_name,
            username,
            role,
            status,
            created_at,
            profile_picture
     FROM users
     ORDER BY user_id DESC'
);

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

/* =========================================================
   CURRENT USER PROFILE
========================================================= */

$name = e($_SESSION['full_name'] ?? 'User');

$currentStmt = $conn->prepare(
    'SELECT profile_picture
     FROM users
     WHERE user_id = ?'
);

$currentStmt->execute([
    (int) $_SESSION['user_id']
]);

$currentUserPhoto = profilePhotoUrl(
    $currentStmt->fetchColumn() ?: null
);
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>User Management | Bakery System</title>

    <link rel="preconnect"
          href="https://fonts.googleapis.com">

    <link rel="preconnect"
          href="https://fonts.gstatic.com"
          crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap"
          rel="stylesheet">

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
    --white: #fff;

    --sidebar-width: 260px;

    --green: #198754;
    --red: #dc3545;
    --blue: #416d9e;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    background: var(--cream);
    color: var(--text);
    font-family: 'DM Sans', Arial, sans-serif;
}

a {
    text-decoration: none;
    color: inherit;
}

/* =========================================================
   SIDEBAR
========================================================= */

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

    color: #fff;
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

.brand-text h2 {
    margin: 0;

    color: #fff;
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 23px;
    line-height: 1.15;
}

.brand-text p {
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
    background: rgba(255, 255, 255, .10);
}

.nav-link.active {
    background: #805735;
    border-left-color: #f0c99d;
    color: #fff;
}

.nav-icon {
    display: inline-flex;
    justify-content: center;
    align-items: center;

    width: 22px;

    font-size: 19px;
}

.sidebar-spacer {
    flex: 1;
    min-height: 20px;
}

.sidebar-bottom {
    margin-top: auto;
    padding-top: 22px;

    border-top: 1px solid rgba(255, 255, 255, .17);
}

.sidebar-profile-link {
    display: flex;
    align-items: center;
    gap: 12px;

    padding: 12px 10px;
    margin-bottom: 12px;

    color: #fff;
    border-radius: 12px;

    transition: .2s;
}

.sidebar-profile-link:hover {
    background: rgba(255, 255, 255, .10);
}

.sidebar-profile-avatar {
    display: flex;
    justify-content: center;
    align-items: center;

    width: 42px;
    height: 42px;

    border-radius: 50%;

    background: #e9d8c5;
    color: var(--brown);

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

    transition: .2s;
}

.logout-link:hover {
    background: #8e3428;
}

/* =========================================================
   MAIN CONTENT
========================================================= */

.main {
    min-height: 100vh;
    margin-left: var(--sidebar-width);
    padding: 32px;
}

.content {
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
    margin: 5px 0 0;

    color: var(--muted);
    font-size: 13px;
}

.topbar-user {
    display: flex;
    align-items: center;
    gap: 12px;

    padding: 8px 12px;

    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;

    color: inherit;
}

.avatar {
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
    flex-shrink: 0;
}

.avatar img {
    width: 100%;
    height: 100%;

    object-fit: cover;
    display: block;
}

.topbar-user strong {
    display: block;
    font-size: 12px;
}

.topbar-user span {
    display: block;
    margin-top: 3px;

    color: var(--muted);
    font-size: 10px;
}

.topbar-user:hover .user-info strong {
    color: var(--brown);
}

/* =========================================================
   PAGE HEADING
========================================================= */

.heading {
    display: flex;
    justify-content: flex-end;
    align-items: flex-end;
    gap: 15px;
    flex-wrap: wrap;

    margin-bottom: 25px;
}

.heading h1 {
    margin: 0;

    font-family: 'Playfair Display', serif;
    font-size: 30px;
    color: #493426;
}

.heading p {
    margin: 7px 0 0;

    font-size: 13px;
    color: var(--muted);
}

.date-label {
    padding: 10px 13px;

    background: white;
    border: 1px solid var(--border);
    border-radius: 9px;

    color: var(--muted);
    font-size: 11px;
    white-space: nowrap;
}

/* =========================================================
   ALERTS
========================================================= */

.alert {
    padding: 13px 16px;
    margin-bottom: 20px;

    border-radius: 10px;

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

/* =========================================================
   SUMMARY CARDS
========================================================= */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 15px;

    margin-bottom: 24px;
}

.summary-card {
    display: flex;
    align-items: center;
    gap: 12px;

    min-width: 0;
    padding: 18px;

    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;

    box-shadow: 0 3px 12px #412b1a08;
}

.summary-icon {
    display: grid;
    place-items: center;

    width: 43px;
    height: 43px;
    min-width: 43px;

    border-radius: 12px;

    font-size: 19px;
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
    margin-bottom: 4px;

    color: var(--muted);
    font-size: 10px;
    font-weight: 700;
}

.summary-info h3 {
    margin: 0;

    color: #443326;
    font-size: 23px;
    font-weight: 800;
}

/* =========================================================
   CARDS
========================================================= */

.card {
    padding: 23px;
    margin-bottom: 22px;

    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;

    box-shadow: 0 3px 12px #412b1a08;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;

    margin-bottom: 20px;
}

.card-header h3 {
    margin: 0;

    font-size: 17px;
    font-weight: 800;
    color: #493426;
}

.card-description {
    margin: 6px 0 0;

    color: #988b80;
    font-size: 12px;
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

/* =========================================================
   FORMS
========================================================= */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 18px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;

    font-size: 12px;
    font-weight: 700;
    color: #514235;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 12px 13px;

    border: 1px solid #ded5cb;
    border-radius: 9px;

    font-family: inherit;
    font-size: 13px;
    color: #382d24;

    background: #fff;
    outline: none;
}

.form-group input:focus,
.form-group select:focus {
    border-color: #9b704c;
    box-shadow: 0 0 0 3px #8057351a;
}

.form-hint {
    margin-top: 6px;

    color: #988b80;
    font-size: 10px;
}

.form-actions,
.edit-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;

    margin-top: 20px;
}

.button {
    border: 0;
    cursor: pointer;

    font-family: inherit;
    font-weight: 700;

    transition: .2s;
}

.add-btn {
    padding: 12px 20px;

    background: #6d8b52;
    color: white;

    border-radius: 9px;
    font-size: 12px;
}

.add-btn:hover {
    background: #5c7744;
}

.edit-btn {
    padding: 9px 13px;

    background: #416d9e;
    color: white;

    border-radius: 7px;
    font-size: 11px;
}

.activate-btn {
    padding: 9px 12px;

    background: #198754;
    color: white;

    border-radius: 7px;
    font-size: 11px;
}

.deactivate-btn {
    padding: 9px 12px;

    background: #bd5148;
    color: white;

    border-radius: 7px;
    font-size: 11px;
}

.cancel-btn {
    padding: 11px 17px;

    background: #77716b;
    color: white;

    border-radius: 8px;
    font-size: 12px;
}

/* =========================================================
   TABLE
========================================================= */

.table-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;

    margin-bottom: 15px;
}

.table-count {
    color: var(--muted);
    font-size: 11px;
}

.search-box {
    position: relative;

    width: 100%;
    max-width: 300px;
}

.search-box input {
    width: 100%;
    padding: 11px 13px 11px 37px;

    border: 1px solid #e4dcd3;
    border-radius: 9px;

    background: #fff;

    font: 12px 'DM Sans', sans-serif;
    outline: none;
}

.search-box input:focus {
    border-color: #9b704c;
    box-shadow: 0 0 0 3px #8057351a;
}

.search-box:before {
    content: '⌕';

    position: absolute;
    left: 13px;
    top: 7px;

    font-size: 19px;
    color: #97887a;
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

th,
td {
    padding: 14px 12px;

    text-align: left;
    border-bottom: 1px solid #eee9e3;

    font-size: 11px;
}

th {
    background: #f8f5f1;
    color: #72563f;

    font-size: 9px;
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
    display: grid;
    place-items: center;

    width: 37px;
    height: 37px;
    min-width: 37px;

    border-radius: 50%;

    background: #eee2d6;
    color: #77543a;

    font-size: 13px;
    font-weight: 800;

    overflow: hidden;
}

.user-table-avatar img {
    width: 100%;
    height: 100%;

    object-fit: cover;
}

.user-cell-info strong {
    display: block;

    color: #493426;
    font-size: 11px;
}

.user-cell-info span {
    display: block;
    margin-top: 3px;

    color: #a0958b;
    font-size: 9px;
}

.role-badge,
.status {
    display: inline-block;

    padding: 6px 10px;

    border-radius: 20px;

    font-size: 9px;
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
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

.actions form {
    margin: 0;
}

/* =========================================================
   PROFILE PHOTO
========================================================= */

.photo-editor {
    grid-column: 1 / -1;

    padding: 15px;

    border: 1px solid #eadfd4;
    border-radius: 13px;

    background: #fbf8f5;
}

.photo-editor-title {
    margin-bottom: 11px;

    font-size: 12px;
    font-weight: 800;
    color: #5b402b;
}

.photo-editor-content {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.photo-preview {
    display: grid;
    place-items: center;

    width: 105px;
    height: 105px;

    border-radius: 15px;

    background: #eadbcb;
    color: #785637;

    font-size: 26px;
    font-weight: 800;

    overflow: hidden;
    flex-shrink: 0;

    border: 2px solid white;
    box-shadow: 0 2px 8px #37231412;
}

.photo-preview img {
    width: 100%;
    height: 100%;

    object-fit: cover;
}

.photo-controls {
    flex: 1;
    min-width: 200px;
}

.photo-controls input[type="file"] {
    display: block;

    width: 100%;
    max-width: 400px;
    padding: 10px;

    border: 1px dashed #cdb9a6;
    border-radius: 9px;

    background: white;
    font-size: 11px;
}

.photo-controls small {
    display: block;
    margin-top: 7px;

    color: #988b80;
    font-size: 10px;
}

.remove-photo-label {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    margin-top: 10px;

    color: #a4473d;
    font-size: 11px;
    font-weight: 700;
}

.remove-photo-label input {
    accent-color: #b64b40;
}

/* =========================================================
   EMPTY STATE
========================================================= */

.empty-state {
    padding: 35px 15px;

    text-align: center;
    color: var(--muted);
}

.empty-state-icon {
    display: block;
    margin-bottom: 10px;

    font-size: 35px;
}

.empty-state strong {
    display: block;

    color: #594738;
    font-size: 13px;
}

/* =========================================================
   FOOTER
========================================================= */

.page-footer {
    padding: 8px 0 20px;

    text-align: center;
    color: #a0958b;

    font-size: 10px;
}

/* =========================================================
   RESPONSIVE DESIGN
========================================================= */

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
        grid-template-columns: repeat(3, minmax(0, 1fr));
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

    .brand-text h2 {
        font-size: 18px;
    }

    .brand-text p {
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

    .summary-grid {
        gap: 10px;
    }

    .summary-card {
        padding: 16px;
    }

    .summary-info h3 {
        font-size: 21px;
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

    .page-title p {
        font-size: 11px;
    }

    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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
        font-size: 16px;
    }

    .summary-info span {
        font-size: 9px;
    }

    .summary-info h3 {
        font-size: 20px;
    }

    .card {
        padding: 16px;
        border-radius: 12px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .card-header h3 {
        font-size: 15px;
    }

    .search-box {
        max-width: none;
    }

    .photo-preview {
        width: 88px;
        height: 88px;
    }

    .date-label {
        font-size: 10px;
    }
}

@media (max-width: 360px) {

    .nav-menu {
        grid-template-columns: 1fr;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>

</head>

<body>

<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar">

    <div class="brand">

        <span class="brand-icon">🥐</span>

        <div class="brand-text">
            <h2>Bakery System</h2>
            <p>SALES &amp; INVENTORY</p>
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

        <a href="categories.php" class="nav-link">
            <span class="nav-icon">▤</span>
            Categories
        </a>

        <a href="inventory.php" class="nav-link">
            <span class="nav-icon">📦</span>
            Inventory
        </a>

        <a href="sales.php" class="nav-link">
            <span class="nav-icon">🛒</span>
            Sales / POS
        </a>

        <a href="reports.php" class="nav-link">
            <span class="nav-icon">📊</span>
            Reports
        </a>

        <a href="user_management.php"
           class="nav-link active"
           aria-current="page">

            <span class="nav-icon">👥</span>
            User Management

        </a>

    </nav>

    <div class="sidebar-bottom">

        <a href="profile.php"
           class="sidebar-profile-link"
           title="My Profile">

            <div class="sidebar-profile-avatar">

                <?php if (!empty($currentUserPhoto)): ?>

                    <img src="<?= e($currentUserPhoto) ?>"
                         alt="Profile picture">

                <?php else: ?>

                    <?= e(
                        strtoupper(
                            substr(
                                $_SESSION['full_name'] ?? 'U',
                                0,
                                1
                            )
                        )
                    ) ?>

                <?php endif; ?>

            </div>

            <div class="sidebar-profile-details">

                <strong><?= $name ?></strong>

                <span>admin · My Profile</span>

            </div>

        </a>

        <a href="../logout.php"
           class="logout-link"
           onclick="return confirm('Are you sure you want to logout?');">

            <span class="nav-icon">↪</span>
            Logout

        </a>

    </div>

</aside>

<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<main class="main">

    <div class="content">

        <!-- TOPBAR -->

        <header class="topbar">

            <div class="page-title">

                <h1>User Management</h1>

                <p>
                    Manage system accounts, roles,
                    profile photos, and user access.
                </p>

            </div>

            <a href="profile.php" class="topbar-user">

                <div class="avatar">

                    <?php if ($currentUserPhoto): ?>

                        <img src="<?= e($currentUserPhoto) ?>"
                             alt="Profile photo">

                    <?php else: ?>

                        <?= e(
                            strtoupper(
                                substr(
                                    $_SESSION['full_name'] ?? 'U',
                                    0,
                                    1
                                )
                            )
                        ) ?>

                    <?php endif; ?>

                </div>

                <div class="user-info">

                    <strong><?= $name ?></strong>

                    <span>Administrator</span>

                </div>

            </a>

        </header>

        <!-- DATE -->

        <section class="heading">

            <div class="date-label">
                📅 <?= date('F d, Y') ?>
            </div>

        </section>

        <!-- ALERTS -->

        <?php if ($message): ?>

            <div class="alert success">
                ✓ <?= e($message) ?>
            </div>

        <?php endif; ?>

        <?php if ($error): ?>

            <div class="alert error">
                ⚠ <?= e($error) ?>
            </div>

        <?php endif; ?>

        <!-- SUMMARY CARDS -->

        <div class="summary-grid">

            <div class="summary-card">

                <div class="summary-icon icon-brown">
                    👥
                </div>

                <div class="summary-info">
                    <span>Total Users</span>
                    <h3><?= number_format($totalUsers) ?></h3>
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-icon icon-green">
                    ✓
                </div>

                <div class="summary-info">
                    <span>Active Users</span>
                    <h3><?= number_format($activeUsers) ?></h3>
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-icon icon-red">
                    ⊘
                </div>

                <div class="summary-info">
                    <span>Inactive Users</span>
                    <h3><?= number_format($inactiveUsers) ?></h3>
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-icon icon-gold">
                    ♙
                </div>

                <div class="summary-info">
                    <span>Administrators</span>
                    <h3><?= number_format($adminUsers) ?></h3>
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-icon icon-blue">
                    ♧
                </div>

                <div class="summary-info">
                    <span>Staff Members</span>
                    <h3><?= number_format($staffUsers) ?></h3>
                </div>

            </div>

        </div>

        <!-- =================================================
             ADD USER
        ================================================== -->

        <section class="card">

            <div class="card-header">

                <div>

                    <h3>+ Add New User</h3>

                    <p class="card-description">
                        Create a new account and optionally
                        add a profile photo.
                    </p>

                </div>

                <span class="section-tag">
                    ACCOUNT CREATION
                </span>

            </div>

            <form method="POST"
                  id="addUserForm"
                  enctype="multipart/form-data"
                  autocomplete="off">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">

                <input type="hidden"
                       name="action"
                       value="add">

                <input type="text"
                       name="dummy_username"
                       autocomplete="username"
                       tabindex="-1"
                       aria-hidden="true"
                       style="position:absolute;left:-9999px">

                <input type="password"
                       name="dummy_password"
                       autocomplete="current-password"
                       tabindex="-1"
                       aria-hidden="true"
                       style="position:absolute;left:-9999px">

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

                            <option value="staff" selected>
                                Staff
                            </option>

                            <option value="admin">
                                Admin
                            </option>

                        </select>

                        <div class="form-hint">
                            Select the access level for this account.
                        </div>

                    </div>

                    <!-- PROFILE PHOTO -->

                    <div class="photo-editor">

                        <div class="photo-editor-title">

                            Profile Photo

                            <span style="font-weight:400;color:#988b80">
                                (Optional)
                            </span>

                        </div>

                        <div class="photo-editor-content">

                            <div class="photo-preview"
                                 id="addPhotoPreview">

                                👤

                            </div>

                            <div class="photo-controls">

                                <input type="file"
                                       name="profile_picture"
                                       id="add_profile_picture"
                                       class="file-input"
                                       accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">

                                <small>
                                    JPG, PNG, or WEBP · Maximum 2MB.
                                    Preview appears after selecting a photo.
                                </small>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="form-actions">

                    <button type="submit"
                            class="button add-btn">

                        + Create Account

                    </button>

                </div>

            </form>

        </section>

        <!-- =================================================
             REGISTERED USERS
        ================================================== -->

        <section class="card">

            <div class="card-header">

                <div>

                    <h3>Registered Users</h3>

                    <p class="card-description">
                        View and manage all registered system accounts.
                    </p>

                </div>

                <span class="section-tag">

                    <?= number_format($totalUsers) ?>
                    ACCOUNTS

                </span>

            </div>

            <div class="table-toolbar">

                <div class="table-count" id="tableCount">

                    Showing
                    <?= number_format($totalUsers) ?>
                    registered account(s)

                </div>

                <div class="search-box">

                    <input type="search"
                           id="userSearch"
                           placeholder="Search name, username, role, status..."
                           aria-label="Search users">

                </div>

            </div>

            <div class="table-wrapper">

                <table id="usersTable">

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

                    <?php if ($users): ?>

                        <?php foreach ($users as $user): ?>

                            <?php

                            $photo = profilePhotoUrl(
                                $user['profile_picture'] ?? null
                            );

                            $initial = strtoupper(
                                substr(
                                    trim($user['full_name']),
                                    0,
                                    1
                                )
                            );

                            ?>

                            <tr>

                                <td>
                                    #<?= e($user['user_id']) ?>
                                </td>

                                <td>

                                    <div class="user-cell">

                                        <div class="user-table-avatar">

                                            <?php if ($photo): ?>

                                                <img src="<?= e($photo) ?>"
                                                     alt="<?= e($user['full_name']) ?> profile photo">

                                            <?php else: ?>

                                                <?= e($initial) ?>

                                            <?php endif; ?>

                                        </div>

                                        <div class="user-cell-info">

                                            <strong>
                                                <?= e($user['full_name']) ?>
                                            </strong>

                                            <span>User Account</span>

                                        </div>

                                    </div>

                                </td>

                                <td>
                                    <?= e($user['username']) ?>
                                </td>

                                <td>

                                    <span class="role-badge
                                        <?= $user['role'] === 'admin'
                                            ? 'role-admin'
                                            : 'role-staff' ?>">

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

                                        <button type="button"
                                                class="button edit-btn"
                                                onclick='editUser(<?= json_encode(
                                                    [
                                                        "user_id" => $user["user_id"],
                                                        "full_name" => $user["full_name"],
                                                        "username" => $user["username"],
                                                        "role" => $user["role"],
                                                        "profile_picture" => $photo,
                                                        "has_photo" => (bool) $photo
                                                    ],
                                                    JSON_HEX_TAG |
                                                    JSON_HEX_APOS |
                                                    JSON_HEX_AMP |
                                                    JSON_HEX_QUOT
                                                ) ?>)'>

                                            Edit

                                        </button>

                                        <?php if (
                                            (int) $user['user_id'] !==
                                            (int) $_SESSION['user_id']
                                        ): ?>

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

                                                <?php if (
                                                    $user['status'] === 'active'
                                                ): ?>

                                                    <button type="submit"
                                                            class="button deactivate-btn">

                                                        Deactivate

                                                    </button>

                                                <?php else: ?>

                                                    <button type="submit"
                                                            class="button activate-btn">

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

                                    <span class="empty-state-icon">
                                        👥
                                    </span>

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

                <div class="empty-state"
                     id="searchEmpty"
                     style="display:none">

                    <strong>No matching users.</strong>

                    <p>
                        Try a different search keyword.
                    </p>

                </div>

            </div>

        </section>

        <!-- =================================================
             EDIT USER
        ================================================== -->

        <section class="card"
                 id="editSection"
                 style="display:none">

            <div class="card-header">

                <div>

                    <h3>Edit User Account</h3>

                    <p class="card-description">
                        Update account information and profile photo.
                    </p>

                </div>

                <span class="section-tag">
                    ACCOUNT SETTINGS
                </span>

            </div>

            <form method="POST"
                  enctype="multipart/form-data"
                  autocomplete="off">

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

                        <select name="role"
                                id="edit_role"
                                required>

                            <option value="staff">
                                Staff
                            </option>

                            <option value="admin">
                                Admin
                            </option>

                        </select>

                    </div>

                    <!-- PROFILE PHOTO -->

                    <div class="photo-editor">

                        <div class="photo-editor-title">
                            Profile Photo
                        </div>

                        <div class="photo-editor-content">

                            <div class="photo-preview"
                                 id="editPhotoPreview">

                                👤

                            </div>

                            <div class="photo-controls">

                                <input type="file"
                                       name="profile_picture"
                                       id="edit_profile_picture"
                                       class="file-input"
                                       accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">

                                <small>
                                    Choose a new photo to replace the current one.
                                    JPG, PNG, or WEBP · Maximum 2MB.
                                </small>

                                <label class="remove-photo-label"
                                       id="removePhotoLabel"
                                       style="display:none">

                                    <input type="checkbox"
                                           name="remove_picture"
                                           value="1"
                                           id="remove_picture">

                                    Remove current profile photo

                                </label>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="edit-actions">

                    <button type="submit"
                            class="button edit-btn">

                        Save Changes

                    </button>

                    <button type="button"
                            class="button cancel-btn"
                            onclick="closeEdit()">

                        Cancel

                    </button>

                </div>

            </form>

        </section>

        <!-- FOOTER -->

        <footer class="page-footer">

            Bakery System &copy; <?= date('Y') ?>
            | User Management

        </footer>

    </div>

</main>

<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

/* =========================================================
   RESET PAGE SCROLL POSITION
========================================================= */

window.addEventListener('load', function () {

    window.scrollTo({
        top: 0,
        left: 0,
        behavior: 'instant'
    });

});

/* =========================================================
   PROFILE PHOTO PREVIEW
========================================================= */

function previewFile(input, preview, fallback) {

    const file = input.files && input.files[0];

    if (!file) {
        preview.innerHTML = fallback;
        return;
    }

    if (file.size > 2 * 1024 * 1024) {

        alert('Image must be no more than 2MB.');

        input.value = '';

        preview.innerHTML = fallback;

        return;
    }

    const allowed = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (!allowed.includes(file.type)) {

        alert(
            'Please select a JPG, PNG, or WEBP image.'
        );

        input.value = '';

        preview.innerHTML = fallback;

        return;
    }

    const reader = new FileReader();

    reader.onload = function (e) {

        preview.innerHTML =
            '<img src="' +
            e.target.result +
            '" alt="Selected profile photo preview">';

    };

    reader.readAsDataURL(file);
}

/* Add User Photo */

document
    .getElementById('add_profile_picture')
    .addEventListener('change', function () {

        previewFile(
            this,
            document.getElementById('addPhotoPreview'),
            '👤'
        );

    });

/* Edit User Photo */

const editInput = document.getElementById(
    'edit_profile_picture'
);

editInput.addEventListener('change', function () {

    previewFile(
        this,
        document.getElementById('editPhotoPreview'),
        '👤'
    );

    if (this.files.length) {

        document.getElementById(
            'remove_picture'
        ).checked = false;

    }

});

/* =========================================================
   EDIT USER
========================================================= */

function editUser(user) {

    document.getElementById('edit_user_id').value =
        user.user_id;

    document.getElementById('edit_full_name').value =
        user.full_name;

    document.getElementById('edit_username').value =
        user.username;

    document.getElementById('edit_role').value =
        user.role;

    editInput.value = '';

    document.getElementById('remove_picture').checked =
        false;

    document.getElementById('removePhotoLabel').style.display =
        user.has_photo
            ? 'inline-flex'
            : 'none';

    document.getElementById('editPhotoPreview').innerHTML =
        user.profile_picture
            ? '<img src="' +
                user.profile_picture +
                '" alt="Current profile photo">'
            : '👤';

    const section = document.getElementById(
        'editSection'
    );

    section.style.display = 'block';

    section.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });

}

/* =========================================================
   CLOSE EDIT FORM
========================================================= */

function closeEdit() {

    document.getElementById(
        'editSection'
    ).style.display = 'none';

}

/* =========================================================
   ADD USER FORM RESET
========================================================= */

document
    .getElementById('addUserForm')
    .addEventListener('submit', function () {

        const form = this;

        setTimeout(function () {
            form.reset();
        }, 0);

    });

/* =========================================================
   SEARCH USERS
========================================================= */

const search = document.getElementById('userSearch');

const rows = [
    ...document.querySelectorAll(
        '#usersTable tbody tr'
    )
];

const count = document.getElementById('tableCount');

const empty = document.getElementById('searchEmpty');

search.addEventListener('input', function () {

    const q = this.value.trim().toLowerCase();

    let shown = 0;

    rows.forEach(function (row) {

        const match = row.textContent
            .toLowerCase()
            .includes(q);

        row.style.display = match ? '' : 'none';

        if (match) {
            shown++;
        }

    });

    count.textContent =
        'Showing ' +
        shown +
        ' matching account(s)';

    empty.style.display =
        shown === 0 && rows.length > 0
            ? 'block'
            : 'none';

});

</script>

</body>
</html>