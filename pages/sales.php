
<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$error = '';
$status = $_GET['status'] ?? '';
$sale_id = filter_input(INPUT_GET, 'sale_id', FILTER_VALIDATE_INT);

// PROCESS CHECKOUT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {

    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $payment_amount = (float)($_POST['payment_amount'] ?? 0);

    if (empty($product_ids) || empty($quantities)) {
        header("Location: sales.php?status=empty");
        exit;
    }

    // Combine duplicate products
    $cart = [];

    foreach ($product_ids as $index => $product_id) {
        $product_id = filter_var($product_id, FILTER_VALIDATE_INT);
        $quantity = filter_var($quantities[$index] ?? 0, FILTER_VALIDATE_INT);

        if (!$product_id || !$quantity || $quantity <= 0) {
            header("Location: sales.php?status=invalid");
            exit;
        }

        if (!isset($cart[$product_id])) {
            $cart[$product_id] = 0;
        }

        $cart[$product_id] += $quantity;
    }

    try {
        $conn->beginTransaction();

        $total_amount = 0;
        $sale_items = [];

        // Check products and lock inventory rows
        foreach ($cart as $product_id => $quantity) {

            $productStmt = $conn->prepare("
                SELECT product_id, product_name, price
                FROM products
                WHERE product_id = ?
            ");
            $productStmt->execute([$product_id]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception("A selected product does not exist.");
            }

            $stockStmt = $conn->prepare("
                SELECT quantity
                FROM inventory
                WHERE product_id = ?
                FOR UPDATE
            ");
            $stockStmt->execute([$product_id]);
            $inventory = $stockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                throw new Exception(
                    "Inventory record missing for " . $product['product_name']
                );
            }

            $current_stock = (int)$inventory['quantity'];

            if ($quantity > $current_stock) {
                throw new Exception(
                    "Not enough stock for " . $product['product_name'] .
                    ". Available stock: " . $current_stock
                );
            }

            $price = (float)$product['price'];
            $subtotal = $price * $quantity;

            $total_amount += $subtotal;

            $sale_items[] = [
                'product_id' => $product_id,
                'quantity' => $quantity,
                'price' => $price,
                'subtotal' => $subtotal
            ];
        }

        if ($payment_amount < $total_amount) {
            throw new Exception("Insufficient payment amount.");
        }

        $change_amount = $payment_amount - $total_amount;

        // Save sale
        $saleStmt = $conn->prepare("
            INSERT INTO sales
            (user_id, total_amount, payment_amount, change_amount)
            VALUES (?, ?, ?, ?)
        ");

        $saleStmt->execute([
            $_SESSION['user_id'],
            $total_amount,
            $payment_amount,
            $change_amount
        ]);

        $new_sale_id = (int)$conn->lastInsertId();

        // Save sale details and deduct inventory
        foreach ($sale_items as $item) {

            $detailStmt = $conn->prepare("
                INSERT INTO sale_details
                (sale_id, product_id, quantity, unit_price, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ");

            $detailStmt->execute([
                $new_sale_id,
                $item['product_id'],
                $item['quantity'],
                $item['price'],
                $item['subtotal']
            ]);

            $updateStock = $conn->prepare("
                UPDATE inventory
                SET quantity = quantity - ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE product_id = ?
            ");

            $updateStock->execute([
                $item['quantity'],
                $item['product_id']
            ]);

            // Record stock movement
            $movementStmt = $conn->prepare("
                INSERT INTO stock_movements
                (product_id, user_id, movement_type, quantity, remarks)
                VALUES (?, ?, 'stock_out', ?, 'Sold Products')
            ");

            $movementStmt->execute([
                $item['product_id'],
                $_SESSION['user_id'],
                $item['quantity']
            ]);
        }

        $conn->commit();

        header("Location: sales.php?status=success&sale_id=" . $new_sale_id);
        exit;

    } catch (Throwable $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        error_log($e->getMessage());

        $message = urlencode($e->getMessage());
        header("Location: sales.php?status=error&message=" . $message);
        exit;
    }
}

// GET PRODUCTS
$productStmt = $conn->query("
    SELECT
        p.product_id,
        p.product_name,
        p.price,
        c.category_name,
        COALESCE(i.quantity, 0) AS stock
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    LEFT JOIN inventory i ON p.product_id = i.product_id
    ORDER BY p.product_name ASC
");

$products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

// GET RECENT SALES
$recentStmt = $conn->query("
    SELECT
        s.sale_id,
        s.total_amount,
        s.payment_amount,
        s.change_amount,
        s.sale_date,
        u.full_name
    FROM sales s
    INNER JOIN users u ON s.user_id = u.user_id
    ORDER BY s.sale_id DESC
    LIMIT 10
");

$recent_sales = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

function peso($amount) {
    return '₱' . number_format((float)$amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales and POS</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f6f9;
    color: #333;
}

.container {
    max-width: 1200px;
    margin: auto;
    padding: 25px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
}

.header h2 {
    margin: 0;
}

.back-link {
    text-decoration: none;
    background: #555;
    color: white;
    padding: 10px 15px;
    border-radius: 5px;
}

.card {
    background: white;
    padding: 22px;
    border-radius: 8px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.card h3 {
    margin-top: 0;
}

.message {
    padding: 13px;
    border-radius: 5px;
    margin-bottom: 18px;
}

.success {
    background: #d1e7dd;
    color: #0f5132;
}

.error {
    background: #f8d7da;
    color: #842029;
}

.info {
    background: #cff4fc;
    color: #055160;
}

.receipt-link {
    display: inline-block;
    margin-top: 10px;
    padding: 9px 14px;
    background: #198754;
    color: white;
    text-decoration: none;
    border-radius: 5px;
}

.form-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    gap: 12px;
    margin-bottom: 15px;
}

label {
    display: block;
    margin-bottom: 7px;
    font-weight: bold;
    font-size: 14px;
}

input, select {
    width: 100%;
    padding: 11px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 14px;
}

button {
    border: none;
    padding: 11px 16px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
}

.add-btn {
    background: #0d6efd;
    color: white;
}

.checkout-btn {
    background: #198754;
    color: white;
    width: 100%;
    margin-top: 15px;
    font-weight: bold;
}

.remove-btn {
    background: #dc3545;
    color: white;
    padding: 7px 10px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

th {
    background: #f1f3f5;
}

.text-right {
    text-align: right;
}

.total-section {
    margin-top: 20px;
    max-width: 350px;
    margin-left: auto;
}

.total-section p {
    display: flex;
    justify-content: space-between;
    font-size: 16px;
}

.grand-total {
    font-size: 22px !important;
    font-weight: bold;
}

.empty {
    text-align: center;
    padding: 20px;
    color: #777;
}

@media(max-width: 700px) {
    .container {
        padding: 12px;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .card {
        overflow-x: auto;
    }

    table {
        min-width: 550px;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="header">
        <h2>Sales and Point of Sale</h2>
        <a href="dashboard.php" class="back-link">Back to Dashboard</a>
    </div>

    <?php if ($status === 'success' && $sale_id): ?>
        <div class="message success">
            <strong>Sale completed successfully!</strong><br>
            Your sale has been recorded and inventory has been updated.<br>

            <a class="receipt-link"
               href="receipt.php?sale_id=<?= (int)$sale_id ?>"
               target="_blank">
                Print Sales Receipt
            </a>
        </div>

    <?php elseif ($status === 'empty'): ?>
        <div class="message info">Please add products to your cart first.</div>

    <?php elseif ($status === 'invalid'): ?>
        <div class="message error">Invalid product or quantity. Please try again.</div>

    <?php elseif ($status === 'error'): ?>
        <div class="message error">
            <?= htmlspecialchars($_GET['message'] ?? 'Unable to complete sale.') ?>
        </div>
    <?php endif; ?>


    <!-- PRODUCT SELECTION -->
    <div class="card">
        <h3>Select Products</h3>

        <div class="form-row">

            <div>
                <label for="productSelect">Product</label>

                <select id="productSelect">
                    <option value="">-- Select Product --</option>

                    <?php foreach ($products as $product): ?>
                        <?php if ((int)$product['stock'] > 0): ?>
                            <option
                                value="<?= (int)$product['product_id'] ?>"
                                data-name="<?= htmlspecialchars($product['product_name'], ENT_QUOTES) ?>"
                                data-price="<?= htmlspecialchars((string)$product['price'], ENT_QUOTES) ?>"
                                data-stock="<?= (int)$product['stock'] ?>"
                            >
                                <?= htmlspecialchars($product['product_name']) ?>
                                (Stock: <?= (int)$product['stock'] ?>)
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="productPrice">Price</label>
                <input type="text" id="productPrice" readonly placeholder="₱0.00">
            </div>

            <div>
                <label for="productQuantity">Quantity</label>
                <input type="number" id="productQuantity" min="1" value="1">
            </div>

        </div>

        <button type="button" class="add-btn" id="addProduct">
            + Add Product
        </button>
    </div>


    <!-- CART -->
    <div class="card">
        <h3>Shopping Cart</h3>

        <form method="POST" id="checkoutForm">

            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Subtotal</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody id="cartBody">
                    <tr>
                        <td colspan="5" class="empty">
                            No products added yet.
                        </td>
                    </tr>
                </tbody>
            </table>

            <div id="hiddenInputs"></div>

            <div class="total-section">

                <p class="grand-total">
                    <span>Total:</span>
                    <span id="totalAmount">₱0.00</span>
                </p>

                <label for="paymentAmount">Cash Received</label>
                <input
                    type="number"
                    id="paymentAmount"
                    name="payment_amount"
                    min="0"
                    step="0.01"
                    placeholder="Enter payment amount"
                    required
                >

                <p>
                    <span>Change:</span>
                    <strong id="changeAmount">₱0.00</strong>
                </p>

                <button
                    type="submit"
                    name="checkout"
                    value="1"
                    class="checkout-btn"
                    id="checkoutButton"
                    disabled
                >
                    Complete Sale
                </button>

            </div>

        </form>
    </div>


    <!-- RECENT SALES -->
    <div class="card">
        <h3>Recent Sales</h3>

        <table>
            <thead>
                <tr>
                    <th>Sale ID</th>
                    <th>Cashier</th>
                    <th>Total</th>
                    <th>Date</th>
                    <th>Receipt</th>
                </tr>
            </thead>

            <tbody>

                <?php if (empty($recent_sales)): ?>
                    <tr>
                        <td colspan="5" class="empty">No sales recorded yet.</td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($recent_sales as $sale): ?>
                        <tr>
                            <td>#<?= (int)$sale['sale_id'] ?></td>

                            <td>
                                <?= htmlspecialchars($sale['full_name']) ?>
                            </td>

                            <td><?= peso($sale['total_amount']) ?></td>

                            <td>
                                <?= htmlspecialchars(date(
                                    'M d, Y h:i A',
                                    strtotime($sale['sale_date'])
                                )) ?>
                            </td>

                            <td>
                                <a href="receipt.php?sale_id=<?= (int)$sale['sale_id'] ?>"
                                   target="_blank">
                                    View Receipt
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>
        </table>
    </div>

</div>


<script>
let cart = [];

const productSelect = document.getElementById('productSelect');
const productPrice = document.getElementById('productPrice');
const productQuantity = document.getElementById('productQuantity');
const cartBody = document.getElementById('cartBody');
const hiddenInputs = document.getElementById('hiddenInputs');
const totalAmount = document.getElementById('totalAmount');
const paymentAmount = document.getElementById('paymentAmount');
const changeAmount = document.getElementById('changeAmount');
const checkoutButton = document.getElementById('checkoutButton');

function formatPeso(amount) {
    return '₱' + amount.toFixed(2);
}

// Display selected product price
productSelect.addEventListener('change', function() {
    const option = this.options[this.selectedIndex];

    if (!this.value) {
        productPrice.value = '';
        return;
    }

    const price = Number(option.dataset.price);
    productPrice.value = formatPeso(price);
});

// Add product to cart
document.getElementById('addProduct').addEventListener('click', function() {

    const option = productSelect.options[productSelect.selectedIndex];

    if (!productSelect.value) {
        alert('Please select a product.');
        return;
    }

    const productId = Number(productSelect.value);
    const name = option.dataset.name;
    const price = Number(option.dataset.price);
    const stock = Number(option.dataset.stock);
    const quantity = Number(productQuantity.value);

    if (!Number.isInteger(quantity) || quantity <= 0) {
        alert('Please enter a valid quantity.');
        return;
    }

    let existing = cart.find(item => item.id === productId);
    const currentQuantity = existing ? existing.quantity : 0;

    if (currentQuantity + quantity > stock) {
        alert('Not enough stock. Available stock: ' + stock);
        return;
    }

    if (existing) {
        existing.quantity += quantity;
    } else {
        cart.push({
            id: productId,
            name: name,
            price: price,
            quantity: quantity,
            stock: stock
        });
    }

    productSelect.value = '';
    productPrice.value = '';
    productQuantity.value = 1;

    renderCart();
});

// Remove product
function removeProduct(index) {
    cart.splice(index, 1);
    renderCart();
}

// Render cart
function renderCart() {

    cartBody.replaceChildren();
    hiddenInputs.replaceChildren();

    let total = 0;

    if (cart.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');

        cell.colSpan = 5;
        cell.className = 'empty';
        cell.textContent = 'No products added yet.';

        row.appendChild(cell);
        cartBody.appendChild(row);
    }

    cart.forEach((item, index) => {

        const subtotal = item.price * item.quantity;
        total += subtotal;

        const row = document.createElement('tr');

        const values = [
            item.name,
            formatPeso(item.price),
            item.quantity,
            formatPeso(subtotal)
        ];

        values.forEach(value => {
            const cell = document.createElement('td');
            cell.textContent = value;
            row.appendChild(cell);
        });

        const actionCell = document.createElement('td');
        const removeButton = document.createElement('button');

        removeButton.type = 'button';
        removeButton.className = 'remove-btn';
        removeButton.textContent = 'Remove';
        removeButton.addEventListener('click', () => removeProduct(index));

        actionCell.appendChild(removeButton);
        row.appendChild(actionCell);

        cartBody.appendChild(row);

        // Hidden inputs submitted to PHP
        const productInput = document.createElement('input');
        productInput.type = 'hidden';
        productInput.name = 'product_id[]';
        productInput.value = item.id;

        const quantityInput = document.createElement('input');
        quantityInput.type = 'hidden';
        quantityInput.name = 'quantity[]';
        quantityInput.value = item.quantity;

        hiddenInputs.appendChild(productInput);
        hiddenInputs.appendChild(quantityInput);
    });

    totalAmount.textContent = formatPeso(total);

    updateChange();

    checkoutButton.disabled = cart.length === 0;
}

// Calculate change
function updateChange() {

    const total = cart.reduce(
        (sum, item) => sum + item.price * item.quantity,
        0
    );

    const payment = Number(paymentAmount.value) || 0;
    const change = Math.max(payment - total, 0);

    changeAmount.textContent = formatPeso(change);

    checkoutButton.disabled =
        cart.length === 0 || payment < total;
}

paymentAmount.addEventListener('input', updateChange);

// Confirm checkout
document.getElementById('checkoutForm').addEventListener('submit', function(event) {

    if (cart.length === 0) {
        event.preventDefault();
        alert('Please add products to your cart.');
        return;
    }

    const total = cart.reduce(
        (sum, item) => sum + item.price * item.quantity,
        0
    );

    if (Number(paymentAmount.value) < total) {
        event.preventDefault();
        alert('Insufficient payment amount.');
        return;
    }

    if (!confirm('Are you sure you want to complete this sale?')) {
        event.preventDefault();
    }
});

renderCart();
</script>

</body>
</html>
