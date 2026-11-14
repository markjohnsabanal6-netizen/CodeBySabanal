
<?php
session_start();

require_once '../config/database.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Check PhpSpreadsheet installation
$autoload = '../vendor/autoload.php';

if (!file_exists($autoload)) {
    die("PhpSpreadsheet is not installed. Please run Composer require first.");
}

require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// Get date filters
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
    die("Invalid date range.");
}

try {

    // Get sales records
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

    // Create Excel workbook
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->setTitle('Sales Report');

    // Report title
    $sheet->mergeCells('A1:J1');
    $sheet->setCellValue('A1', 'BAKERY SALES REPORT');

    $sheet->mergeCells('A2:J2');
    $sheet->setCellValue(
        'A2',
        'Report Period: ' . $start_date . ' to ' . $end_date
    );

    // Column headings
    $headers = [
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
    ];

    $sheet->fromArray($headers, NULL, 'A4');

    // Format title
    $sheet->getStyle('A1:J1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 18,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '198754']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);

    $sheet->getRowDimension(1)->setRowHeight(32);

    // Format report period
    $sheet->getStyle('A2:J2')->applyFromArray([
        'font' => [
            'italic' => true,
            'size' => 11
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER
        ]
    ]);

    // Format column headers
    $sheet->getStyle('A4:J4')->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '343A40']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'CCCCCC']
            ]
        ]
    ]);

    // Insert records
    $row = 5;

    foreach ($records as $record) {

        // Explicit text cells to prevent formula interpretation
        $sheet->setCellValueExplicit(
            'A' . $row,
            (string)$record['sale_id'],
            DataType::TYPE_STRING
        );

        $sheet->setCellValueExplicit(
            'B' . $row,
            (string)$record['sale_date'],
            DataType::TYPE_STRING
        );

        $sheet->setCellValueExplicit(
            'C' . $row,
            (string)$record['cashier'],
            DataType::TYPE_STRING
        );

        $sheet->setCellValueExplicit(
            'D' . $row,
            (string)($record['product_name'] ?? 'Deleted Product'),
            DataType::TYPE_STRING
        );

        $sheet->setCellValue('E' . $row, (int)$record['quantity']);
        $sheet->setCellValue('F' . $row, (float)$record['unit_price']);
        $sheet->setCellValue('G' . $row, (float)$record['subtotal']);
        $sheet->setCellValue('H' . $row, (float)$record['total_amount']);
        $sheet->setCellValue('I' . $row, (float)$record['payment_amount']);
        $sheet->setCellValue('J' . $row, (float)$record['change_amount']);

        $row++;
    }

    // Handle empty report
    if (empty($records)) {
        $sheet->mergeCells('A5:J5');
        $sheet->setCellValue('A5', 'No sales records found for this date range.');
        $sheet->getStyle('A5')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $lastRow = 5;
    } else {
        $lastRow = $row - 1;
    }

    // Currency formatting
    if (!empty($records)) {
        $sheet->getStyle('F5:J' . $lastRow)
            ->getNumberFormat()
            ->setFormatCode('"₱"#,##0.00');

        $sheet->getStyle('E5:E' . $lastRow)
            ->getNumberFormat()
            ->setFormatCode('#,##0');
    }

    // Borders
    $sheet->getStyle('A4:J' . $lastRow)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'DDDDDD']
            ]
        ]
    ]);

    // Alternate row colors
    for ($r = 5; $r <= $lastRow; $r++) {
        if ($r % 2 == 0) {
            $sheet->getStyle('A' . $r . ':J' . $r)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('F2F6F8');
        }
    }

    // Auto-size columns
    foreach (range('A', 'J') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Freeze heading rows
    $sheet->freezePane('A5');

    // Add filter
    if (!empty($records)) {
        $sheet->setAutoFilter('A4:J' . $lastRow);
    }

    // Page setup for printing
    $sheet->getPageSetup()
        ->setOrientation(
            \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE
        );

    $sheet->getPageSetup()
        ->setFitToWidth(1);

    $sheet->getPageSetup()
        ->setFitToHeight(0);

    // Download Excel file
    $filename = "Bakery_Sales_Report_{$start_date}_to_{$end_date}.xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    exit;

} catch (Throwable $e) {

    error_log($e->getMessage());

    http_response_code(500);
    exit("Unable to generate Excel report. Please check the server error log.");
}
