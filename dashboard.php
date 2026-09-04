<?php
require 'db.php';
session_start();

// Strict session check: Boot unauthorized guests out to the log in panel
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// 1. Process Form Submissions (Adding items with Category handling)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    $title = trim($_POST['title']);
    $amount = floatval($_POST['amount']);
    $type = $_POST['type'];
    $date = $_POST['date'];

    if (!empty($title) && $amount > 0 && !empty($date)) {
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, title, amount, type, date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $amount, $type, $date]);
        header("Location: dashboard.php"); // Refresh pattern to prevent double submissions
        exit;
    }
}

// 2. Handle Entry Deletions via GET query parameters
if (isset($_GET['delete'])) {
    $transaction_id = intval($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
    $stmt->execute([$transaction_id, $user_id]);
    header("Location: dashboard.php");
    exit;
}

// 3. Extract Filtering Choices via GET Parameters
$filter_month = isset($_GET['filter_month']) ? $_GET['filter_month'] : '';
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';

// 4. Build Dynamic SQL Query for Filtering Ledger and Metrics
$query = "SELECT * FROM transactions WHERE user_id = :user_id";
$params = [':user_id' => $user_id];

if (!empty($filter_month)) {
    $query .= " AND DATE_FORMAT(date, '%Y-%m') = :filter_month";
    $params[':filter_month'] = $filter_month;
}

if (!empty($filter_type)) {
    $query .= " AND type = :filter_type";
    $params[':filter_type'] = $filter_type;
}

$query .= " ORDER BY date DESC, id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// 5. Calculate Metrics dynamically based on the active filtered results
$total_income = 0;
$total_expense = 0;

foreach ($transactions as $t) {
    if ($t['type'] === 'income') {
        $total_income += $t['amount'];
    } else {
        $total_expense += $t['amount'];
    }
}
$current_balance = $total_income - $total_expense;

// 6. Fetch distinct months from database to auto-populate our filter dropdown list
$month_stmt = $pdo->prepare("SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as m_val, DATE_FORMAT(date, '%M %Y') as m_text FROM transactions WHERE user_id = ? ORDER BY date DESC");
$month_stmt->execute([$user_id]);
$available_months = $month_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Personal Expense Tracker</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; color: #2c3e50; }
        .container { max-width: 1100px; margin: 0 auto; }
        
        /* Modern Header styling */
        .header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
        .header h1 { margin: 0; font-size: 22px; color: #1a202c; }
        .header-actions { display: flex; gap: 12px; }
        .logout-btn { color: #e74c3c; text-decoration: none; font-weight: bold; border: 1.5px solid #e74c3c; padding: 8px 16px; border-radius: 6px; font-size: 14px; transition: 0.2s; }
        .logout-btn:hover { background: #e74c3c; color: white; }
        
        /* Excel Export utility markup button link */
        .export-btn { background: #27ae60; color: white; text-decoration: none; font-weight: bold; padding: 9px 16px; border-radius: 6px; font-size: 14px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .export-btn:hover { background: #219653; }

        /* Filter Component Bar Panel */
        .filter-box { background: #fff; padding: 15px 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
        .filter-box form { display: flex; align-items: center; gap: 15px; width: 100%; flex-wrap: wrap; }
        .filter-group { display: flex; align-items: center; gap: 8px; }
        .filter-group label { font-weight: 600; color: #4a5568; font-size: 14px; margin: 0; }
        .filter-group select { width: auto; min-width: 140px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; background-color: #fff; }
        .filter-btn { padding: 8px 16px; background: #3498db; border: none; color: white; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; font-size: 14px; transition: 0.2s; }
        .filter-btn:hover { background: #2980b9; }
        .clear-btn { background: #95a5a6; }
        .clear-btn:hover { background: #7f8c8d; }

        /* Sleek Balance Summary Presentation Cards Layout */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .card { background: #fff; padding: 22px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); position: relative; overflow: hidden; border-left: 5px solid #cbd5e1; }
        .card h3 { margin: 0 0 8px 0; color: #718096; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .card p { margin: 0; font-size: 26px; font-weight: 700; color: #1a202c; }
        .card-balance { border-left-color: #34495e; }
        .card-income { border-left-color: #2ecc71; }
        .card-expense { border-left-color: #e74c3c; }
        .income-val { color: #2ecc71 !important; }
        .expense-val { color: #e74c3c !important; }

        /* Main Workspace Split layout */
        .main-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 25px; }
        .box { background: #fff; padding: 22px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); height: fit-content; margin-bottom: 25px; }
        .box h2 { margin-top: 0; font-size: 18px; border-bottom: 2px solid #f4f7f6; padding-bottom: 12px; color: #1a202c; }
        
        /* Form Styling with custom Category mappings */
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; color: #4a5568; font-size: 14px; }
        input, select { width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; transition: 0.2s; background: #fff; }
        input:focus, select:focus { border-color: #3498db; outline: none; }
        .submit-btn { width: 100%; padding: 12px; background: #2ecc71; border: none; color: white; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: bold; transition: 0.2s; }
        .submit-btn:hover { background: #27ae60; }
        .alert-error { background: #fde8e8; color: #e74c3c; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; display: none; }

        /* Table Architecture with custom Category Badge markers */
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 14px 12px; border-bottom: 1px solid #edf2f7; }
        th { background: #f8fafc; color: #4a5568; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        td { font-size: 14px; }
        
        /* Contextual Status Badges */
        .type-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-income { background: #e8f8f0; color: #2ecc71; }
        .badge-expense { background: #fde8e8; color: #e74c3c; }
        .delete-link { color: #cbd5e1; text-decoration: none; font-size: 20px; line-height: 1; transition: 0.2s; }
        .delete-link:hover { color: #e74c3c; }
        .no-data { text-align: center; color: #a0aec0; padding: 40px 20px; font-size: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Application Layout Header -->
        <div class="header">
            <h1>Welcome back, <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>!</h1>
            <div class="header-actions">
                <a href="export.php" class="export-btn">📊 Export Spreadsheet (CSV)</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <!-- Filter Component Bar Panel -->
        <div class="filter-box">
            <form method="GET" action="dashboard.php">
                <div class="filter-group">
                    <label for="filter_month">Month:</label>
                    <select name="filter_month" id="filter_month">
                        <option value="">All Months</option>
                        <?php foreach ($available_months as $m): ?>
                            <option value="<?= $m['m_val'] ?>" <?= $filter_month === $m['m_val'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['m_text']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_type">Type:</label>
                    <select name="filter_type" id="filter_type">
                        <option value="">All Types</option>
                        <option value="income" <?= $filter_type === 'income' ? 'selected' : '' ?>>Income Only</option>
                        <option value="expense" <?= $filter_type === 'expense' ? 'selected' : '' ?>>Expense Only</option>
                    </select>
                </div>

                <button type="submit" class="filter-btn">Apply Filters</button>
                <?php if (!empty($filter_month) || !empty($filter_type)): ?>
                    <a href="dashboard.php" class="filter-btn clear-btn">Clear Filters</a>
                <?php endif; ?>
            </form>
        </div>

