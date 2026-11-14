
<?php
session_start();

require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Get date range from Reports page
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Validate date format
$start = DateTime::createFromFormat('Y-m-d', $start_date);
$end = DateTime::createFromFormat('Y-m-d', $end_date);

if (
    !$start || $start->format('Y-m-d') !== $start_date ||
    !$end || $end->format('Y-m-d') !== $end_date ||
    $start_date > $end_date
) {
    die("Invalid date range.");
}

try {
    $stmt = $conn->prepare("
        SELECT
            s.sale_id,
            s.sale_date,
            u.full_name AS cashier,
            p.product_name,
            sd.quantity,
            sd.unit_price,
            sd.subtotal,
            s.total_amount,
            s.payment_amount,
            s.change_amount
        FROM sales s
        INNER JOIN users u
            ON s.user_id = u.user_id
        INNER JOIN sale_details sd
            ON s.sale_id = sd.sale_id
        LEFT JOIN products p
            ON sd.product_id = p.product_id
        WHERE s.sale_date >= :start_date
          AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
        ORDER BY s.sale_date DESC, s.sale_id DESC
    ");

    $stmt->execute([
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);

    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // CSV download headers
    $filename = "bakery_sales_{$start_date}_to_{$end_date}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // CSV headings
    fputcsv($output, [
        'Sale ID',
        'Sale Date',
        'Cashier',
        'Product Name',
        'Quantity',
        'Unit Price',
        'Subtotal',
        'Total Amount',
        'Payment',
        'Change'
    ]);

    foreach ($records as $row) {
        fputcsv($output, [
            $row['sale_id'],
            $row['sale_date'],
            $row['cashier'],
            $row['product_name'] ?? 'Deleted Product',
            $row['quantity'],
            $row['unit_price'],
            $row['subtotal'],
            $row['total_amount'],
            $row['payment_amount'],
            $row['change_amount']
        ]);
    }

    fclose($output);
    exit;

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit("Unable to export sales records.");
}
