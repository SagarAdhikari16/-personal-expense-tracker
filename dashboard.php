<?php
require 'db.php';
session_start();

// Strict session check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Predefined Categories
$categories = [
    'income' => ['Salary', 'Freelance', 'Investment', 'Gift', 'Other Income'],
    'expense' => ['Food & Dining', 'Rent & Housing', 'Utilities', 'Entertainment', 'Shopping', 'Transport', 'Healthcare', 'Other Expense']
];

// Handle Monthly Budget Settings
if (isset($_POST['set_budget'])) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['monthly_budget'] = floatval($_POST['monthly_budget']);
    }
}
$monthly_budget = $_SESSION['monthly_budget'] ?? 1000.00; // Default budget

// 1. Process Form Submissions (Add Transaction)
$form_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    }

    $title = trim($_POST['title']);
    $amount = floatval($_POST['amount']);
    $type = $_POST['type'] ?? '';
    $category = trim($_POST['category'] ?? 'Other');
    $date = $_POST['date'];

    if (!empty($title) && $amount > 0 && !empty($date) && in_array($type, ['income', 'expense'])) {
        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, title, amount, category, type, date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $amount, $category, $type, $date]);
        header("Location: dashboard.php");
        exit;
    } else {
        $form_error = "Please fill in all fields with valid information.";
    }
}

// 2. Handle Entry Deletions (CSRF Safe)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("CSRF token validation failed.");
    }

    $transaction_id = intval($_POST['delete_id']);
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
    $stmt->execute([$transaction_id, $user_id]);
    header("Location: dashboard.php");
    exit;
}

