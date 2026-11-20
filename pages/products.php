
<?php
session_start();
require_once '../config/database.php';

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

// GET THE LOGGED-IN USER'S PROFILE PICTURE
$profilePictureUrl = null;

$userStmt = $conn->prepare(
    "SELECT profile_picture
     FROM users
     WHERE user_id = ?"
);
$userStmt->execute([(int)$_SESSION['user_id']]);

$userProfile = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!empty($userProfile['profile_picture'])) {
    $storedProfilePicture = $userProfile['profile_picture'];

    // Only allow images stored inside the profile uploads folder.
    if (strpos($storedProfilePicture, 'uploads/profiles/') === 0) {
        $profilePictureFilename = basename($storedProfilePicture);
        $profilePicturePath = __DIR__ . '/../uploads/profiles/' . $profilePictureFilename;

        if (is_file($profilePicturePath)) {
            $profilePictureUrl = '../uploads/profiles/' . rawurlencode($profilePictureFilename);
        }
    }
}

$message = '';
$messageType = 'success';
$editProduct = null;

// DATABASE-DRIVEN BEVERAGE PRODUCT TYPES
$conn->exec("CREATE TABLE IF NOT EXISTS beverage_product_types (
    type_id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$defaultBeverageTypes = [
    'Softdrinks',
    'Water',
    'Juice',
    'Ice Coffee',
    'Shake',
    'Coffee',
    'Milk Tea'
];

$seedTypeStmt = $conn->prepare("INSERT IGNORE INTO beverage_product_types (type_name) VALUES (?)");
foreach ($defaultBeverageTypes as $defaultType) {
    $seedTypeStmt->execute([$defaultType]);
}

$beverageTypeStmt = $conn->query("SELECT type_name FROM beverage_product_types ORDER BY type_name ASC");
$beverageTypes = $beverageTypeStmt->fetchAll(PDO::FETCH_COLUMN);

// ADD A NEW BEVERAGE TYPE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_type') {
    $newType = trim($_POST['new_product_type'] ?? '');

    if ($newType === '' || mb_strlen($newType) > 100) {
        header('Location: products.php?type_error=invalid');
        exit;
    }

    try {
        $addTypeStmt = $conn->prepare(
            "INSERT INTO beverage_product_types (type_name) VALUES (?)"
        );
        $addTypeStmt->execute([$newType]);
        header('Location: products.php?type_added=1');
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            header('Location: products.php?type_error=exists');
            exit;
        }
        throw $e;
    }
}

// IMAGE UPLOAD SETTINGS
$uploadDirectory = __DIR__ . '/../uploads/products/';
$uploadUrl = '../uploads/products/';
$maxFileSize = 5 * 1024 * 1024;

// CREATE UPLOAD DIRECTORY
if (!is_dir($uploadDirectory)) {
    mkdir($uploadDirectory, 0755, true);
}

// UPLOAD PRODUCT IMAGE
function uploadProductImage($file, $uploadDirectory, $maxFileSize)
{
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Image upload failed. Please try again.");
    }

    if ($file['size'] > $maxFileSize) {
        throw new Exception("Image must not exceed 5MB.");
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!isset($allowedMimeTypes[$mimeType])) {
        throw new Exception("Only JPG, PNG, and WEBP images are allowed.");
    }

    if (@getimagesize($file['tmp_name']) === false) {
        throw new Exception("The uploaded file is not a valid image.");
    }

    $extension = $allowedMimeTypes[$mimeType];
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;

    if (!move_uploaded_file(
        $file['tmp_name'],
        $uploadDirectory . $filename
    )) {
        throw new Exception("Unable to save the uploaded image.");
    }

    return $filename;
}

// DELETE PRODUCT IMAGE
function deleteProductImage($filename, $uploadDirectory)
{
    if (!$filename) {
        return;
    }

    $safeFilename = basename($filename);
    $path = $uploadDirectory . $safeFilename;

    if (is_file($path)) {
        @unlink($path);
    }
}

// CATEGORY ICON FALLBACK
function getCategoryIcon($categoryName)
{
    $name = strtolower($categoryName ?? '');

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

    return '🧁';
}

// GET CATEGORY IMAGE URL
function getCategoryImageUrl($categoryImage)
{
    if (empty($categoryImage)) {
        return null;
    }

    $imagePath = ltrim($categoryImage, '/');

    if (strpos($imagePath, 'uploads/categories/') !== 0) {
        $imagePath = 'uploads/categories/' . basename($imagePath);
    }

    return '../' . $imagePath;
}

// CHECK WHETHER CATEGORY IS BEVERAGES
function isBeveragesCategory($categoryName)
{
    return strcasecmp(trim((string)$categoryName), 'Beverages') === 0;
}

// NOTIFICATIONS
if (isset($_GET['type_added'])) {
    $message = "Beverage product type added successfully!";
} elseif (isset($_GET['type_error'])) {
    $message = $_GET['type_error'] === 'exists'
        ? "That product type already exists."
        : "Please enter a product type with 1–100 characters.";
    $messageType = 'error';
} elseif (isset($_GET['added'])) {
    $message = "Product added successfully!";
} elseif (isset($_GET['updated'])) {
    $message = "Product updated successfully!";
} elseif (isset($_GET['status_updated'])) {
    $message = "Product status updated successfully!";
}

