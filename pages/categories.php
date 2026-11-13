
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

// SHOW NOTIFICATION AFTER REDIRECT
if (isset($_GET['updated'])) {
    $message = "Category updated successfully!";
}

if (isset($_GET['added'])) {
    $message = "Category added successfully!";
}

if (isset($_GET['deleted'])) {
    $message = "Category deleted successfully!";
}

// ADD CATEGORY
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST['action'] ?? '') === 'add'
) {
    $name = trim($_POST['category_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $message = "Category name is required!";
        $messageType = "error";
    } else {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO categories (category_name, description)
                 VALUES (?, ?)"
            );

            $stmt->execute([$name, $description]);

            header("Location: categories.php?added=1");
            exit;

        } catch (PDOException $e) {
            $message = "Unable to add category. It may already exist.";
            $messageType = "error";
        }
    }
}

// UPDATE CATEGORY
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST['action'] ?? '') === 'update'
) {
    $id = (int)($_POST['category_id'] ?? 0);
    $name = trim($_POST['category_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($id <= 0 || $name === '') {
        $message = "Invalid category information!";
        $messageType = "error";
    } else {
        try {
            $stmt = $conn->prepare(
                "UPDATE categories
                 SET category_name = ?, description = ?
                 WHERE category_id = ?"
            );

            $stmt->execute([$name, $description, $id]);

            // RETURN TO NORMAL CATEGORY PAGE
            header("Location: categories.php?updated=1");
            exit;

        } catch (PDOException $e) {
            $message = "Unable to update category.";
            $messageType = "error";
        }
    }
}

// DELETE CATEGORY
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST['action'] ?? '') === 'delete'
) {
    $id = (int)($_POST['category_id'] ?? 0);

    try {
        // CHECK IF CATEGORY IS USED BY PRODUCTS
        $check = $conn->prepare(
            "SELECT COUNT(*) FROM products WHERE category_id = ?"
        );

        $check->execute([$id]);

        if ($check->fetchColumn() > 0) {
            $message = "Cannot delete: This category is assigned to products.";
            $messageType = "error";
        } else {
            $stmt = $conn->prepare(
                "DELETE FROM categories WHERE category_id = ?"
            );

            $stmt->execute([$id]);

            header("Location: categories.php?deleted=1");
            exit;
        }

    } catch (PDOException $e) {
        $message = "Unable to delete category.";
        $messageType = "error";
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
}

// GET ALL CATEGORIES
$stmt = $conn->query(
    "SELECT * FROM categories ORDER BY category_id DESC"
);

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<title>Category Management | Bakery System</title>

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
    max-width: 1100px;
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

.back:hover {
    text-decoration: underline;
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
textarea {
    width: 100%;
    padding: 12px;
    margin: 8px 0 15px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-family: Arial, sans-serif;
}

input:focus,
textarea:focus {
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

.edit:hover {
    background: #1d5e91;
}

.delete {
    background: #b83232;
}

.delete:hover {
    background: #8e2525;
}

.cancel {
    background: #777;
}

.cancel:hover {
    background: #555;
}

/* NOTIFICATIONS */

.message {
    padding: 13px 16px;
    border-radius: 6px;
    margin-top: 18px;
    font-size: 14px;
    transition: opacity 0.5s ease;
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

/* TABLE */

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

/* RESPONSIVE */

@media (max-width: 650px) {

    body {
        padding: 15px;
    }

    .form-box,
    .table-box {
        padding: 16px;
    }

    .table-box {
        overflow-x: auto;
    }

    table {
        min-width: 650px;
    }

}

</style>

</head>

<body>

<div class="container">

    <a class="back" href="dashboard.php">
        ← Back to Dashboard
    </a>

    <h1>Category Management</h1>

    <p class="subtitle">
        Manage your bakery product categories.
    </p>


    <!-- NOTIFICATION -->

    <?php if ($message): ?>

        <p
            class="message <?= $messageType === 'success'
                ? 'success-message'
                : 'error-message' ?>"
            id="categoryMessage"
        >
            <?= htmlspecialchars($message) ?>
        </p>

    <?php endif; ?>


    <!-- CATEGORY FORM -->

    <div class="form-box">

        <?php if ($editCategory): ?>

            <h2>Edit Category</h2>

            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="update"
                >

                <input
                    type="hidden"
                    name="category_id"
                    value="<?= (int)$editCategory['category_id'] ?>"
                >

                <label>Category Name</label>

                <input
                    type="text"
                    name="category_name"
                    value="<?= htmlspecialchars($editCategory['category_name']) ?>"
                    maxlength="100"
                    required
                >

                <label>Description</label>

                <textarea
                    name="description"
                    rows="3"
                ><?= htmlspecialchars($editCategory['description'] ?? '') ?></textarea>

                <button type="submit">
                    Update Category
                </button>

                <a
                    class="btn cancel"
                    href="categories.php"
                >
                    Cancel
                </a>

            </form>

        <?php else: ?>

            <h2>Add New Category</h2>

            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="add"
                >

                <label>Category Name</label>

                <input
                    type="text"
                    name="category_name"
                    placeholder="Example: Bread"
                    maxlength="100"
                    required
                >

                <label>Description</label>

                <textarea
                    name="description"
                    placeholder="Enter category description"
                    rows="3"
                ></textarea>

                <button type="submit">
                    Add Category
                </button>

            </form>

        <?php endif; ?>

    </div>


    <!-- CATEGORY LIST -->

    <div class="table-box">

        <h2>Category List</h2>

        <table>

            <thead>

                <tr>
                    <th>ID</th>
                    <th>Category Name</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>

            </thead>

            <tbody>

            <?php if ($categories): ?>

                <?php foreach ($categories as $category): ?>

                    <tr>

                        <td>
                            <?= (int)$category['category_id'] ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($category['category_name']) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($category['description'] ?? '') ?>
                        </td>

                        <td>

                            <div class="actions">

                                <a
                                    class="btn edit"
                                    href="categories.php?edit=<?= (int)$category['category_id'] ?>"
                                >
                                    Edit
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

                                    <button
                                        type="submit"
                                        class="delete"
                                    >
                                        Delete
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="4" class="empty">
                        No categories found.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- AUTO-HIDE NOTIFICATION -->

<script>

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
