<?php
require 'db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch the full ledger profile belonging to this individual account
$stmt = $pdo->prepare("SELECT title, amount, type, date FROM transactions WHERE user_id = ? ORDER BY date DESC");
$stmt->execute([$user_id]);
$records = $stmt->fetchAll();

// Set HTTP headers to force an automatic spreadsheet file download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=Expense_Report_' . date('Y-m-d') . '.csv');

// Open the output stream buffer mapping layer
$output = fopen('php://output', 'w');

// Print column headers into row 1 of the spreadsheet file
fputcsv($output, ['Description', 'Amount (Rs.)', 'Transaction Type', 'Tracking Date']);

// Populate data entries sequentially
foreach ($records as $row) {
    fputcsv($output, [
        $row['title'],
        number_format($row['amount'], 2),
        ucfirst($row['type']),
        $row['date']
    ]);
}

fclose($output);
exit;