// ADD PRODUCT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {

    $productName = trim($_POST['product_name'] ?? '');
    $category = (int)($_POST['category_id'] ?? 0);
    $productType = trim($_POST['product_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = $_POST['price'] ?? '';

    $uploadedImage = null;

    if (
        $productName === '' ||
        $category <= 0 ||
        !is_numeric($price) ||
        (float)$price <= 0
    ) {
        $message = "Please enter valid product information.";
        $messageType = 'error';

    } else {
        try {
            // GET SELECTED CATEGORY NAME
            $check = $conn->prepare(
                "SELECT category_name FROM categories WHERE category_id = ?"
            );
            $check->execute([$category]);

            $selectedCategoryName = $check->fetchColumn();

            if (!$selectedCategoryName) {
                $message = "Selected category does not exist.";
                $messageType = 'error';

            } else {
                // PRODUCT TYPE IS REQUIRED ONLY FOR BEVERAGES
                if (isBeveragesCategory($selectedCategoryName)) {

                    if (!in_array($productType, $beverageTypes, true)) {
                        throw new Exception("Please select a valid beverage product type.");
                    }

                } else {
                    $productType = null;
                }

                $uploadedImage = uploadProductImage(
                    $_FILES['product_image'] ?? null,
                    $uploadDirectory,
                    $maxFileSize
                );

                $stmt = $conn->prepare(
                    "INSERT INTO products
                    (category_id, product_type, product_name, description, price, product_image, product_status)
                    VALUES (?, ?, ?, ?, ?, ?, 'Active')"
                );

                $stmt->execute([
                    $category,
                    $productType,
                    $productName,
                    $description,
                    (float)$price,
                    $uploadedImage
                ]);

                header("Location: products.php?added=1");
                exit;
            }

        } catch (Exception $e) {

            if ($uploadedImage) {
                deleteProductImage($uploadedImage, $uploadDirectory);
            }

            $message = $e instanceof PDOException
                ? "Unable to add product. Please check your database."
                : $e->getMessage();

            $messageType = 'error';
        }
    }
}

// UPDATE PRODUCT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {

    $id = (int)($_POST['product_id'] ?? 0);
    $productName = trim($_POST['product_name'] ?? '');
    $category = (int)($_POST['category_id'] ?? 0);
    $productType = trim($_POST['product_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = $_POST['price'] ?? '';

    $newImage = null;

    if (
        $id <= 0 ||
        $productName === '' ||
        $category <= 0 ||
        !is_numeric($price) ||
        (float)$price <= 0
    ) {
        $message = "Please enter valid product information.";
        $messageType = 'error';

    } else {
        try {
            // GET SELECTED CATEGORY NAME
            $check = $conn->prepare(
                "SELECT category_name FROM categories WHERE category_id = ?"
            );
            $check->execute([$category]);

            $selectedCategoryName = $check->fetchColumn();

            if (!$selectedCategoryName) {
                $message = "Selected category does not exist.";
                $messageType = 'error';

            } else {
                // PRODUCT TYPE IS REQUIRED ONLY FOR BEVERAGES
                if (isBeveragesCategory($selectedCategoryName)) {

                    if (!in_array($productType, $beverageTypes, true)) {
                        throw new Exception("Please select a valid beverage product type.");
                    }

                } else {
                    $productType = null;
                }

                $currentStmt = $conn->prepare(
                    "SELECT product_image FROM products WHERE product_id = ?"
                );
                $currentStmt->execute([$id]);

                $currentProduct = $currentStmt->fetch(PDO::FETCH_ASSOC);

                if (!$currentProduct) {
                    throw new Exception("Product not found.");
                }

                $oldImage = $currentProduct['product_image'] ?? null;

                $newImage = uploadProductImage(
                    $_FILES['product_image'] ?? null,
                    $uploadDirectory,
                    $maxFileSize
                );

                $imageToSave = $newImage ?? $oldImage;

                $stmt = $conn->prepare(
                    "UPDATE products
                     SET category_id = ?,
                         product_type = ?,
                         product_name = ?,
                         description = ?,
                         price = ?,
                         product_image = ?
                     WHERE product_id = ?"
                );

                $stmt->execute([
                    $category,
                    $productType,
                    $productName,
                    $description,
                    (float)$price,
                    $imageToSave,
                    $id
                ]);

                if ($newImage && $oldImage && $oldImage !== $newImage) {
                    deleteProductImage($oldImage, $uploadDirectory);
                }

                header("Location: products.php?updated=1");
                exit;
            }

        } catch (Exception $e) {

            if ($newImage) {
                deleteProductImage($newImage, $uploadDirectory);
            }

            $message = $e instanceof PDOException
                ? "Unable to update product. Please check your database."
                : $e->getMessage();

            $messageType = 'error';
        }
    }
}

// ACTIVATE / DEACTIVATE PRODUCT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {

    $id = (int)($_POST['product_id'] ?? 0);
    $newStatus = ($_POST['new_status'] ?? '') === 'Inactive' ? 'Inactive' : 'Active';

    try {
        if ($id <= 0) {
            throw new Exception("Invalid product.");
        }

        $statusStmt = $conn->prepare(
            "UPDATE products SET product_status = ? WHERE product_id = ?"
        );
        $statusStmt->execute([$newStatus, $id]);

        if ($statusStmt->rowCount() === 0) {
            $checkStmt = $conn->prepare(
                "SELECT product_id FROM products WHERE product_id = ?"
            );
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetchColumn()) {
                throw new Exception("Product not found.");
            }
        }

        header("Location: products.php?status_updated=1");
        exit;

    } catch (Exception $e) {
        $message = $e instanceof PDOException
            ? "Unable to update product status. Please check your database."
            : $e->getMessage();
        $messageType = 'error';
    }
}

// GET PRODUCT FOR EDITING
if (isset($_GET['edit'])) {

    $id = (int)$_GET['edit'];

    $stmt = $conn->prepare(
        "SELECT * FROM products WHERE product_id = ?"
    );
    $stmt->execute([$id]);

    $editProduct = $stmt->fetch(PDO::FETCH_ASSOC);
}

