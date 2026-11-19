
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

// GET LOGGED-IN USER PROFILE PICTURE
$userId = (int) $_SESSION['user_id'];
$profilePicture = null;

$userStmt = $conn->prepare(
    "SELECT profile_picture
     FROM users
     WHERE user_id = ?"
);
$userStmt->execute([$userId]);
$userProfile = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!empty($userProfile['profile_picture'])) {
    $profileFilename = basename($userProfile['profile_picture']);
    $profileFilePath = __DIR__ . '/../uploads/profiles/' . $profileFilename;

    if (
        strpos($userProfile['profile_picture'], 'uploads/profiles/') === 0
        && is_file($profileFilePath)
    ) {
        $profilePicture = '../uploads/profiles/' . rawurlencode($profileFilename);
    }
}

$role = $_SESSION['role'] ?? 'staff';
$isAdmin = ($role === 'admin');

$message = '';
$messageType = 'success';

$formName = '';
$formDescription = '';

$uploadDirectory = __DIR__ . '/../uploads/categories/';
$uploadDatabasePath = 'uploads/categories/';

// CREATE UPLOAD DIRECTORY
if (!is_dir($uploadDirectory)) {
    mkdir($uploadDirectory, 0755, true);
}

// UPLOAD CATEGORY IMAGE
function uploadCategoryImage($file, $uploadDirectory)
{
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Image upload failed. Please try again.");
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException("Image must not exceed 5MB.");
    }

    $imageInfo = getimagesize($file['tmp_name']);

    if ($imageInfo === false) {
        throw new RuntimeException("Please upload a valid image.");
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    $mimeType = $imageInfo['mime'];

    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException(
            "Only JPG, PNG, and WEBP images are allowed."
        );
    }

    $extension = $allowedTypes[$mimeType];
    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $uploadDirectory . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException("Unable to save the uploaded image.");
    }

    return $fileName;
}

