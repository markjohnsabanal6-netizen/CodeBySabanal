
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

// Initialize inventory records for products without inventory.
try {
    $conn->exec("
        INSERT INTO inventory (product_id, quantity, minimum_stock)
        SELECT p.product_id, 0, 5
        FROM products p
        LEFT JOIN inventory i ON p.product_id = i.product_id
        WHERE i.product_id IS NULL
    ");
} catch (PDOException $e) {
    die("Unable to initialize inventory: " . $e->getMessage());
}

// Handle form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

    try {
        if (!$product_id || $product_id < 1) {
            throw new Exception("Invalid product selected.");
        }

        // STOCK IN AND STOCK OUT
        if ($action === 'stock_in' || $action === 'stock_out') {

            $amount = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);

            if (!$amount || $amount < 1) {
                throw new Exception("Please enter a valid quantity.");
            }

            $remark_choice = trim($_POST['remarks_choice'] ?? '');
            $custom_remarks = trim($_POST['custom_remarks'] ?? '');

            $allowed_remarks = [
                'stock_in' => [
                    'New Production',
                    'Supplier Delivery',
                    'Restocking',
                    'Customer Return',
                    'Stock Adjustment'
                ],
                'stock_out' => [
                    'Sold Products',
                    'Damaged Products',
                    'Expired Products',
                    'Production Use',
                    'Returned to Supplier',
                    'Stock Adjustment'
                ]
            ];

            if ($remark_choice === 'Custom Remark') {
                if ($custom_remarks === '') {
                    throw new Exception("Please enter your custom remark.");
                }

                $remarks = $custom_remarks;
            } else {
                if (!in_array($remark_choice, $allowed_remarks[$action], true)) {
                    throw new Exception("Please select a valid remark.");
                }

                $remarks = $remark_choice;
            }

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT quantity
                FROM inventory
                WHERE product_id = ?
                FOR UPDATE
            ");

            $stmt->execute([$product_id]);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                throw new Exception("Inventory record not found.");
            }

            $current_quantity = (int) $inventory['quantity'];

            if ($action === 'stock_out' && $amount > $current_quantity) {
                throw new Exception("Not enough stock available.");
            }

            if ($action === 'stock_in') {
                $new_quantity = $current_quantity + $amount;
                $movement_type = 'stock_in';
            } else {
                $new_quantity = $current_quantity - $amount;
                $movement_type = 'stock_out';
            }

            // Update current stock.
            $update = $conn->prepare("
                UPDATE inventory
                SET quantity = ?
                WHERE product_id = ?
            ");

            $update->execute([$new_quantity, $product_id]);

            // Record stock movement.
            $movement = $conn->prepare("
                INSERT INTO stock_movements
                    (product_id, user_id, movement_type, quantity, remarks)
                VALUES (?, ?, ?, ?, ?)
            ");

            $movement->execute([
                $product_id,
                $_SESSION['user_id'],
                $movement_type,
                $amount,
                $remarks
            ]);

            $conn->commit();

            $status = $action === 'stock_in'
                ? 'stock_in_success'
                : 'stock_out_success';

            header("Location: inventory.php?status=" . $status);
            exit;
        }

        // UPDATE MINIMUM STOCK
        if ($action === 'set_minimum') {

            $minimum_stock = filter_input(
                INPUT_POST,
                'minimum_stock',
                FILTER_VALIDATE_INT
            );

            if (
                $minimum_stock === false ||
                $minimum_stock === null ||
                $minimum_stock < 0
            ) {
                throw new Exception("Please enter a valid minimum stock level.");
            }

            $stmt = $conn->prepare("
                UPDATE inventory
                SET minimum_stock = ?
                WHERE product_id = ?
            ");

            $stmt->execute([$minimum_stock, $product_id]);

            header("Location: inventory.php?status=minimum_updated");
            exit;
        }

        throw new Exception("Invalid action.");

    } catch (PDOException $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        header("Location: inventory.php?error=" . urlencode("A database error occurred."));
        exit;

    } catch (Exception $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        header("Location: inventory.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}

// GET INVENTORY
$stmt = $conn->query("
    SELECT
        p.product_id,
        p.product_name,
        c.category_name,
        i.quantity,
        i.minimum_stock,
        i.updated_at
    FROM products p
    LEFT JOIN categories c
        ON p.category_id = c.category_id
    INNER JOIN inventory i
        ON p.product_id = i.product_id
    ORDER BY p.product_name ASC
");

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// GET RECENT STOCK MOVEMENTS
$stmt = $conn->query("
    SELECT
        sm.movement_type,
        sm.quantity,
        sm.remarks,
        sm.movement_date,
        p.product_name,
        u.full_name
    FROM stock_movements sm
    INNER JOIN products p
        ON sm.product_id = p.product_id
    INNER JOIN users u
        ON sm.user_id = u.user_id
    ORDER BY sm.movement_date DESC
    LIMIT 20
");

$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// INVENTORY SUMMARY
$total_products = count($products);
$low_stock_count = 0;
$total_stock = 0;

foreach ($products as $product) {
    $total_stock += (int) $product['quantity'];

    if ((int) $product['quantity'] <= (int) $product['minimum_stock']) {
        $low_stock_count++;
    }
}

// Secure output function.
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Inventory Management</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f4f5f7;
            color: #333;
        }

        .container {
            max-width: 1250px;
            margin: auto;
        }

        h1 {
            margin-bottom: 5px;
        }

        .subtitle {
            color: #777;
            margin-top: 0;
            margin-bottom: 25px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 18px;
            color: #5b3924;
            text-decoration: none;
            font-weight: bold;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            padding: 22px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .summary-card h3 {
            margin: 0 0 10px;
            color: #777;
            font-size: 14px;
        }

        .summary-card p {
            margin: 0;
            font-size: 27px;
            font-weight: bold;
        }

        .panel {
            background: white;
            padding: 22px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .message {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .success {
            background: #dff3e4;
            color: #216b35;
        }

        .error {
            background: #fbe2e2;
            color: #a12626;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 13px 10px;
            border-bottom: 1px solid #eee;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f8f6f3;
            color: #5b3924;
        }

        .low-stock {
            color: #c0392b;
            font-weight: bold;
        }

        .normal-stock {
            color: #218838;
            font-weight: bold;
        }

        .action-form {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 9px;
        }

        input, select, button {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }

        input[type="number"] {
            width: 85px;
        }

        input[type="text"] {
            width: 145px;
        }

        select {
            width: 165px;
            background: white;
        }

        button {
            border: none;
            color: white;
            cursor: pointer;
        }

        .stock-in-btn {
            background: #218838;
        }

        .stock-out-btn {
            background: #c0392b;
        }

        .minimum-btn {
            background: #5b3924;
        }

        button:hover {
            opacity: .85;
        }

        .custom-remark {
            display: none;
        }

        .empty {
            text-align: center;
            color: #777;
            padding: 25px;
        }

        @media (max-width: 700px) {
            body {
                padding: 12px;
            }

            .summary {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 14px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <a href="dashboard.php" class="back-link">
        ← Back to Dashboard
    </a>

    <h1>Inventory Management</h1>

    <p class="subtitle">
        Monitor product quantities and manage stock movements.
    </p>

    <?php
    $messages = [
        'stock_in_success' => 'Stock successfully added!',
        'stock_out_success' => 'Stock successfully deducted!',
        'minimum_updated' => 'Minimum stock level updated!'
    ];

    $status = $_GET['status'] ?? '';

    if (isset($messages[$status])):
    ?>
        <div class="message success">
            <?= e($messages[$status]) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="message error">
            <?= e($_GET['error']) ?>
        </div>
    <?php endif; ?>


    <!-- INVENTORY SUMMARY -->
    <div class="summary">

        <div class="summary-card">
            <h3>Total Products</h3>
            <p><?= $total_products ?></p>
        </div>

        <div class="summary-card">
            <h3>Total Stock Quantity</h3>
            <p><?= $total_stock ?></p>
        </div>

        <div class="summary-card">
            <h3>Low Stock Products</h3>
            <p><?= $low_stock_count ?></p>
        </div>

    </div>


    <!-- PRODUCT INVENTORY -->
    <div class="panel">

        <h2>Product Inventory</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Minimum Stock</th>
                        <th>Status</th>
                        <th>Manage Stock</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($products)): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No products found. Add products first.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($products as $product): ?>

                        <?php
                        $is_low =
                            (int) $product['quantity'] <=
                            (int) $product['minimum_stock'];
                        ?>

                        <tr>

                            <td>
                                <?= e($product['product_name']) ?>
                            </td>

                            <td>
                                <?= e($product['category_name'] ?? 'Uncategorized') ?>
                            </td>

                            <td>
                                <?= (int) $product['quantity'] ?>
                            </td>

                            <td>
                                <?= (int) $product['minimum_stock'] ?>
                            </td>

                            <td>
                                <?php if ($is_low): ?>
                                    <span class="low-stock">Low Stock</span>
                                <?php else: ?>
                                    <span class="normal-stock">In Stock</span>
                                <?php endif; ?>
                            </td>

                            <td>

                                <!-- STOCK IN -->
                                <form method="POST" class="action-form">

                                    <input type="hidden"
                                           name="product_id"
                                           value="<?= (int) $product['product_id'] ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="stock_in">

                                    <input type="number"
                                           name="quantity"
                                           min="1"
                                           placeholder="Quantity"
                                           required>

                                    <select name="remarks_choice"
                                            class="remark-select"
                                            required>

                                        <option value="">Select Remark</option>
                                        <option value="New Production">New Production</option>
                                        <option value="Supplier Delivery">Supplier Delivery</option>
                                        <option value="Restocking">Restocking</option>
                                        <option value="Customer Return">Customer Return</option>
                                        <option value="Stock Adjustment">Stock Adjustment</option>
                                        <option value="Custom Remark">Custom Remark</option>

                                    </select>

                                    <input type="text"
                                           name="custom_remarks"
                                           class="custom-remark"
                                           placeholder="Enter your remark...">

                                    <button type="submit" class="stock-in-btn">
                                        Stock In
                                    </button>

                                </form>


                                <!-- STOCK OUT -->
                                <form method="POST" class="action-form">

                                    <input type="hidden"
                                           name="product_id"
                                           value="<?= (int) $product['product_id'] ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="stock_out">

                                    <input type="number"
                                           name="quantity"
                                           min="1"
                                           max="<?= (int) $product['quantity'] ?>"
                                           placeholder="Quantity"
                                           required>

                                    <select name="remarks_choice"
                                            class="remark-select"
                                            required>

                                        <option value="">Select Remark</option>
                                        <option value="Sold Products">Sold Products</option>
                                        <option value="Damaged Products">Damaged Products</option>
                                        <option value="Expired Products">Expired Products</option>
                                        <option value="Production Use">Production Use</option>
                                        <option value="Returned to Supplier">Returned to Supplier</option>
                                        <option value="Stock Adjustment">Stock Adjustment</option>
                                        <option value="Custom Remark">Custom Remark</option>

                                    </select>

                                    <input type="text"
                                           name="custom_remarks"
                                           class="custom-remark"
                                           placeholder="Enter your remark...">

                                    <button type="submit" class="stock-out-btn">
                                        Stock Out
                                    </button>

                                </form>


                                <!-- SET MINIMUM STOCK -->
                                <form method="POST" class="action-form">

                                    <input type="hidden"
                                           name="product_id"
                                           value="<?= (int) $product['product_id'] ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="set_minimum">

                                    <input type="number"
                                           name="minimum_stock"
                                           min="0"
                                           value="<?= (int) $product['minimum_stock'] ?>"
                                           required>

                                    <button type="submit" class="minimum-btn">
                                        Set Minimum
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>


    <!-- RECENT STOCK MOVEMENTS -->
    <div class="panel">

        <h2>Recent Stock Movements</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Movement</th>
                        <th>Quantity</th>
                        <th>Remarks</th>
                        <th>Processed By</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($movements)): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No stock movements recorded yet.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($movements as $movement): ?>

                        <tr>

                            <td>
                                <?= e($movement['movement_date']) ?>
                            </td>

                            <td>
                                <?= e($movement['product_name']) ?>
                            </td>

                            <td>
                                <?= e(ucwords(str_replace('_', ' ', $movement['movement_type']))) ?>
                            </td>

                            <td>
                                <?= (int) $movement['quantity'] ?>
                            </td>

                            <td>
                                <?= e($movement['remarks'] ?: '-') ?>
                            </td>

                            <td>
                                <?= e($movement['full_name']) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<script>
// Show custom remark input when Custom Remark is selected.
document.querySelectorAll('.remark-select').forEach(function(select) {

    function toggleCustomRemark() {

        const form = select.closest('form');
        const customInput = form.querySelector('.custom-remark');

        if (select.value === 'Custom Remark') {

            customInput.style.display = 'inline-block';
            customInput.required = true;

        } else {

            customInput.style.display = 'none';
            customInput.required = false;
            customInput.value = '';

        }
    }

    select.addEventListener('change', toggleCustomRemark);

    toggleCustomRemark();

});
</script>

</body>
</html>