// GET CATEGORIES
$categoryStmt = $conn->query(
    "SELECT * FROM categories ORDER BY category_name ASC"
);
$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

// GET CATEGORY PRODUCT COUNTS AND IMAGES
$categoryCountStmt = $conn->query("
    SELECT
        c.category_id,
        c.category_name,
        c.category_image,
        COUNT(p.product_id) AS product_count
    FROM categories c
    LEFT JOIN products p
        ON c.category_id = p.category_id
        
    GROUP BY
        c.category_id,
        c.category_name,
        c.category_image
    ORDER BY c.category_name ASC
");

$categoryCounts = $categoryCountStmt->fetchAll(PDO::FETCH_ASSOC);

// PRODUCT STATISTICS
$totalRegisteredProducts = (int)$conn->query(
    "SELECT COUNT(*) FROM products"
)->fetchColumn();

$totalCategories = (int)$conn->query(
    "SELECT COUNT(*) FROM categories"
)->fetchColumn();

$uncategorizedProducts = (int)$conn->query(
    "SELECT COUNT(*)
     FROM products
     WHERE (category_id IS NULL
        OR category_id NOT IN (SELECT category_id FROM categories))"
)->fetchColumn();

// SEARCH, CATEGORY, AND BEVERAGE TYPE FILTER
$search = trim($_GET['search'] ?? '');
$selectedCategory = (int)($_GET['category'] ?? 0);
$selectedType = trim($_GET['type'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

if (!in_array($statusFilter, ['all', 'Active', 'Inactive'], true)) {
    $statusFilter = 'all';
}

// Find the Beverages category dynamically by its category name.
$beveragesCategoryId = 0;
$beveragesProductCount = 0;
foreach ($categoryCounts as $categoryRow) {
    if (strcasecmp(trim($categoryRow['category_name']), 'Beverages') === 0) {
        $beveragesCategoryId = (int)$categoryRow['category_id'];
        $beveragesProductCount = (int)$categoryRow['product_count'];
        break;
    }
}

// Only allow a beverage type filter if the Beverages category is selected
// and the requested type exists in the registered beverage type list.
if (
    $selectedCategory !== $beveragesCategoryId ||
    !in_array($selectedType, $beverageTypes, true)
) {
    $selectedType = '';
}

// Get product counts for each registered beverage type.
$beverageTypeCounts = [];
if ($beveragesCategoryId > 0) {
    $typeCountStmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM products
         WHERE category_id = ?
           AND product_type = ?"
    );

    foreach ($beverageTypes as $beverageType) {
        $typeCountStmt->execute([
            $beveragesCategoryId,
            $beverageType
        ]);

        $beverageTypeCounts[$beverageType] = (int)$typeCountStmt->fetchColumn();
    }
}

// CATEGORY DISPLAY NUMBERING
// Number products within the selected category using ALL products,
// including Active and Inactive, regardless of search/status filters.
$categoryProductNumbers = [];

if ($selectedCategory > 0) {
    $numberStmt = $conn->prepare(
        "SELECT product_id
         FROM products
         WHERE category_id = ?
         ORDER BY product_id DESC"
    );
    $numberStmt->execute([$selectedCategory]);

    $categoryProductIds = $numberStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($categoryProductIds as $index => $productId) {
        $categoryProductNumbers[(int)$productId] = $index + 1;
    }
}

// PRODUCT QUERY
$sql = "SELECT
            p.*,
            c.category_name,
            c.category_image
        FROM products p
        LEFT JOIN categories c
            ON p.category_id = c.category_id
        WHERE 1 = 1";

$params = [];

if ($statusFilter !== 'all') {
    $sql .= " AND p.product_status = :status";
    $params['status'] = $statusFilter;
}

if ($search !== '') {
    $sql .= " AND (
        p.product_name LIKE :search
        OR c.category_name LIKE :search
        OR p.product_type LIKE :search
    )";

    $params['search'] = "%$search%";
}

if ($selectedCategory > 0) {
    $sql .= " AND p.category_id = :category";
    $params['category'] = $selectedCategory;
}

if ($selectedType !== '') {
    $sql .= " AND p.product_type = :product_type";
    $params['product_type'] = $selectedType;
}

$sql .= " ORDER BY p.product_id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalProducts = count($products);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Products | Bakery Management System</title>

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

/* BANNER */

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

/* SECTIONS */

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

/* OVERVIEW */

.overview-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
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

/* FORMS */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.form-group {
    min-width: 0;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    color: var(--text);
    font-size: 11px;
    font-weight: 700;
}

input,
textarea,
select {
    width: 100%;
    padding: 10px 11px;
    border: 1px solid var(--border);
    border-radius: 7px;
    outline: none;
    background: #fff;
    color: var(--text);
    font-family: inherit;
    font-size: 11px;
    transition: .2s;
}

input:focus,
textarea:focus,
select:focus {
    border-color: var(--brown-light);
    box-shadow: 0 0 0 3px rgba(130,85,47,.09);
}

textarea {
    resize: vertical;
    min-height: 84px;
}

input[type="file"] {
    padding: 9px;
    background: #faf8f5;
    cursor: pointer;
}

.form-hint {
    display: block;
    margin-top: 6px;
    color: var(--muted);
    font-size: 10px;
}

/* PRODUCT TYPE */

.product-type-group {
    display: none;
}

.product-type-group.show {
    display: block;
}

.product-type-hint {
    display: block;
    margin-top: 6px;
    color: var(--muted);
    font-size: 10px;
}

.form-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
    flex-wrap: wrap;
}