// DELETE CATEGORY IMAGE
function deleteCategoryImage($imagePath, $uploadDirectory)
{
    if (empty($imagePath)) {
        return;
    }

    $fileName = basename($imagePath);
    $fullPath = $uploadDirectory . $fileName;

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

// NOTIFICATIONS
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

    $newImageFile = null;

    if ($formName === '') {
        $message = "Category name is required!";
        $messageType = 'error';
    } else {
        try {
            $newImageFile = uploadCategoryImage(
                $_FILES['category_image'] ?? null,
                $uploadDirectory
            );

            if ($newImageFile === null) {
                throw new RuntimeException(
                    "Please select a category image."
                );
            }

            $imagePath = $uploadDatabasePath . $newImageFile;

            $stmt = $conn->prepare(
                "INSERT INTO categories
                    (category_name, description, category_image)
                 VALUES (?, ?, ?)"
            );

            $stmt->execute([
                $formName,
                $formDescription,
                $imagePath
            ]);

            header("Location: categories.php?added=1");
            exit;

        } catch (Throwable $e) {
            if ($newImageFile !== null) {
                deleteCategoryImage(
                    $newImageFile,
                    $uploadDirectory
                );
            }

            $message = $e instanceof RuntimeException
                ? $e->getMessage()
                : "Unable to add category. It may already exist.";

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

    $newImageFile = null;

    if ($id <= 0 || $formName === '') {
        $message = "Invalid category information!";
        $messageType = 'error';
    } else {
        try {
            // GET CURRENT IMAGE
            $currentStmt = $conn->prepare(
                "SELECT category_image
                 FROM categories
                 WHERE category_id = ?"
            );

            $currentStmt->execute([$id]);
            $currentCategory = $currentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentCategory) {
                throw new RuntimeException("Category not found.");
            }

            // IMAGE IS OPTIONAL WHEN EDITING
            $newImageFile = uploadCategoryImage(
                $_FILES['category_image'] ?? null,
                $uploadDirectory
            );

            if ($newImageFile !== null) {
                $newImagePath = $uploadDatabasePath . $newImageFile;

                $stmt = $conn->prepare(
                    "UPDATE categories
                     SET category_name = ?,
                         description = ?,
                         category_image = ?
                     WHERE category_id = ?"
                );

                $stmt->execute([
                    $formName,
                    $formDescription,
                    $newImagePath,
                    $id
                ]);

                // DELETE OLD IMAGE AFTER SUCCESSFUL UPDATE
                deleteCategoryImage(
                    $currentCategory['category_image'],
                    $uploadDirectory
                );

            } else {
                // KEEP EXISTING IMAGE
                $stmt = $conn->prepare(
                    "UPDATE categories
                     SET category_name = ?,
                         description = ?
                     WHERE category_id = ?"
                );

                $stmt->execute([
                    $formName,
                    $formDescription,
                    $id
                ]);
            }

            header("Location: categories.php?updated=1");
            exit;

        } catch (Throwable $e) {
            if ($newImageFile !== null) {
                deleteCategoryImage(
                    $newImageFile,
                    $uploadDirectory
                );
            }

            $message = $e instanceof RuntimeException
                ? $e->getMessage()
                : "Unable to update category.";

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
            throw new RuntimeException("Invalid category.");
        }

        $check = $conn->prepare(
            "SELECT COUNT(*)
             FROM products
             WHERE category_id = ?"
        );

        $check->execute([$id]);

        if ((int)$check->fetchColumn() > 0) {
            $message = "Cannot delete: This category is assigned to products.";
            $messageType = 'error';
        } else {
            // GET IMAGE BEFORE DELETING CATEGORY
            $imageStmt = $conn->prepare(
                "SELECT category_image
                 FROM categories
                 WHERE category_id = ?"
            );

            $imageStmt->execute([$id]);
            $categoryToDelete = $imageStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare(
                "DELETE FROM categories
                 WHERE category_id = ?"
            );

            $stmt->execute([$id]);

            if ($categoryToDelete) {
                deleteCategoryImage(
                    $categoryToDelete['category_image'],
                    $uploadDirectory
                );
            }

            header("Location: categories.php?deleted=1");
            exit;
        }

    } catch (Throwable $e) {
        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : "Unable to delete category.";

        $messageType = 'error';
    }
}

// GET CATEGORY FOR EDITING
$editCategory = null;

if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];

    $stmt = $conn->prepare(
        "SELECT *
         FROM categories
         WHERE category_id = ?"
    );

    $stmt->execute([$id]);
    $editCategory = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editCategory) {
        $message = "Category not found.";
        $messageType = 'error';
    }
}

// PRESERVE FORM VALUES WHEN UPDATE FAILS
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update' &&
    isset($_POST['category_id'])
) {
    $editId = (int)$_POST['category_id'];

    $stmt = $conn->prepare(
        "SELECT *
         FROM categories
         WHERE category_id = ?"
    );

    $stmt->execute([$editId]);
    $editCategory = $stmt->fetch(PDO::FETCH_ASSOC);
}

// GET ALL CATEGORIES
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

// DEFAULT IMAGE
$defaultImage = 'https://images.unsplash.com/photo-1509440159596-0249088772ff?w=500';
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
    display: block;
    object-fit: cover;
    border-radius: 50%;
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

/* SECTION */

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

/* STATISTICS */

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

/* IMAGE UPLOAD */

.image-upload-box {
    padding: 15px;
    background: #faf8f5;
    border: 1px dashed #cbb8a5;
    border-radius: 10px;
}

.image-upload-box input[type="file"] {
    padding: 10px;
    background: white;
    cursor: pointer;
}

.image-help {
    margin: 8px 0 0;
    color: var(--muted);
    font-size: 10px;
}

.image-preview-wrapper {
    margin-top: 13px;
}

.image-preview {
    display: block;
    width: 190px;
    height: 135px;
    object-fit: cover;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: #eee5dc;
}

.preview-label {
    display: block;
    margin-bottom: 7px;
    color: var(--muted);
    font-size: 10px;
    font-weight: 700;
}

