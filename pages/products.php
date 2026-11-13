<?php
session_start();
require_once '../config/database.php';

// CHECK LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$message = "";
$messageType = "success";
$editProduct = null;

// NOTIFICATIONS
if (isset($_GET['added'])) {
    $message = "Product added successfully!";
} elseif (isset($_GET['updated'])) {
    $message = "Product updated successfully!";
} elseif (isset($_GET['deleted'])) {
    $message = "Product deleted successfully!";
}

// ADD PRODUCT
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST['action'] ?? '') === 'add'
) {
    $name = trim($_POST['product_name'] ?? '');
    $category = (int)($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $price = $_POST['price'] ?? '';

    if (
        $name === '' ||
        $category <= 0 ||
        !is_numeric($price) ||
        (float)$price <= 0
    ) {
        $message = "Please enter valid product information.";
        $messageType = "error";
    } else {
        try {
            $check = $conn->prepare(
                "SELECT COUNT(*) FROM categories WHERE category_id = ?"
            );
            $check->execute([$category]);

            if (!$check->fetchColumn()) {
                $message = "Selected category does not exist.";
                $messageType = "error";
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO products
                    (category_id, product_name, description, price)
                    VALUES (?, ?, ?, ?)"
                );

                $stmt->execute([
                    $category,
                    $name,
                    $description,
                    (float)$price
                ]);

                header("Location: products.php?added=1");
                exit;
            }
        } catch (PDOException $e) {
            $message = "Unable to add product.";
            $messageType = "error";
        }
    }
}