/* IMAGE PREVIEW */

.image-preview-box {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.image-preview {
    width: 125px;
    height: 105px;
    object-fit: cover;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #faf8f5;
}

.current-image-label {
    color: var(--muted);
    font-size: 10px;
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

.edit-btn {
    background: #e6eff8;
    color: #2878b5;
}

.edit-btn:hover {
    background: #d5e5f5;
}

.delete-btn {
    background: #fce8e6;
    color: #b83232;
}

.delete-btn:hover {
    background: #f8d6d3;
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

.category-card-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 54px;
    height: 54px;
    flex-shrink: 0;
    overflow: hidden;
    border-radius: 15px;
    background: #f1e5d9;
    font-size: 27px;
}

.category-card-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
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

/* BEVERAGE TYPE FILTERS */

.beverage-type-section {
    margin-top: 20px;
    padding: 18px;
    background: #fffaf5;
    border: 1px solid #ead8c7;
    border-radius: 14px;
}

.beverage-type-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 13px;
}

.beverage-type-heading h4 {
    margin: 0;
    color: var(--brown);
    font-size: 13px;
}

.beverage-type-heading span {
    color: var(--muted);
    font-size: 10px;
}

.beverage-type-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.beverage-type-card {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    padding: 11px;
    color: var(--brown);
    background: white;
    border: 1px solid var(--border);
    border-radius: 10px;
    text-decoration: none;
    transition: .2s ease;
}

.beverage-type-card:hover {
    border-color: var(--brown-light);
    transform: translateY(-1px);
}

.beverage-type-card.active {
    color: white;
    background: var(--brown-dark);
    border-color: var(--brown-dark);
}

.beverage-type-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 35px;
    height: 35px;
    flex-shrink: 0;
    background: #f2e7db;
    border-radius: 9px;
    font-size: 18px;
}

.beverage-type-card.active .beverage-type-icon {
    background: rgba(255,255,255,.15);
}

.beverage-type-info {
    min-width: 0;
}

.beverage-type-info strong {
    display: block;
    overflow: hidden;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.beverage-type-info small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 9px;
}

.beverage-type-card.active .beverage-type-info small {
    color: #ead9c8;
}

@media (max-width: 1100px) {
    .beverage-type-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 520px) {
    .beverage-type-grid {
        grid-template-columns: 1fr;
    }
}

/* SEARCH */



.search-form {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 17px;
}

.search-wrap {
    position: relative;
    flex: 1;
    min-width: 180px;
}

.suggestions {
    display: none;
    position: absolute;
    top: calc(100% + 5px);
    left: 0;
    right: 0;
    z-index: 1000;
    max-height: 240px;
    overflow-y: auto;
    background: white;
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0,0,0,.10);
}

.suggestions.show {
    display: block;
}

.suggestion-item {
    display: block;
    width: 100%;
    padding: 10px 12px;
    border: none;
    border-radius: 0;
    background: white;
    color: var(--text);
    text-align: left;
    cursor: pointer;
    font-family: inherit;
    font-size: 11px;
}

.suggestion-item:hover {
    background: var(--cream);
}

.suggestion-category {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 9px;
}

.no-suggestions {
    padding: 11px;
    color: var(--muted);
    font-size: 10px;
}

/* TABLE */

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 980px;
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

.category-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 9px;
    border-radius: 20px;
    background: #f2e7db;
    color: var(--brown);
    font-size: 9px;
    font-weight: 700;
    white-space: nowrap;
}

.category-badge img {
    width: 25px;
    height: 25px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid rgba(84, 53, 31, .12);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: 700;
    white-space: nowrap;
}

.status-active {
    background: #e3f2e8;
    color: #34734a;
}

.status-inactive {
    background: #fce8e6;
    color: #a12626;
}

.product-type-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 20px;
    background: #e8f2ed;
    color: #34734a;
    font-size: 9px;
    font-weight: 700;
    white-space: nowrap;
}

.no-product-type {
    color: #aaa096;
    font-size: 10px;
}

.price {
    color: var(--brown);
    font-weight: 700;
    white-space: nowrap;
}

.description {
    max-width: 190px;
    color: var(--muted);
    line-height: 1.5;
}

.actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}

.actions form {
    margin: 0;
}