.current-image-note {
    margin-top: 8px;
    color: #34734a;
    font-size: 10px;
    font-weight: 600;
}

/* FORM BUTTONS */

.form-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 9px;
    margin-top: 18px;
}

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

/* CATEGORY GALLERY */

.category-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
}

.category-card {
    min-width: 0;
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(55,35,18,.04);
    transition: .2s ease;
}

.category-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(55,35,18,.10);
}

.category-image {
    position: relative;
    height: 165px;
    overflow: hidden;
    background: #eee5dc;
}

.category-image img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform .35s ease;
    cursor: zoom-in;
}

.category-card:hover .category-image img {
    transform: scale(1.06);
}

.category-image-label {
    position: absolute;
    left: 12px;
    bottom: 12px;
    padding: 6px 11px;
    color: white;
    background: rgba(66,41,24,.82);
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    pointer-events: none;
}

.category-card-body {
    padding: 15px;
}

.category-card-title {
    margin: 0;
    color: var(--brown);
    font-size: 15px;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.category-card-description {
    min-height: 35px;
    margin: 8px 0 12px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.category-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    padding-top: 11px;
    border-top: 1px solid var(--border);
}

.category-product-count {
    padding: 6px 9px;
    color: var(--brown);
    background: #f2e7db;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
}

.category-empty-count {
    color: #9a672d;
    background: #fff0db;
}

.category-card-actions {
    display: flex;
    gap: 5px;
}

.category-card-actions form {
    margin: 0;
}

.category-card-actions .btn {
    padding: 7px 9px;
    font-size: 10px;
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

.table-category-image {
    width: 55px;
    height: 45px;
    object-fit: cover;
    border-radius: 7px;
    background: #eee5dc;
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

/* IMAGE ZOOM MODAL */

.image-zoom-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 25px;
    background: rgba(25, 17, 12, 0.88);
    backdrop-filter: blur(5px);
}

.image-zoom-modal.active {
    display: flex;
}

.zoom-content {
    position: relative;
    width: 100%;
    max-width: 750px;
    text-align: center;
    animation: zoomIn .25s ease;
}

.zoom-content img {
    display: block;
    max-width: 100%;
    max-height: 75vh;
    margin: auto;
    object-fit: contain;
    border-radius: 13px;
    box-shadow: 0 10px 40px rgba(0,0,0,.35);
}

.zoom-title {
    margin-top: 15px;
    color: white;
    font-family: 'Playfair Display', serif;
    font-size: 22px;
}

.zoom-close {
    position: absolute;
    top: -18px;
    right: -18px;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    color: white;
    background: #9c4938;
    border: none;
    border-radius: 50%;
    font-size: 23px;
    cursor: pointer;
    transition: .2s;
}

.zoom-close:hover {
    background: #c45b45;
    transform: rotate(90deg);
}

@keyframes zoomIn {
    from {
        opacity: 0;
        transform: scale(.85);
    }

    to {
        opacity: 1;
        transform: scale(1);
    }
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

    .stats-grid {
        gap: 10px;
    }

    .stat-card {
        padding: 16px;
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

    .category-grid {
        grid-template-columns: 1fr;
    }

    .category-image {
        height: 190px;
    }

    .zoom-close {
        top: -15px;
        right: -8px;
    }

    .zoom-title {
        font-size: 19px;
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
            <p>Organize and manage your bakery product categories.</p>
        </div>

        <div class="user-info">

            <div class="user-avatar">
                <?php if ($profilePicture): ?>
                    <img
                        src="<?= htmlspecialchars($profilePicture, ENT_QUOTES, 'UTF-8') ?>"
                        alt="Profile picture"
                    >
                <?php else: ?>
                    <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
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
                        ? 'Modify the information and image of your selected category.'
                        : 'Enter category details and upload a category image.' ?>
                </p>

            </div>

            <span style="font-size:25px;">🧁</span>

        </div>

        <?php if ($editCategory): ?>

            <!-- EDIT CATEGORY FORM -->

            <form method="POST" enctype="multipart/form-data">

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

                    <div class="form-group full">

                        <label for="category_image">Category Image</label>

                        <div class="image-upload-box">

                            <input
                                type="file"
                                id="category_image"
                                name="category_image"
                                accept="image/jpeg,image/png,image/webp"
                            >

                            <p class="image-help">
                                Upload JPG, PNG, or WEBP. Maximum file size: 5MB.
                                Leave blank to keep the current image.
                            </p>

                            <div class="image-preview-wrapper">

                                <span class="preview-label">
                                    CURRENT / NEW IMAGE PREVIEW
                                </span>

                                <img
                                    src="<?= !empty($editCategory['category_image'])
                                        ? '../' . htmlspecialchars($editCategory['category_image'], ENT_QUOTES, 'UTF-8')
                                        : $defaultImage ?>"
                                    alt="Category image preview"
                                    class="image-preview"
                                    id="imagePreview"
                                >

                                <?php if (!empty($editCategory['category_image'])): ?>
                                    <div class="current-image-note">
                                        ✓ Current category image
                                    </div>
                                <?php endif; ?>

                            </div>

                        </div>

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

            <!-- ADD CATEGORY FORM -->

            <form method="POST" enctype="multipart/form-data">

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

                    <div class="form-group full">

                        <label for="category_image">
                            Category Image <span style="color:#b83232;">*</span>
                        </label>

                        <div class="image-upload-box">

                            <input
                                type="file"
                                id="category_image"
                                name="category_image"
                                accept="image/jpeg,image/png,image/webp"
                                required
                            >

                            <p class="image-help">
                                Choose an image from your computer.
                                JPG, PNG, or WEBP only. Maximum 5MB.
                            </p>

                            <div class="image-preview-wrapper">

                                <span class="preview-label">
                                    IMAGE PREVIEW
                                </span>

                                <img
                                    src="<?= $defaultImage ?>"
                                    alt="Category image preview"
                                    class="image-preview"
                                    id="imagePreview"
                                >

                            </div>

                        </div>

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

    <!-- CATEGORY GALLERY -->

    <div class="section-heading">

        <h2>Category Gallery</h2>
        <span>Double-click an image to zoom</span>

    </div>

    <section class="panel">

        <div class="panel-header">

            <div>
                <h2>Bakery Categories</h2>
                <p>Double-click any category image to view it in a larger preview.</p>
            </div>

            <span class="category-count">
                🧁 <?= number_format($totalCategories) ?> Categories
            </span>

        </div>

        <?php if ($categories): ?>

            <div class="category-grid">

                <?php foreach ($categories as $category): ?>

                    <?php
                        $categoryImage = !empty($category['category_image'])
                            ? '../' . $category['category_image']
                            : $defaultImage;

                        $productCount = (int)$category['product_count'];
                    ?>

                    <article class="category-card">

                        <div class="category-image">

                            <img
                                src="<?= htmlspecialchars(
                                    $categoryImage,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                alt="<?= htmlspecialchars(
                                    $category['category_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                loading="lazy"
                                onerror="this.onerror=null;this.src='<?= htmlspecialchars($defaultImage, ENT_QUOTES, 'UTF-8') ?>';"
                            >

                            <span class="category-image-label">
                                #<?= (int)$category['category_id'] ?>
                            </span>

                        </div>

                        <div class="category-card-body">

                            <h3 class="category-card-title">
                                <?= htmlspecialchars(
                                    $category['category_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </h3>

                            <p class="category-card-description">
                                <?= htmlspecialchars(
                                    $category['description'] ?: 'No description available.',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </p>

                            <div class="category-card-footer">

                                <span class="category-product-count <?= $productCount === 0
                                    ? 'category-empty-count'
                                    : '' ?>">

                                    📦 <?= number_format($productCount) ?>
                                    <?= $productCount === 1
                                        ? 'Product'
                                        : 'Products' ?>

                                </span>

                                <div class="category-card-actions">

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

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty">
                No categories found. Add your first category above.
            </div>

        <?php endif; ?>

    </section>

    <!-- CATEGORY TABLE -->

    <div class="section-heading">

        <h2>Category List</h2>
        <span>Manage your registered categories</span>

    </div>

    <section class="panel">

        <div class="panel-header">

            <div>
                <h2>Registered Categories</h2>
                <p>View, edit, and manage your bakery categories.</p>
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
                        <th>Image</th>
                        <th>Category Name</th>
                        <th>Description</th>
                        <th>Products</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($categories): ?>

                    <?php foreach ($categories as $category): ?>

                        <?php
                            $tableImage = !empty($category['category_image'])
                                ? '../' . $category['category_image']
                                : $defaultImage;
                        ?>

                        <tr>

                            <td class="category-id">
                                #<?= (int)$category['category_id'] ?>
                            </td>

                            <td>
                                <img
                                    src="<?= htmlspecialchars(
                                        $tableImage,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    alt="Category"
                                    class="table-category-image"
                                    loading="lazy"
                                    onerror="this.onerror=null;this.src='<?= htmlspecialchars($defaultImage, ENT_QUOTES, 'UTF-8') ?>';"
                                >
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
                        <td colspan="6" class="empty">
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

<!-- CATEGORY IMAGE ZOOM MODAL -->

<div
    class="image-zoom-modal"
    id="imageZoomModal"
    aria-hidden="true"
>

    <div class="zoom-content">

        <button
            type="button"
            class="zoom-close"
            id="zoomClose"
            aria-label="Close image preview"
        >
            &times;
        </button>

        <img
            src=""
            alt="Category Preview"
            id="zoomImage"
        >

        <h2 class="zoom-title" id="zoomTitle"></h2>

    </div>

</div>

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

// IMAGE LIVE PREVIEW

const imageInput = document.getElementById("category_image");
const imagePreview = document.getElementById("imagePreview");

if (imageInput && imagePreview) {

    imageInput.addEventListener("change", function() {

        const file = this.files[0];

        if (!file) {
            return;
        }

        const allowedTypes = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        if (!allowedTypes.includes(file.type)) {
            alert("Please select a JPG, PNG, or WEBP image.");
            this.value = "";
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert("Image must not exceed 5MB.");
            this.value = "";
            return;
        }

        const imageURL = URL.createObjectURL(file);
        imagePreview.src = imageURL;

    });

}

// CATEGORY IMAGE DOUBLE-CLICK ZOOM

const zoomModal = document.getElementById("imageZoomModal");
const zoomImage = document.getElementById("zoomImage");
const zoomTitle = document.getElementById("zoomTitle");
const zoomClose = document.getElementById("zoomClose");

// SELECT CATEGORY GALLERY IMAGES ONLY

document.querySelectorAll(".category-image img").forEach(image => {

    image.addEventListener("dblclick", function() {

        const categoryCard = this.closest(".category-card");

        if (!categoryCard) {
            return;
        }

        const categoryName = categoryCard.querySelector(
            ".category-card-title"
        ).textContent.trim();

        zoomImage.src = this.src;
        zoomImage.alt = categoryName;
        zoomTitle.textContent = categoryName;

        zoomModal.classList.add("active");
        zoomModal.setAttribute("aria-hidden", "false");

    });

});

// CLOSE MODAL FUNCTION

function closeImageZoom() {

    zoomModal.classList.remove("active");
    zoomModal.setAttribute("aria-hidden", "true");

    zoomImage.src = "";

}

// CLOSE USING X BUTTON

zoomClose.addEventListener("click", closeImageZoom);

// CLOSE WHEN CLICKING OUTSIDE IMAGE

zoomModal.addEventListener("click", function(event) {

    if (event.target === zoomModal) {
        closeImageZoom();
    }

});

// CLOSE USING ESC KEY

document.addEventListener("keydown", function(event) {

    if (
        event.key === "Escape" &&
        zoomModal.classList.contains("active")
    ) {
        closeImageZoom();
    }

});

</script>

</body>
</html>
