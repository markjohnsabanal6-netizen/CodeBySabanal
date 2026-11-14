
<?php
session_start();

require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Default date range
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Validate dates
$start = DateTime::createFromFormat('Y-m-d', $start_date);
$end = DateTime::createFromFormat('Y-m-d', $end_date);

if (
    !$start || $start->format('Y-m-d') !== $start_date ||
    !$end || $end->format('Y-m-d') !== $end_date ||
    $start_date > $end_date
) {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-d');
}

// Sales summary
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_transactions,
        COALESCE(SUM(total_amount), 0) AS total_sales
    FROM sales
    WHERE sale_date >= :start_date
      AND sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$sales_summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Total items sold
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(sd.quantity), 0)
    FROM sale_details sd
    INNER JOIN sales s ON sd.sale_id = s.sale_id
    WHERE s.sale_date >= :start_date
      AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$total_items_sold = $stmt->fetchColumn();

// Total products
$total_products = $conn->query("
    SELECT COUNT(*) FROM products
")->fetchColumn();

// Best-selling products
$stmt = $conn->prepare("
    SELECT
        COALESCE(p.product_name, 'Deleted Product') AS product_name,
        SUM(sd.quantity) AS total_quantity,
        SUM(sd.subtotal) AS total_revenue
    FROM sale_details sd
    INNER JOIN sales s ON sd.sale_id = s.sale_id
    LEFT JOIN products p ON sd.product_id = p.product_id
    WHERE s.sale_date >= :start_date
      AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
    GROUP BY sd.product_id, p.product_name
    ORDER BY total_quantity DESC, total_revenue DESC
    LIMIT 5
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$best_sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Daily sales summary
$stmt = $conn->prepare("
    SELECT
        DATE(sale_date) AS sale_day,
        COUNT(*) AS transactions,
        SUM(total_amount) AS daily_sales
    FROM sales
    WHERE sale_date >= :start_date
      AND sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
    GROUP BY DATE(sale_date)
    ORDER BY sale_day DESC
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$daily_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Current inventory summary
$inventory_summary = $conn->query("
    SELECT
        COUNT(*) AS total_stock_items,
        COALESCE(SUM(CASE WHEN quantity > minimum_stock THEN 1 ELSE 0 END), 0) AS in_stock,
        COALESCE(SUM(CASE WHEN quantity > 0 AND quantity <= minimum_stock THEN 1 ELSE 0 END), 0) AS low_stock,
        COALESCE(SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock
    FROM inventory
")->fetch(PDO::FETCH_ASSOC);

// Inventory details
$inventory_details = $conn->query("
    SELECT
        p.product_name,
        i.quantity,
        i.minimum_stock,
        CASE
            WHEN i.quantity = 0 THEN 'Out of Stock'
            WHEN i.quantity <= i.minimum_stock THEN 'Low Stock'
            ELSE 'In Stock'
        END AS stock_status
    FROM inventory i
    INNER JOIN products p ON i.product_id = p.product_id
    ORDER BY p.product_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Sales transaction records
$stmt = $conn->prepare("
    SELECT
        s.sale_id,
        s.sale_date,
        u.full_name AS cashier,
        s.total_amount,
        s.payment_amount,
        s.change_amount
    FROM sales s
    INNER JOIN users u ON s.user_id = u.user_id
    WHERE s.sale_date >= :start_date
      AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
    ORDER BY s.sale_date DESC
");

$stmt->execute([
    'start_date' => $start_date,
    'end_date' => $end_date
]);

$sales_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Safe HTML output
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reports and Analytics</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 25px;
    font-family: Arial, sans-serif;
    background: #f4f6f9;
    color: #333;
}

.container {
    max-width: 1250px;
    margin: auto;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 25px;
}

.header h1 {
    margin: 0;
}

.back-btn {
    background: #343a40;
    color: white;
    padding: 10px 16px;
    text-decoration: none;
    border-radius: 5px;
}

.filter-box,
.section {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 22px;
    box-shadow: 0 2px 7px rgba(0,0,0,0.06);
}

.filter-box form {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 12px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

input[type="date"] {
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 5px;
}

button,
.action-btn {
    border: none;
    padding: 10px 15px;
    border-radius: 5px;
    cursor: pointer;
    color: white;
    text-decoration: none;
    display: inline-block;
    font-size: 14px;
}

.filter-btn {
    background: #007bff;
}

.csv-btn {
    background: #198754;
}

.excel-btn {
    background: #217346;
}

.print-btn {
    background: #6c757d;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 22px;
}

.summary-card {
    background: white;
    padding: 22px;
    border-radius: 8px;
    box-shadow: 0 2px 7px rgba(0,0,0,0.06);
}

.summary-card h3 {
    margin: 0 0 12px;
    color: #666;
    font-size: 14px;
}

.summary-card p {
    margin: 0;
    font-size: 24px;
    font-weight: bold;
    overflow-wrap: anywhere;
}

.section h2 {
    margin-top: 0;
    font-size: 19px;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th, td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 14px;
}

th {
    background: #f1f3f5;
}

.status {
    padding: 5px 9px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
}

.in-stock {
    background: #d4edda;
    color: #155724;
}

.low-stock {
    background: #fff3cd;
    color: #856404;
}

.out-stock {
    background: #f8d7da;
    color: #721c24;
}

.empty {
    text-align: center;
    padding: 20px;
    color: #777;
}

.note {
    color: #777;
    font-size: 13px;
    margin-top: 10px;
}

@media (max-width: 800px) {
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 500px) {
    body {
        padding: 12px;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }
}

@media print {
    body {
        padding: 0;
        background: white;
    }

    .no-print {
        display: none !important;
    }

    .section,
    .summary-card {
        box-shadow: none;
        border: 1px solid #ddd;
        break-inside: avoid;
    }

    table {
        min-width: 0;
    }

    th, td {
        font-size: 10px;
        padding: 7px;
    }
}
</style>
</head>

<body>

<div class="container">

    <!-- HEADER -->
    <div class="header">
        <h1>Reports and Analytics</h1>

        <a href="dashboard.php" class="back-btn no-print">
            Back to Dashboard
        </a>
    </div>

    <!-- FILTER AND EXPORT BUTTONS -->
    <div class="filter-box no-print">

        <form method="GET">

            <div class="filter-group">
                <label>Start Date</label>
                <input
                    type="date"
                    name="start_date"
                    value="<?= e($start_date) ?>"
                    required
                >
            </div>

            <div class="filter-group">
                <label>End Date</label>
                <input
                    type="date"
                    name="end_date"
                    value="<?= e($end_date) ?>"
                    required
                >
            </div>

            <button type="submit" class="filter-btn">
                Filter Report
            </button>

            <a
                href="export_sales.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                class="action-btn csv-btn"
            >
                Export to CSV
            </a>

            <a
                href="export_sales_excel.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                class="action-btn excel-btn"
            >
                Export to Excel
            </a>

            <button
                type="button"
                class="print-btn"
                onclick="window.print()"
            >
                Print Report
            </button>

        </form>

    </div>

    <!-- SALES SUMMARY -->
    <div class="summary-grid">

        <div class="summary-card">
            <h3>Total Sales</h3>
            <p>₱<?= number_format((float)$sales_summary['total_sales'], 2) ?></p>
        </div>

        <div class="summary-card">
            <h3>Total Transactions</h3>
            <p><?= number_format((int)$sales_summary['total_transactions']) ?></p>
        </div>

        <div class="summary-card">
            <h3>Total Items Sold</h3>
            <p><?= number_format((int)$total_items_sold) ?></p>
        </div>

        <div class="summary-card">
            <h3>Total Products</h3>
            <p><?= number_format((int)$total_products) ?></p>
        </div>

    </div>

    <!-- BEST SELLING PRODUCTS -->
    <div class="section">

        <h2>Top 5 Best-Selling Products</h2>

        <div class="table-wrap">
            <table>

                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Product Name</th>
                        <th>Quantity Sold</th>
                        <th>Total Revenue</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($best_sellers): ?>

                    <?php foreach ($best_sellers as $index => $product): ?>

                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= e($product['product_name']) ?></td>
                        <td><?= number_format((int)$product['total_quantity']) ?></td>
                        <td>₱<?= number_format((float)$product['total_revenue'], 2) ?></td>
                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="4" class="empty">
                            No sales records for this date range.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </div>

    <!-- DAILY SALES -->
    <div class="section">

        <h2>Daily Sales Summary</h2>

        <div class="table-wrap">
            <table>

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transactions</th>
                        <th>Total Sales</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($daily_sales): ?>

                    <?php foreach ($daily_sales as $day): ?>

                    <tr>
                        <td><?= e($day['sale_day']) ?></td>
                        <td><?= number_format((int)$day['transactions']) ?></td>
                        <td>₱<?= number_format((float)$day['daily_sales'], 2) ?></td>
                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="3" class="empty">
                            No daily sales records found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </div>

    <!-- INVENTORY SUMMARY -->
    <div class="section">

        <h2>Current Inventory Summary</h2>

        <div class="summary-grid">

            <div class="summary-card">
                <h3>Total Inventory Items</h3>
                <p><?= number_format((int)$inventory_summary['total_stock_items']) ?></p>
            </div>

            <div class="summary-card">
                <h3>In Stock</h3>
                <p><?= number_format((int)$inventory_summary['in_stock']) ?></p>
            </div>

            <div class="summary-card">
                <h3>Low Stock</h3>
                <p><?= number_format((int)$inventory_summary['low_stock']) ?></p>
            </div>

            <div class="summary-card">
                <h3>Out of Stock</h3>
                <p><?= number_format((int)$inventory_summary['out_of_stock']) ?></p>
            </div>

        </div>

        <div class="table-wrap">
            <table>

                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Current Stock</th>
                        <th>Minimum Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($inventory_details): ?>

                    <?php foreach ($inventory_details as $item): ?>

                    <?php
                        $status_class = 'in-stock';

                        if ($item['stock_status'] === 'Low Stock') {
                            $status_class = 'low-stock';
                        } elseif ($item['stock_status'] === 'Out of Stock') {
                            $status_class = 'out-stock';
                        }
                    ?>

                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= number_format((int)$item['quantity']) ?></td>
                        <td><?= number_format((int)$item['minimum_stock']) ?></td>
                        <td>
                            <span class="status <?= $status_class ?>">
                                <?= e($item['stock_status']) ?>
                            </span>
                        </td>
                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="4" class="empty">
                            No inventory records found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

        <p class="note">
            Note: Inventory status shows the current stock,
            not the historical stock during the selected date range.
        </p>

    </div>

    <!-- SALES TRANSACTION RECORDS -->
    <div class="section">

        <h2>Sales Transaction Records</h2>

        <div class="table-wrap">
            <table>

                <thead>
                    <tr>
                        <th>Sale ID</th>
                        <th>Date</th>
                        <th>Cashier</th>
                        <th>Total Amount</th>
                        <th>Payment</th>
                        <th>Change</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($sales_records): ?>

                    <?php foreach ($sales_records as $sale): ?>

                    <tr>
                        <td><?= e($sale['sale_id']) ?></td>
                        <td><?= e($sale['sale_date']) ?></td>
                        <td><?= e($sale['cashier']) ?></td>
                        <td>₱<?= number_format((float)$sale['total_amount'], 2) ?></td>
                        <td>₱<?= number_format((float)$sale['payment_amount'], 2) ?></td>
                        <td>₱<?= number_format((float)$sale['change_amount'], 2) ?></td>
                    </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No sales transactions found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </div>

    <!-- REPORT PERIOD -->
    <p class="note">
        Report Period: <?= e($start_date) ?> to <?= e($end_date) ?>
    </p>

</div>

</body>
</html>