.actions .btn {
    padding: 6px 8px;
    font-size: 8px;
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

    .overview-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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

    .form-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .form-group.full {
        grid-column: auto;
    }

    .search-form {
        flex-wrap: wrap;
    }

    .search-wrap {
        flex-basis: 100%;
    }

    .search-form .btn {
        flex: 1;
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
}

@media (max-width: 360px) {
    .nav-menu {
        grid-template-columns: 1fr;
    }
}

.add-product-type-row {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    align-items: stretch;
}
.add-product-type-row input {
    min-width: 0;
    flex: 1;
}
.add-product-type-row button {
    white-space: nowrap;
}
@media (max-width: 520px) {
    .add-product-type-row {
        flex-direction: column;
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

        <a href="products.php" class="nav-link active" aria-current="page">
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
            <h1>Product Management</h1>
            <p>Organize and manage your bakery products.</p>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php if ($profilePictureUrl): ?>
                    <img
                        src="<?= htmlspecialchars($profilePictureUrl, ENT_QUOTES, 'UTF-8') ?>"
                        alt="<?= htmlspecialchars($rawName, ENT_QUOTES, 'UTF-8') ?> profile picture"
                    >
                <?php else: ?>
                    <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
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
            <h2>Product Management 🧁</h2>
            <p>
                Manage your bakery products, organize your catalog, and keep your product information updated.
                <br>
                <?= date('l, F d, Y') ?>
            </p>
        </div>

        <div class="banner-icon">🥐</div>
    </section>

    <!-- NOTIFICATIONS -->

    <?php if ($message): ?>
        <div
            class="message <?= $messageType === 'success' ? 'success-message' : 'error-message' ?>"
            id="productMessage"
        >
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- PRODUCT OVERVIEW -->

    <div class="section-heading">
        <h2>Product Overview</h2>
        <span>Summary of your bakery products</span>
    </div>

    <section class="overview-grid">

        <div class="overview-card">
            <div class="overview-card-top">
                <span class="overview-card-label">Total Products</span>
                <span class="overview-icon icon-brown">🍞</span>
            </div>

            <div class="overview-number">
                <?= number_format($totalRegisteredProducts) ?>
            </div>

            <p class="overview-description">Registered bakery products</p>
        </div>

        <div class="overview-card">
            <div class="overview-card-top">
                <span class="overview-card-label">Product Categories</span>
                <span class="overview-icon icon-green">📦</span>
            </div>

            <div class="overview-number">
                <?= number_format($totalCategories) ?>
            </div>

            <p class="overview-description">Available product categories</p>
        </div>

        <div class="overview-card">
            <div class="overview-card-top">
                <span class="overview-card-label">Uncategorized Products</span>
                <span class="overview-icon icon-orange">🗂️</span>
            </div>

            <div class="overview-number">
                <?= number_format($uncategorizedProducts) ?>
            </div>

            <p class="overview-description">Products without a valid category</p>
        </div>

    </section>

    <!-- ADD OR EDIT PRODUCT -->

    <div class="section-heading">
        <h2><?= $editProduct ? 'Edit Product' : 'Add New Product' ?></h2>
        <span>Product Information</span>
    </div>

    <section class="panel">

        <div class="panel-header">
            <div>
                <h2>
                    <?= $editProduct ? 'Update Product Details' : 'Register a New Product' ?>
                </h2>

                <p>
                    <?= $editProduct
                        ? 'Modify the information and product image.'
                        : 'Enter product details and upload its picture.' ?>
                </p>
            </div>

            <div class="panel-header-icon">🧁</div>
        </div>

        <?php if ($editProduct): ?>

            <form method="POST" enctype="multipart/form-data">

                <input type="hidden" name="action" value="update">

                <input
                    type="hidden"
                    name="product_id"
                    value="<?= (int)$editProduct['product_id'] ?>"
                >

                <div class="form-grid">

                    <div class="form-group">
                        <label>Product Name</label>

                        <input
                            type="text"
                            name="product_name"
                            value="<?= htmlspecialchars($editProduct['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                            maxlength="150"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Category</label>

                        <select
                            name="category_id"
                            id="editCategory"
                            required
                        >
                            <option value="">Select Category</option>

                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= (int)$category['category_id'] ?>"
                                    data-category-name="<?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $editProduct['category_id'] == $category['category_id'] ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <!-- EDIT PRODUCT TYPE -->

                    <div class="form-group product-type-group" id="editProductTypeGroup">
                        <label for="editProductType">Product Type</label>

                        <select
                            name="product_type"
                            id="editProductType"
                        >
                            <option value="">Select Product Type</option>

                            <?php foreach ($beverageTypes as $type): ?>
                                <option
                                    value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= ($editProduct['product_type'] ?? '') === $type ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>

                        </select>

                        <span class="product-type-hint">
                            Select the type of beverage.
                        </span>
                    </div>

                    <div class="form-group">
                        <label>Price (₱)</label>

                        <input
                            type="number"
                            name="price"
                            min="0.01"
                            step="0.01"
                            value="<?= htmlspecialchars((string)$editProduct['price'], ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Change Product Image</label>

                        <input
                            type="file"
                            name="product_image"
                            id="productImage"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >

                        <span class="form-hint">
                            JPG, PNG, or WEBP only. Maximum 5MB.
                            Leave blank to keep the current image.
                        </span>
                    </div>

                    <div class="form-group full">
                        <label>Current Product Image</label>

                        <div class="image-preview-box">

                            <?php if (!empty($editProduct['product_image'])): ?>

                                <img
                                    src="<?= htmlspecialchars($uploadUrl . rawurlencode($editProduct['product_image']), ENT_QUOTES, 'UTF-8') ?>"
                                    class="image-preview"
                                    id="imagePreview"
                                    alt="Current product image"
                                >

                            <?php else: ?>

                                <img
                                    src=""
                                    class="image-preview"
                                    id="imagePreview"
                                    alt="Product image preview"
                                    style="display:none;"
                                >

                                <div class="no-product-image" id="noImagePlaceholder">
                                    🧁
                                </div>

                            <?php endif; ?>

                            <span class="current-image-label" id="previewLabel">
                                <?= !empty($editProduct['product_image'])
                                    ? 'Current uploaded image'
                                    : 'No image uploaded yet' ?>
                            </span>

                        </div>
                    </div>

                    <div class="form-group full">
                        <label>Description</label>

                        <textarea name="description" rows="3"><?= htmlspecialchars($editProduct['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                </div>

                <div class="form-actions">

                    <button type="submit" class="btn primary-btn">
                        ✓ Update Product
                    </button>

                    <a href="products.php" class="btn cancel-btn">
                        Cancel
                    </a>

                </div>

            </form>

        <?php else: ?>

            <?php if (count($categories) > 0): ?>

                <form method="POST" enctype="multipart/form-data">

                    <input type="hidden" name="action" value="add">

                    <div class="form-grid">

                        <div class="form-group">
                            <label>Product Name</label>

                            <input
                                type="text"
                                name="product_name"
                                placeholder="Example: Chocolate Cake"
                                maxlength="150"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Category</label>

                            <select
                                name="category_id"
                                id="addCategory"
                                required
                            >
                                <option value="">Select Category</option>

                                <?php foreach ($categories as $category): ?>
                                    <option
                                        value="<?= (int)$category['category_id'] ?>"
                                        data-category-name="<?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                        <?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>
                        </div>

                        <!-- ADD PRODUCT TYPE -->

                        <div class="form-group product-type-group" id="addProductTypeGroup">
                            <label for="addProductType">Product Type</label>

                            <select
                                name="product_type"
                                id="addProductType"
                            >
                                <option value="">Select Product Type</option>

                                <?php foreach ($beverageTypes as $type): ?>
                                    <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>

                            <span class="product-type-hint">
                                Select the type of beverage.
                            </span>

                            <div class="add-product-type-row">
                                <input
                                    type="text"
                                    name="new_product_type"
                                    maxlength="100"
                                    placeholder="Add another type (e.g. Lemonade)"
                                >
                                <button
                                    type="submit"
                                    name="action"
                                    value="add_type"
                                    class="secondary-btn"
                                    formnovalidate
                                >
                                    + Add Type
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Price (₱)</label>

                            <input
                                type="number"
                                name="price"
                                placeholder="Example: 50.00"
                                min="0.01"
                                step="0.01"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Product Image</label>

                            <input
                                type="file"
                                name="product_image"
                                id="productImage"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            >

                            <span class="form-hint">
                                JPG, PNG, or WEBP only. Maximum 5MB.
                            </span>
                        </div>

                        <div class="form-group full">
                            <label>Image Preview</label>

                            <div class="image-preview-box">

                                <img
                                    src=""
                                    class="image-preview"
                                    id="imagePreview"
                                    alt="Product image preview"
                                    style="display:none;"
                                >

                                <div class="no-product-image" id="noImagePlaceholder">
                                    🧁
                                </div>

                                <span class="current-image-label" id="previewLabel">
                                    No image selected
                                </span>

                            </div>
                        </div>

                        <div class="form-group full">
                            <label>Description</label>

                            <textarea
                                name="description"
                                placeholder="Enter product description"
                                rows="3"
                            ></textarea>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn primary-btn">
                            + Add Product
                        </button>
                    </div>

                </form>

            <?php else: ?>

                <div class="message error-message">
                    Please add a category first before registering products.
                </div>

                <a class="btn primary-btn" href="categories.php">
                    Manage Categories
                </a>

            <?php endif; ?>

        <?php endif; ?>

    </section>

    <!-- PRODUCT LIST -->

    <div class="section-heading" id="category-products">
        <h2>Product List</h2>
        <span>Manage your registered bakery products</span>
    </div>

    <section class="panel">

        <div class="panel-header">
            <div>
                <h2>Registered Products</h2>
                <p>View, search, filter, edit, and manage your products.</p>
            </div>

            <span class="product-count">
                📦 <?= number_format($totalProducts) ?> Products
            </span>
        </div>

        <!-- CATEGORY CARDS -->

        <div class="category-section">

            <h3 class="category-title">Choose a Product Category</h3>

            <div class="category-grid">

                <!-- ALL PRODUCTS -->

                <a
                    href="products.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>#category-products"
                    class="category-card <?= $selectedCategory === 0 ? 'active' : '' ?>"
                >

                    <div class="category-card-icon">📦</div>

                    <div class="category-card-info">
                        <span class="category-card-name">All Products</span>

                        <span class="category-card-count">
                            <?= number_format($totalRegisteredProducts) ?> products
                        </span>
                    </div>

                </a>

                <!-- INDIVIDUAL CATEGORIES -->

                <?php foreach ($categoryCounts as $cat): ?>

                    <a
                        href="products.php?category=<?= (int)$cat['category_id'] ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>#category-products"
                        class="category-card <?= $selectedCategory === (int)$cat['category_id'] ? 'active' : '' ?>"
                    >

                        <div class="category-card-icon">

                            <?php if (!empty($cat['category_image'])): ?>

                                <img
                                    src="<?= htmlspecialchars(
                                        getCategoryImageUrl($cat['category_image']),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    alt="<?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    loading="lazy"
                                >

                            <?php else: ?>

                                <?= getCategoryIcon($cat['category_name']) ?>

                            <?php endif; ?>

                        </div>

                        <div class="category-card-info">

                            <span class="category-card-name">
                                <?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <span class="category-card-count">
                                <?= number_format((int)$cat['product_count']) ?> products
                            </span>

                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

            <?php if ($selectedCategory === $beveragesCategoryId && $beveragesCategoryId > 0): ?>

                <!-- BEVERAGE PRODUCT TYPE FILTERS -->

                <div class="beverage-type-section">
                    <div class="beverage-type-heading">
                        <h4>Choose a Beverage Type</h4>
                        <span>Filter the products by type</span>
                    </div>

                    <div class="beverage-type-grid">

                        <?php
                        $allBeveragesQuery = [
                            'category' => $beveragesCategoryId
                        ];

                        if ($search !== '') {
                            $allBeveragesQuery['search'] = $search;
                        }

                        if ($statusFilter !== 'all') {
                            $allBeveragesQuery['status'] = $statusFilter;
                        }
                        ?>

                        <a
                            href="products.php?<?= htmlspecialchars(
                                http_build_query($allBeveragesQuery),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>#category-products"
                            class="beverage-type-card <?= $selectedType === '' ? 'active' : '' ?>"
                        >
                            <span class="beverage-type-icon">🥤</span>
                            <span class="beverage-type-info">
                                <strong>All Beverages</strong>
                                <small><?= number_format($beveragesProductCount) ?> products</small>
                            </span>
                        </a>

                        <?php foreach ($beverageTypes as $beverageType): ?>

                            <?php
                            $typeQuery = [
                                'category' => $beveragesCategoryId,
                                'type' => $beverageType
                            ];

                            if ($search !== '') {
                                $typeQuery['search'] = $search;
                            }

                            if ($statusFilter !== 'all') {
                                $typeQuery['status'] = $statusFilter;
                            }
                            ?>

                            <a
                                href="products.php?<?= htmlspecialchars(
                                    http_build_query($typeQuery),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>#category-products"
                                class="beverage-type-card <?= $selectedType === $beverageType ? 'active' : '' ?>"
                            >
                                <span class="beverage-type-icon">🧋</span>
                                <span class="beverage-type-info">
                                    <strong><?= htmlspecialchars($beverageType, ENT_QUOTES, 'UTF-8') ?></strong>
                                    <small><?= number_format($beverageTypeCounts[$beverageType] ?? 0) ?> products</small>
                                </span>
                            </a>

                        <?php endforeach; ?>

                    </div>
                </div>

            <?php endif; ?>

            <div class="category-showing">

                <?php if ($selectedCategory > 0): ?>

                    <?php
                    $selectedCategoryName = 'Selected Category';

                    foreach ($categories as $cat) {
                        if ((int)$cat['category_id'] === $selectedCategory) {
                            $selectedCategoryName = $cat['category_name'];
                            break;
                        }
                    }
                    ?>

                    Showing <?= number_format($totalProducts) ?>
                    products in
                    <strong>
                        <?= htmlspecialchars($selectedCategoryName, ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <?php if ($selectedType !== ''): ?>
                        — <strong><?= htmlspecialchars($selectedType, ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php endif; ?>.

                <?php else: ?>

                    Showing all <?= number_format($totalProducts) ?> products.

                <?php endif; ?>

            </div>

        </div>

        <!-- SEARCH -->

        <form method="GET" action="products.php#category-products" class="search-form" id="productSearchForm">

            <div class="search-wrap">

                <input
                    type="text"
                    name="search"
                    id="productSearch"
                    placeholder="Search product..."
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="off"
                >

                <div class="suggestions" id="searchSuggestions"></div>

            </div>

            <?php if ($selectedCategory > 0): ?>

                <input
                    type="hidden"
                    name="category"
                    value="<?= $selectedCategory ?>"
                >

            <?php endif; ?>

            <?php if ($selectedType !== ''): ?>

                <input
                    type="hidden"
                    name="type"
                    value="<?= htmlspecialchars($selectedType, ENT_QUOTES, 'UTF-8') ?>"
                >

            <?php endif; ?>

            <select name="status" aria-label="Filter product status" style="max-width:150px;">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>

            <button type="submit" class="btn primary-btn">
                🔍 Search
            </button>

            <a
                href="products.php#category-products"
                class="btn cancel-btn"
            >
                Clear
            </a>

        </form>

        <!-- TABLE -->

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Product Type</th>
                        <th>Status</th>
                        <th>Price</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($products): ?>

                        <?php foreach ($products as $product): ?>

                            <tr>

                                <td>
                                    #<?= $selectedCategory > 0
                                        ? ($categoryProductNumbers[(int)$product['product_id']] ?? (int)$product['product_id'])
                                        : (int)$product['product_id'] ?>
                                </td>

                                <td>

                                    <div class="product-cell">

                                        <?php if (!empty($product['product_image'])): ?>

                                            <img
                                                src="<?= htmlspecialchars($uploadUrl . rawurlencode($product['product_image']), ENT_QUOTES, 'UTF-8') ?>"
                                                class="product-thumbnail"
                                                alt="<?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                loading="lazy"
                                            >

                                        <?php else: ?>

                                            <div class="no-product-image">
                                                🧁
                                            </div>

                                        <?php endif; ?>

                                        <span class="product-name">
                                            <?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>

                                    </div>

                                </td>

                                <!-- CATEGORY NAME WITH CATEGORY IMAGE -->

                                <td>

                                    <span class="category-badge">

                                        <?php if (!empty($product['category_image'])): ?>

                                            <img
                                                src="<?= htmlspecialchars(
                                                    getCategoryImageUrl($product['category_image']),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                alt=""
                                                loading="lazy"
                                            >

                                        <?php else: ?>

                                            <?= getCategoryIcon($product['category_name'] ?? '') ?>

                                        <?php endif; ?>

                                        <?= htmlspecialchars(
                                            $product['category_name'] ?? 'Uncategorized',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </span>

                                </td>

                                <!-- PRODUCT TYPE -->

                                <td>

                                    <?php if (!empty($product['product_type'])): ?>

                                        <span class="product-type-badge">
                                            <?= htmlspecialchars($product['product_type'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="no-product-type">—</span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <span class="status-badge <?= ($product['product_status'] ?? 'Active') === 'Active' ? 'status-active' : 'status-inactive' ?>">
                                        <?= htmlspecialchars($product['product_status'] ?? 'Active', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>

                                <td class="price">
                                    ₱<?= number_format((float)$product['price'], 2) ?>
                                </td>

                                <td class="description">
                                    <?= htmlspecialchars($product['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>

                                    <div class="actions">

                                        <a
                                            class="btn edit-btn"
                                            href="products.php?edit=<?= (int)$product['product_id'] ?>"
                                        >
                                            ✎ Edit
                                        </a>

                                        <form
                                            method="POST"
                                            onsubmit="return confirm('<?= ($product['product_status'] ?? 'Active') === 'Active' ? 'Deactivate' : 'Activate' ?> this product?');"
                                        >
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="product_id" value="<?= (int)$product['product_id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= ($product['product_status'] ?? 'Active') === 'Active' ? 'Inactive' : 'Active' ?>">
                                            <button type="submit" class="btn <?= ($product['product_status'] ?? 'Active') === 'Active' ? 'delete-btn' : 'primary-btn' ?>">
                                                <?= ($product['product_status'] ?? 'Active') === 'Active' ? '⏸ Deactivate' : '▶ Activate' ?>
                                            </button>
                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8" class="empty">
                                No products found for the selected search or category.
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

const message = document.getElementById("productMessage");

if (message) {
    setTimeout(() => {
        message.style.opacity = "0";

        setTimeout(() => {
            message.remove();
        }, 500);

    }, 3000);
}

// CONDITIONAL BEVERAGE PRODUCT TYPE

function setupProductType(categoryId, groupId, typeId) {

    const categorySelect = document.getElementById(categoryId);
    const productTypeGroup = document.getElementById(groupId);
    const productTypeSelect = document.getElementById(typeId);

    if (!categorySelect || !productTypeGroup || !productTypeSelect) {
        return;
    }

    function updateProductTypeVisibility() {

        const selectedOption =
            categorySelect.options[categorySelect.selectedIndex];

        const categoryName = selectedOption
            ? (selectedOption.dataset.categoryName || selectedOption.textContent.trim())
            : '';

        const isBeverages =
            categoryName.trim().toLowerCase() === 'beverages';

        if (isBeverages) {

            productTypeGroup.classList.add('show');
            productTypeSelect.required = true;

        } else {

            productTypeGroup.classList.remove('show');
            productTypeSelect.required = false;
            productTypeSelect.value = '';

        }
    }

    categorySelect.addEventListener('change', updateProductTypeVisibility);

    // INITIAL CHECK, IMPORTANT FOR EDIT FORM
    updateProductTypeVisibility();
}

// ADD FORM
setupProductType(
    'addCategory',
    'addProductTypeGroup',
    'addProductType'
);

// EDIT FORM
setupProductType(
    'editCategory',
    'editProductTypeGroup',
    'editProductType'
);

// IMAGE PREVIEW

const imageInput = document.getElementById("productImage");
const imagePreview = document.getElementById("imagePreview");
const noImagePlaceholder = document.getElementById("noImagePlaceholder");
const previewLabel = document.getElementById("previewLabel");

if (imageInput && imagePreview) {

    imageInput.addEventListener("change", function () {

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

        const reader = new FileReader();

        reader.onload = function (event) {

            imagePreview.src = event.target.result;
            imagePreview.style.display = "block";

            if (noImagePlaceholder) {
                noImagePlaceholder.style.display = "none";
            }

            if (previewLabel) {
                previewLabel.textContent = "Selected image preview";
            }
        };

        reader.readAsDataURL(file);

    });
}

// LIVE SEARCH AUTOCOMPLETE

const searchInput = document.getElementById("productSearch");
const suggestions = document.getElementById("searchSuggestions");
const searchForm = document.getElementById("productSearchForm");

let searchTimer;
let currentRequest = null;

searchInput.addEventListener("input", function () {

    const query = this.value.trim();

    clearTimeout(searchTimer);

    if (currentRequest) {
        currentRequest.abort();
    }

    suggestions.replaceChildren();

    if (query.length === 0) {
        suggestions.classList.remove("show");
        return;
    }

    searchTimer = setTimeout(async function () {

        currentRequest = new AbortController();

        try {

            const response = await fetch(
                "search_products.php?q=" + encodeURIComponent(query),
                { signal: currentRequest.signal }
            );

            if (!response.ok) {
                throw new Error("Search request failed");
            }

            const products = await response.json();

            suggestions.replaceChildren();

            if (products.length === 0) {

                const empty = document.createElement("div");
                empty.className = "no-suggestions";
                empty.textContent = "No products found.";

                suggestions.appendChild(empty);

            } else {

                products.forEach(function (product) {

                    const item = document.createElement("button");

                    item.type = "button";
                    item.className = "suggestion-item";

                    const name = document.createElement("span");
                    name.textContent = product.product_name;

                    const category = document.createElement("span");
                    category.className = "suggestion-category";
                    category.textContent = product.category_name || "Uncategorized";

                    item.appendChild(name);
                    item.appendChild(category);

                    item.addEventListener("click", function () {

                        searchInput.value = product.product_name;
                        suggestions.classList.remove("show");

                        searchForm.requestSubmit();
                    });

                    suggestions.appendChild(item);

                });

            }

            suggestions.classList.add("show");

        } catch (error) {

            if (error.name === "AbortError") {
                return;
            }

            suggestions.replaceChildren();

            const errorMessage = document.createElement("div");
            errorMessage.className = "no-suggestions";
            errorMessage.textContent = "Unable to load suggestions.";

            suggestions.appendChild(errorMessage);
            suggestions.classList.add("show");
        }

    }, 200);

});

// CLOSE SUGGESTIONS WHEN CLICKING OUTSIDE

document.addEventListener("click", function (event) {

    if (!event.target.closest(".search-wrap")) {
        suggestions.classList.remove("show");
    }

});

// CLOSE SUGGESTIONS USING ESCAPE

searchInput.addEventListener("keydown", function (event) {

    if (event.key === "Escape") {
        suggestions.classList.remove("show");
    }

});

// KEEP CATEGORY AREA VISIBLE AFTER FILTERING

window.addEventListener("load", function () {

    if (window.location.hash === "#category-products") {

        const categorySection = document.getElementById("category-products");

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