// UPDATE PRODUCT
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST['action'] ?? '') === 'update'
) {
    $id = (int)($_POST['product_id'] ?? 0);
    $name = trim($_POST['product_name'] ?? '');
    $category = (int)($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $price = $_POST['price'] ?? '';

    if (
        $id <= 0 ||
        $name === '' ||
        $category <= 0 ||
        !is_numeric($price) ||
        (float)$price <= 0
    ) {
        $message = "Please enter valid product information.";
        $messageType = "error";
    } else {
        try {
            $check = $conn->prepare(
                "SELECT COUNT(*) FROM categories WHERE category_id = ?"
            );
            $check->execute([$category]);

            if (!$check->fetchColumn()) {
                $message = "Selected category does not exist.";
                $messageType = "error";
            } else {
                $stmt = $conn->prepare(
                    "UPDATE products
                     SET category_id = ?,
                         product_name = ?,
                         description = ?,
                         price = ?
                     WHERE product_id = ?"
                );

                $stmt->execute([
                    $category,
                    $name,
                    $description,
                    (float)$price,
                    $id
                ]);

                header("Location: products.php?updated=1");
                exit;
            }
        } catch (PDOException $e) {
            $message = "Unable to update product.";
            $messageType = "error";
        }
    }
}

// DELETE PRODUCT
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST['action'] ?? '') === 'delete'
) {
    $id = (int)($_POST['product_id'] ?? 0);

    try {
        if ($id <= 0) {
            throw new Exception("Invalid product.");
        }

        // CHECK SALES RECORDS
        $checkSales = $conn->prepare(
            "SELECT COUNT(*) FROM sale_details WHERE product_id = ?"
        );
        $checkSales->execute([$id]);

        // CHECK STOCK MOVEMENTS
        $checkMovements = $conn->prepare(
            "SELECT COUNT(*) FROM stock_movements WHERE product_id = ?"
        );
        $checkMovements->execute([$id]);

        if (
            $checkSales->fetchColumn() > 0 ||
            $checkMovements->fetchColumn() > 0
        ) {
            $message = "Cannot delete: This product already has transaction records.";
            $messageType = "error";
        } else {
            $conn->beginTransaction();

            // DELETE INVENTORY RECORD FIRST
            $deleteInventory = $conn->prepare(
                "DELETE FROM inventory WHERE product_id = ?"
            );
            $deleteInventory->execute([$id]);

            // DELETE PRODUCT
            $deleteProduct = $conn->prepare(
                "DELETE FROM products WHERE product_id = ?"
            );
            $deleteProduct->execute([$id]);

            $conn->commit();

            header("Location: products.php?deleted=1");
            exit;
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $message = "Unable to delete product.";
        $messageType = "error";
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        $message = "Unable to delete product.";
        $messageType = "error";
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

// GET ALL CATEGORIES
$categoryStmt = $conn->query(
    "SELECT * FROM categories ORDER BY category_name ASC"
);
$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

// SEARCH PRODUCTS
$search = trim($_GET['search'] ?? '');

$sql = "SELECT p.*, c.category_name
        FROM products p
        LEFT JOIN categories c
        ON p.category_id = c.category_id";

if ($search !== '') {
    $sql .= " WHERE p.product_name LIKE :search
              OR c.category_name LIKE :search";
}

$sql .= " ORDER BY p.product_id DESC";

$stmt = $conn->prepare($sql);

if ($search !== '') {
    $stmt->execute(['search' => "%$search%"]);
} else {
    $stmt->execute();
}

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Product Management | Bakery System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px;
            font-family: Arial, sans-serif;
            background: #f7f4ef;
            color: #333;
        }

        .container {
            max-width: 1150px;
            margin: auto;
        }

        h1 {
            color: #59391f;
        }

        .subtitle {
            color: #777;
            margin-top: -8px;
        }

        .back {
            display: inline-block;
            margin-bottom: 15px;
            color: #754c24;
            text-decoration: none;
            font-weight: bold;
        }

        .form-box,
        .table-box {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-top: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        label {
            display: block;
            font-weight: bold;
            margin-top: 12px;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 12px;
            margin: 8px 0 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-family: Arial, sans-serif;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #754c24;
        }

        button,
        .btn {
            display: inline-block;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        button {
            background: #754c24;
        }

        button:hover {
            background: #59391f;
        }

        .edit {
            background: #2878b5;
        }

        .delete {
            background: #b83232;
        }

        .cancel {
            background: #777;
        }

        .message {
            padding: 13px 16px;
            border-radius: 6px;
            margin-top: 18px;
            transition: opacity .5s ease;
        }

        .success-message {
            background: #e7f5e9;
            color: #26743a;
            border: 1px solid #b9e2c1;
        }

        .error-message {
            background: #ffe8e8;
            color: #a12626;
            border: 1px solid #f2baba;
        }

        /* SEARCH FORM */
        .search-form {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .search-form button {
            white-space: nowrap;
        }

        /* AUTOCOMPLETE */
        .search-wrap {
            position: relative;
            flex: 1;
            min-width: 0;
        }

        .search-wrap input {
            width: 100%;
            margin: 0;
        }

        .suggestions {
            display: none;
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-shadow: 0 4px 10px rgba(0,0,0,.12);
            z-index: 1000;
            max-height: 260px;
            overflow-y: auto;
        }

        .suggestions.show {
            display: block;
        }

        .suggestion-item {
            display: block;
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 0;
            background: white;
            color: #333;
            text-align: left;
            cursor: pointer;
            font-size: 14px;
        }

        .suggestion-item:hover,
        .suggestion-item:focus {
            background: #f7f4ef;
        }

        .suggestion-category {
            display: block;
            margin-top: 4px;
            color: #888;
            font-size: 12px;
        }

        .no-suggestions {
            padding: 12px;
            color: #888;
            font-size: 13px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th,
        td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #f7f4ef;
            color: #59391f;
        }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .actions form {
            margin: 0;
        }

        .empty {
            text-align: center;
            color: #888;
            padding: 20px;
        }

        @media (max-width: 700px) {
            body {
                padding: 15px;
            }

            .table-box {
                overflow-x: auto;
            }

            table {
                min-width: 750px;
            }

            .search-form {
                flex-direction: column;
            }

            .search-form button,
            .search-form .btn {
                text-align: center;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <a class="back" href="dashboard.php">
        ← Back to Dashboard
    </a>

    <h1>Product Management</h1>

    <p class="subtitle">
        Manage your bakery products.
    </p>

    <!-- NOTIFICATION -->
    <?php if ($message): ?>
        <p
            class="message <?= $messageType === 'success'
                ? 'success-message'
                : 'error-message' ?>"
            id="productMessage"
        >
            <?= htmlspecialchars($message) ?>
        </p>
    <?php endif; ?>

    <!-- ADD OR EDIT PRODUCT -->
    <div class="form-box">

        <?php if ($editProduct): ?>

            <h2>Edit Product</h2>

            <form method="POST">

                <input type="hidden" name="action" value="update">

                <input
                    type="hidden"
                    name="product_id"
                    value="<?= (int)$editProduct['product_id'] ?>"
                >

                <label>Product Name</label>

                <input
                    type="text"
                    name="product_name"
                    value="<?= htmlspecialchars($editProduct['product_name']) ?>"
                    maxlength="150"
                    required
                >

                <label>Category</label>

                <select name="category_id" required>
                    <option value="">Select Category</option>

                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= (int)$category['category_id'] ?>"
                            <?= $editProduct['category_id'] == $category['category_id']
                                ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <label>Price (₱)</label>

                <input
                    type="number"
                    name="price"
                    min="0.01"
                    step="0.01"
                    value="<?= htmlspecialchars((string)$editProduct['price']) ?>"
                    required
                >

                <label>Description</label>

                <textarea
                    name="description"
                    rows="3"
                ><?= htmlspecialchars($editProduct['description'] ?? '') ?></textarea>

                <button type="submit">Update Product</button>

                <a class="btn cancel" href="products.php">Cancel</a>

            </form>

        <?php else: ?>

            <h2>Add New Product</h2>

            <?php if (count($categories) > 0): ?>

                <form method="POST">

                    <input type="hidden" name="action" value="add">

                    <label>Product Name</label>

                    <input
                        type="text"
                        name="product_name"
                        placeholder="Example: Chocolate Cake"
                        maxlength="150"
                        required
                    >

                    <label>Category</label>

                    <select name="category_id" required>
                        <option value="">Select Category</option>

                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int)$category['category_id'] ?>">
                                <?= htmlspecialchars($category['category_name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>

                    <label>Price (₱)</label>

                    <input
                        type="number"
                        name="price"
                        placeholder="Example: 50.00"
                        min="0.01"
                        step="0.01"
                        required
                    >

                    <label>Description</label>

                    <textarea
                        name="description"
                        placeholder="Enter product description"
                        rows="3"
                    ></textarea>

                    <button type="submit">Add Product</button>

                </form>

            <?php else: ?>

                <p class="message error-message">
                    Please add a category first before registering products.
                </p>

                <a class="btn edit" href="categories.php">
                    Manage Categories
                </a>

            <?php endif; ?>

        <?php endif; ?>

    </div>

    <!-- PRODUCT LIST -->
    <div class="table-box">

        <h2>Product List</h2>

        <!-- LIVE SEARCH WITH AUTOCOMPLETE -->
        <form method="GET" class="search-form" id="productSearchForm">

            <div class="search-wrap">

                <input
                    type="text"
                    name="search"
                    id="productSearch"
                    placeholder="Search product or category..."
                    value="<?= htmlspecialchars($search) ?>"
                    autocomplete="off"
                >

                <div
                    class="suggestions"
                    id="searchSuggestions"
                ></div>

            </div>

            <button type="submit">Search</button>

            <a class="btn cancel" href="products.php">Clear</a>

        </form>

        <table>

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Product Name</th>
                    <th>Category</th>
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
                                <?= (int)$product['product_id'] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($product['product_name']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?>
                            </td>

                            <td>
                                ₱<?= number_format((float)$product['price'], 2) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($product['description'] ?? '') ?>
                            </td>

                            <td>

                                <div class="actions">

                                    <a
                                        class="btn edit"
                                        href="products.php?edit=<?= (int)$product['product_id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Are you sure you want to delete this product?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="product_id"
                                            value="<?= (int)$product['product_id'] ?>"
                                        >

                                        <button type="submit" class="delete">
                                            Delete
                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No products found.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<!-- JAVASCRIPT -->
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
                    category.textContent =
                        product.category_name || "Uncategorized";

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

</script>

</body>
</html>