// 3. Extract Filtering Choices via GET Parameters
$filter_month = $_GET['filter_month'] ?? '';
$filter_type = $_GET['filter_type'] ?? '';
$filter_category = $_GET['filter_category'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// 4. Build Dynamic SQL Query for Filtering
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
if (!empty($filter_category)) {
    $query .= " AND category = :filter_category";
    $params[':filter_category'] = $filter_category;
}
if (!empty($start_date)) {
    $query .= " AND date >= :start_date";
    $params[':start_date'] = $start_date;
}
if (!empty($end_date)) {
    $query .= " AND date <= :end_date";
    $params[':end_date'] = $end_date;
}

$query .= " ORDER BY date DESC, id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// 5. Calculate Dynamic Metrics
$total_income = 0;
$total_expense = 0;
$category_breakdown = [];

foreach ($transactions as $t) {
    if ($t['type'] === 'income') {
        $total_income += $t['amount'];
    } else {
        $total_expense += $t['amount'];
        $cat = $t['category'] ?? 'Uncategorized';
        $category_breakdown[$cat] = ($category_breakdown[$cat] ?? 0) + $t['amount'];
    }
}
$current_balance = $total_income - $total_expense;
$budget_used_pct = $monthly_budget > 0 ? min(100, round(($total_expense / $monthly_budget) * 100)) : 0;

// 6. Fetch Available Months for Dropdown
$month_stmt = $pdo->prepare("SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as m_val, DATE_FORMAT(date, '%M %Y') as m_text FROM transactions WHERE user_id = ? ORDER BY date DESC");
$month_stmt->execute([$user_id]);
$available_months = $month_stmt->fetchAll();

// Build Query String for CSV Export Link
$export_params = http_build_query([
    'filter_month' => $filter_month,
    'filter_type' => $filter_type,
    'filter_category' => $filter_category,
    'start_date' => $start_date,
    'end_date' => $end_date
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personal Finance Tracker Pro</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; color: #2c3e50; }
        .container { max-width: 1150px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
        .header h1 { margin: 0; font-size: 22px; color: #1a202c; }
        .header-actions { display: flex; gap: 12px; }
        .logout-btn { color: #e74c3c; text-decoration: none; font-weight: bold; border: 1.5px solid #e74c3c; padding: 8px 16px; border-radius: 6px; font-size: 14px; transition: 0.2s; }
        .logout-btn:hover { background: #e74c3c; color: white; }
        .export-btn { background: #27ae60; color: white; text-decoration: none; font-weight: bold; padding: 9px 16px; border-radius: 6px; font-size: 14px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .export-btn:hover { background: #219653; }
        .filter-box { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; }
        .filter-group label { display: block; font-weight: 600; color: #4a5568; font-size: 12px; margin-bottom: 4px; text-transform: uppercase; }
        .filter-group select, .filter-group input { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; background-color: #fff; box-sizing: border-box; }
        .filter-btn { padding: 9px 16px; background: #3498db; border: none; color: white; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; font-size: 14px; transition: 0.2s; text-align: center; }
        .filter-btn:hover { background: #2980b9; }
        .clear-btn { background: #95a5a6; }
        .clear-btn:hover { background: #7f8c8d; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border-left: 5px solid #cbd5e1; }
        .card h3 { margin: 0 0 8px 0; color: #718096; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .card p { margin: 0; font-size: 24px; font-weight: 700; color: #1a202c; }
        .card-balance { border-left-color: #34495e; }
        .card-income { border-left-color: #2ecc71; }
        .card-expense { border-left-color: #e74c3c; }
        .income-val { color: #2ecc71 !important; }
        .expense-val { color: #e74c3c !important; }
        
        /* Budget Progress Styling */
        .budget-box { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 25px; }
        .progress-bar-bg { background: #edf2f7; border-radius: 10px; height: 12px; width: 100%; overflow: hidden; margin-top: 8px; }
        .progress-bar-fill { height: 100%; background: #2ecc71; transition: width 0.3s; }
        .progress-bar-fill.warning { background: #e67e22; }
        .progress-bar-fill.danger { background: #e74c3c; }
        
        .main-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 25px; }
        @media(max-width: 850px) { .main-layout { grid-template-columns: 1fr; } }
        
        .box { background: #fff; padding: 22px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); height: fit-content; }
        .box h2 { margin-top: 0; font-size: 18px; border-bottom: 2px solid #f4f7f6; padding-bottom: 12px; color: #1a202c; }
        .form-group { margin-bottom: 14px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; color: #4a5568; font-size: 13px; }
        input, select { width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; background: #fff; }
        .submit-btn { width: 100%; padding: 12px; background: #2ecc71; border: none; color: white; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: bold; transition: 0.2s; }
        .submit-btn:hover { background: #27ae60; }
        .alert-error { background: #fde8e8; color: #e74c3c; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid #edf2f7; }
        th { background: #f8fafc; color: #4a5568; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        td { font-size: 13px; }
        .type-badge { padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .badge-income { background: #e8f8f0; color: #2ecc71; }
        .badge-expense { background: #fde8e8; color: #e74c3c; }
        .cat-badge { background: #edf2f7; color: #4a5568; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .delete-btn { background: none; border: none; color: #cbd5e1; font-size: 18px; cursor: pointer; }
        .delete-btn:hover { color: #e74c3c; }
        .no-data { text-align: center; color: #a0aec0; padding: 30px 20px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>Welcome back, <strong><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></strong>!</h1>
            <div class="header-actions">
                <a href="export.php?<?= $export_params ?>" class="export-btn">📊 Export Filtered CSV</a>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>

        <!-- Filter Component Bar -->
        <div class="filter-box">
            <form method="GET" action="dashboard.php">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Month</label>
                        <select name="filter_month">
                            <option value="">All Months</option>
                            <?php foreach ($available_months as $m): ?>
                                <option value="<?= $m['m_val'] ?>" <?= $filter_month === $m['m_val'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['m_text']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Type</label>
                        <select name="filter_type">
                            <option value="">All Types</option>
                            <option value="income" <?= $filter_type === 'income' ? 'selected' : '' ?>>Income</option>
                            <option value="expense" <?= $filter_type === 'expense' ? 'selected' : '' ?>>Expense</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Category</label>
                        <select name="filter_category">
                            <option value="">All Categories</option>
                            <?php 
                            $all_cats = array_merge($categories['income'], $categories['expense']);
                            foreach ($all_cats as $cat): ?>
                                <option value="<?= $cat ?>" <?= $filter_category === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>From Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                    </div>

                    <div class="filter-group">
                        <label>To Date</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                    </div>

                    <button type="submit" class="filter-btn">Apply</button>
                    <a href="dashboard.php" class="filter-btn clear-btn">Reset</a>
                </div>
            </form>
        </div>

        <!-- Stats Overview Cards -->
        <div class="stats-grid">
            <div class="card card-balance">
                <h3>Net Balance</h3>
                <p>$<?= number_format($current_balance, 2) ?></p>
            </div>
            <div class="card card-income">
                <h3>Total Income</h3>
                <p class="income-val">+$<?= number_format($total_income, 2) ?></p>
            </div>
            <div class="card card-expense">
                <h3>Total Expense</h3>
                <p class="expense-val">-$<?= number_format($total_expense, 2) ?></p>
            </div>
        </div>

        <!-- Monthly Expense Budget Tracker -->
        <div class="budget-box">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>Monthly Expense Threshold Budget:</strong> 
                    $<?= number_format($total_expense, 2) ?> of $<?= number_format($monthly_budget, 2) ?> (<?= $budget_used_pct ?>%)
                </div>
                <form method="POST" action="dashboard.php" style="display: flex; gap: 8px;">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="number" step="10" name="monthly_budget" value="<?= $monthly_budget ?>" style="width: 110px; padding: 4px 8px;" required>
                    <button type="submit" name="set_budget" class="filter-btn" style="padding: 4px 10px; font-size: 12px;">Update</button>
                </form>
            </div>
            <div class="progress-bar-bg">
                <div class="progress-bar-fill <?= $budget_used_pct >= 90 ? 'danger' : ($budget_used_pct >= 75 ? 'warning' : '') ?>" style="width: <?= $budget_used_pct ?>%;"></div>
            </div>
        </div>

        <!-- Main Workspace -->
        <div class="main-layout">
            <!-- Add Transaction Form -->
            <div class="box">
                <h2>Add Record</h2>
                <?php if ($form_error): ?>
                    <div class="alert-error"><?= htmlspecialchars($form_error) ?></div>
                <?php endif; ?>
                <form method="POST" action="dashboard.php">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="form-group">
                        <label for="title">Title / Description</label>
                        <input type="text" name="title" id="title" required placeholder="e.g., Grocery Shopping">
                    </div>
                    
                    <div class="form-group">
                        <label for="amount">Amount ($)</label>
                        <input type="number" step="0.01" name="amount" id="amount" required placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label for="type">Type</label>
                        <select name="type" id="type" onchange="updateCategories(this.value)" required>
                            <option value="expense">Expense</option>
                            <option value="income">Income</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="category">Category</label>
                        <select name="category" id="category" required>
                            <!-- Dynamically populated via JS below -->
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="date">Transaction Date</label>
                        <input type="date" name="date" id="date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <button type="submit" name="add_transaction" class="submit-btn">Save Entry</button>
                </form>
            </div>

            <!-- Ledger Table -->
            <div class="box">
                <h2>Transaction Ledger (<?= count($transactions) ?> Entries)</h2>
                <?php if (empty($transactions)): ?>
                    <div class="no-data">No transactions match your current search criteria.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><?= htmlspecialchars($t['date']) ?></td>
                                    <td><strong><?= htmlspecialchars($t['title']) ?></strong></td>
                                    <td><span class="cat-badge"><?= htmlspecialchars($t['category'] ?? 'General') ?></span></td>
                                    <td>
                                        <span class="type-badge <?= $t['type'] === 'income' ? 'badge-income' : 'badge-expense' ?>">
                                            <?= htmlspecialchars($t['type']) ?>
                                        </span>
                                    </td>
                                    <td class="<?= $t['type'] === 'income' ? 'income-val' : 'expense-val' ?>">
                                        <?= $t['type'] === 'income' ? '+' : '-' ?>$<?= number_format($t['amount'], 2) ?>
                                    </td>
                                    <td>
                                        <form method="POST" action="dashboard.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this item?');">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="delete_id" value="<?= $t['id'] ?>">
                                            <button type="submit" class="delete-btn" title="Delete">&times;</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        const categories = <?= json_encode($categories) ?>;
        
        function updateCategories(type) {
            const catSelect = document.getElementById('category');
            catSelect.innerHTML = '';
            
            const options = categories[type] || [];
            options.forEach(cat => {
                const opt = document.createElement('option');
                opt.value = cat;
                opt.textContent = cat;
                catSelect.appendChild(opt);
            });
        }

        // Initialize form category choices based on default type ('expense')
        updateCategories('expense');
    </script>
</body>
</html>