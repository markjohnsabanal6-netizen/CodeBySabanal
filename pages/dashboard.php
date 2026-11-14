
<?php
session_start();
require_once '../config/database.php';

// Protect dashboard from unauthorized access
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Get logged-in user's information
$name = htmlspecialchars($_SESSION['full_name'] ?? 'User');
$role = $_SESSION['role'] ?? 'staff';
$isAdmin = ($role === 'admin');

// Get dashboard statistics
$totalProducts = $conn->query(
    "SELECT COUNT(*) FROM products"
)->fetchColumn();

$totalSales = $conn->query(
    "SELECT COALESCE(SUM(total_amount), 0)
     FROM sales
     WHERE DATE(sale_date) = CURDATE()"
)->fetchColumn();

$totalStock = $conn->query(
    "SELECT COALESCE(SUM(quantity), 0)
     FROM inventory"
)->fetchColumn();

$lowStock = $conn->query(
    "SELECT COUNT(*)
     FROM inventory
     WHERE quantity <= minimum_stock"
)->fetchColumn();

// Get recent transactions
$recentSales = $conn->query(
    "SELECT s.sale_id, s.total_amount, s.sale_date,
            u.full_name
     FROM sales s
     LEFT JOIN users u ON s.user_id = u.user_id
     ORDER BY s.sale_date DESC
     LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Dashboard | Bakery System</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f7f4ef;
    color: #333;
}

.sidebar {
    width: 240px;
    height: 100vh;
    background: #59391f;
    color: white;
    position: fixed;
    padding: 25px 15px;
    overflow-y: auto;
}

.sidebar h2 {
    text-align: center;
    margin-bottom: 35px;
}

.sidebar a {
    display: block;
    color: white;
    text-decoration: none;
    padding: 13px;
    margin-bottom: 8px;
    border-radius: 6px;
}

.sidebar a:hover,
.sidebar a.active {
    background: #82552f;
}

.sidebar .logout {
    background: #a83e32;
    margin-top: 30px;
}

.main {
    margin-left: 240px;
    padding: 30px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 30px;
}

.header h1 {
    color: #59391f;
}

.role-badge {
    background: #eee3d5;
    color: #59391f;
    padding: 9px 15px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: bold;
}

.cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
}

.card {
    background: white;
    padding: 22px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

.card h3 {
    font-size: 14px;
    color: #777;
}

.card p {
    font-size: 27px;
    font-weight: bold;
    color: #59391f;
    overflow-wrap: anywhere;
}

.table-box {
    margin-top: 30px;
    background: white;
    padding: 22px;
    border-radius: 10px;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

th, td {
    text-align: left;
    padding: 13px;
    border-bottom: 1px solid #eee;
}

th {
    background: #f7f4ef;
}

@media (max-width: 900px) {
    .cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 600px) {
    .sidebar {
        width: 100%;
        height: auto;
        position: relative;
        padding: 15px;
    }

    .sidebar h2 {
        margin-bottom: 15px;
    }

    .sidebar a {
        padding: 11px;
        margin-bottom: 5px;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }

    .cards {
        grid-template-columns: 1fr;
    }

    .header {
        align-items: flex-start;
    }
}
</style>
</head>

<body>

<div class="sidebar">

    <h2>🥐 Bakery System</h2>

    <a href="dashboard.php" class="active">
        Dashboard
    </a>

    <a href="products.php">
        Products
    </a>

    <a href="categories.php">
        Categories
    </a>

    <a href="inventory.php">
        Inventory
    </a>

    <a href="sales.php">
        Sales
    </a>

    <a href="reports.php">
        Reports
    </a>

    <?php if ($isAdmin): ?>
        <a href="user_management.php">
            User Management
        </a>
    <?php endif; ?>

    <a href="../logout.php" class="logout">
        Logout
    </a>

</div>

<div class="main">

    <div class="header">

        <div>
            <h1>Dashboard</h1>
            <p>Welcome back, <?= $name ?>!</p>
        </div>

        <div class="role-badge">
            <?= $isAdmin ? 'Administrator' : 'Staff' ?>
        </div>

    </div>

    <div class="cards">

        <div class="card">
            <h3>Total Products</h3>
            <p><?= number_format($totalProducts) ?></p>
        </div>

        <div class="card">
            <h3>Today's Sales</h3>
            <p>₱<?= number_format((float)$totalSales, 2) ?></p>
        </div>

        <div class="card">
            <h3>Available Stock</h3>
            <p><?= number_format($totalStock) ?></p>
        </div>

        <div class="card">
            <h3>Low Stock Products</h3>
            <p><?= number_format($lowStock) ?></p>
        </div>

    </div>

    <div class="table-box">

        <h2>Recent Sales Transactions</h2>

        <table>

            <thead>
                <tr>
                    <th>Sale ID</th>
                    <th>Cashier</th>
                    <th>Total Amount</th>
                    <th>Date</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($recentSales) > 0): ?>

                <?php foreach ($recentSales as $sale): ?>

                <tr>

                    <td>
                        #<?= (int)$sale['sale_id'] ?>
                    </td>

                    <td>
                        <?= htmlspecialchars(
                            $sale['full_name'] ?? 'Unknown'
                        ) ?>
                    </td>

                    <td>
                        ₱<?= number_format(
                            (float)$sale['total_amount'], 2
                        ) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($sale['sale_date']) ?>
                    </td>

                </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="4">
                        No sales transactions yet.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>
