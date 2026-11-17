
<?php

// Follow Windows Pacific Time
date_default_timezone_set('America/Los_Angeles');

session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$sale_id = filter_input(INPUT_GET, 'sale_id', FILTER_VALIDATE_INT);

if (!$sale_id || $sale_id <= 0) {
    die("Invalid receipt number.");
}

try {
    // Get sale information
    $stmt = $conn->prepare("
        SELECT
            s.sale_id,
            s.total_amount,
            s.payment_amount,
            s.change_amount,
            s.sale_date,
            u.full_name
        FROM sales s
        INNER JOIN users u ON s.user_id = u.user_id
        WHERE s.sale_id = :sale_id
        AND (s.user_id = :user_id OR :is_admin = 1)
    ");

    $stmt->execute([
        'sale_id' => $sale_id,
        'user_id' => $_SESSION['user_id'],
        'is_admin' => ($_SESSION['role'] ?? '') === 'admin' ? 1 : 0
    ]);

    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        die("Receipt not found or access denied.");
    }

    // Get purchased products
    $stmt = $conn->prepare("
        SELECT
            COALESCE(p.product_name, 'Deleted Product') AS product_name,
            sd.quantity,
            sd.unit_price,
            sd.subtotal
        FROM sale_details sd
        LEFT JOIN products p ON sd.product_id = p.product_id
        WHERE sd.sale_id = :sale_id
    ");

    $stmt->execute(['sale_id' => $sale_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Unable to load receipt.");
}

function peso($amount) {
    return '₱' . number_format((float)$amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?= (int)$sale['sale_id'] ?></title>

    <style>
        body {
            background: #f2f2f2;
            font-family: Arial, sans-serif;
            padding: 30px;
        }

        .receipt {
            background: white;
            width: 350px;
            max-width: 100%;
            margin: auto;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .center {
            text-align: center;
        }

        h2 {
            margin-bottom: 5px;
        }

        .small {
            font-size: 12px;
            color: #555;
        }

        hr {
            border: none;
            border-top: 1px dashed #777;
            margin: 15px 0;
        }

        .details {
            font-size: 13px;
            line-height: 1.7;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th, td {
            padding: 7px 2px;
            text-align: left;
        }

        th:last-child,
        td:last-child {
            text-align: right;
        }

        .totals p {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            margin: 8px 0;
        }

        .grand-total {
            font-weight: bold;
            font-size: 17px !important;
        }

        .actions {
            text-align: center;
            margin-top: 20px;
        }

        button, .back-link {
            padding: 10px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 4px;
            font-size: 14px;
        }

        button {
            background: #198754;
            color: white;
        }

        .back-link {
            background: #555;
            color: white;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .receipt {
                box-shadow: none;
                width: 100%;
                max-width: 350px;
                padding: 10px;
            }

            .actions {
                display: none;
            }
        }
    </style>
</head>

<body>

<div class="receipt">

    <div class="center">
        <h2>BAKERY SHOP</h2>
        <p class="small">Sales Receipt</p>
        <p class="small">Thank you for your purchase!</p>
    </div>

    <hr>

    <div class="details">
        <strong>Receipt No:</strong> #<?= (int)$sale['sale_id'] ?><br>

        <strong>Date:</strong>
        <?= htmlspecialchars(date('M d, Y h:i A', strtotime($sale['sale_date']))) ?><br>
        
        <strong>Cashier:</strong>
        <?= htmlspecialchars($sale['full_name']) ?>
    </div>

    <hr>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Qty</th>
                <th>Amount</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($item['product_name']) ?><br>
                        <span class="small">
                            <?= peso($item['unit_price']) ?> each
                        </span>
                    </td>

                    <td><?= (int)$item['quantity'] ?></td>

                    <td><?= peso($item['subtotal']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <hr>

    <div class="totals">
        <p>
            <span>Total:</span>
            <strong><?= peso($sale['total_amount']) ?></strong>
        </p>

        <p>
            <span>Cash Received:</span>
            <strong><?= peso($sale['payment_amount']) ?></strong>
        </p>

        <p class="grand-total">
            <span>Change:</span>
            <span><?= peso($sale['change_amount']) ?></span>
        </p>
    </div>

    <hr>

    <div class="center small">
        <p>Thank you for supporting our bakery!</p>
        <p>Please come again.</p>
    </div>

    <div class="actions">
        <button onclick="window.print()">Print Receipt</button>
        <a href="sales.php" class="back-link">Back to Sales</a>
    </div>

</div>

</body>
</html>
