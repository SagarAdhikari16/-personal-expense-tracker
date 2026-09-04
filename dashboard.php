<?php
require 'db.php';
session_start();

// Strict session check: Boot unauthorized guests out to the log in panel
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// 1. Process Form Submissions (Adding items)
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

// 3. Fetch financial calculations explicitly for this logged-in account
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY date DESC, id DESC");
$stmt->execute([$user_id]);
$transactions = $stmt->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Personal Expense Tracker</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 24px; color: #333; }
        .logout-btn { color: #e74c3c; text-decoration: none; font-weight: bold; border: 1px solid #e74c3c; padding: 6px 12px; border-radius: 4px; }
        .logout-btn:hover { background: #e74c3c; color: white; }
        
        /* Stats Section */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); text-align: center; }
        .card h3 { margin: 0 0 10px 0; color: #777; font-size: 14px; text-transform: uppercase; }
        .card p { margin: 0; font-size: 24px; font-weight: bold; }
        .balance { color: #2c3e50; }
        .income-val { color: #2ecc71; }
        .expense-val { color: #e74c3c; }

        /* Main Workspace Split layout */
        .main-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        .box { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); height: fit-content; }
        .box h2 { margin-top: 0; font-size: 18px; border-bottom: 2px solid #f4f7f6; padding-bottom: 10px; color: #333; }
        
        /* Form Styling */
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: #666; font-size: 14px; }
        input, select { width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; }
        .submit-btn { width: 100%; padding: 10px; background: #2ecc71; border: none; color: white; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .submit-btn:hover { background: #27ae60; }

        /* Custom error alert directly inside the client */
        .alert-error { background: #fde8e8; color: #e74c3c; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; display: none; }

        /* Table Architecture */
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #edf2f7; }
        th { background: #f8fafc; color: #4a5568; font-size: 14px; }
        .type-badge { padding: 3px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .badge-income { background: #e8f8f0; color: #2ecc71; }
        .badge-expense { background: #fde8e8; color: #e74c3c; }
        .delete-link { color: #cbd5e1; text-decoration: none; font-size: 18px; }
        .delete-link:hover { color: #e74c3c; }
        .no-data { text-align: center; color: #aaa; padding: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>

        <!-- Metrics Display -->
        <div class="stats-grid">
            <div class="card">
                <h3>Total Balance</h3>
                <p class="balance">Rs. <?= number_format($current_balance, 2) ?></p>
            </div>
            <div class="card">
                <h3>Total Income</h3>
                <p class="income-val">+ Rs. <?= number_format($total_income, 2) ?></p>
            </div>
            <div class="card">
                <h3>Total Expenses</h3>
                <p class="expense-val">- Rs. <?= number_format($total_expense, 2) ?></p>
            </div>
        </div>

        <div class="main-layout">
            <!-- Transaction Input Form Column -->
            <div class="box">
                <h2>Add Transaction</h2>
                <div class="alert-error" id="jsErrorBlock"></div>
                <form method="POST" id="transactionForm">
                    <div class="form-group">
                        <label>Description / Title</label>
                        <input type="text" name="title" id="txTitle" placeholder="e.g., Internet bill, Salary">
                    </div>
                    <div class="form-group">
                        <label>Amount (Rs.)</label>
                        <input type="number" step="0.01" name="amount" id="txAmount" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" id="txType">
                            <option value="expense">Expense</option>
                            <option value="income">Income</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="date" id="txDate" value="<?= date('Y-m-d') ?>">
                    </div>
                    <button type="submit" name="add_transaction" class="submit-btn">Save Entry</button>
                </form>
            </div>

            <!-- Ledger View History Column -->
            <div class="box">
                <h2>History Ledger</h2>
                <?php if (empty($transactions)): ?>
                    <div class="no-data">No transactions added yet.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Details</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><?= htmlspecialchars($t['title']) ?></td>
                                    <td class="<?= $t['type'] === 'income' ? 'income-val' : 'expense-val' ?>">
                                        Rs. <?= number_format($t['amount'], 2) ?>
                                    </td>
                                    <td>
                                        <span class="type-badge badge-<?= $t['type'] ?>">
                                            <?= $t['type'] ?>
                                        </span>
                                    </td>
                                    <td><?= date('Y-m-d', strtotime($t['date'])) ?></td>
                                    <td>
                                        <a href="dashboard.php?delete=<?= $t['id'] ?>" class="delete-link" onclick="return confirm('Are you sure you want to remove this record?');">&times;</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Client side dynamic validations component -->
    <script>
        document.getElementById('transactionForm').addEventListener('submit', function(e) {
            const title = document.getElementById('txTitle').value.trim();
            const amount = document.getElementById('txAmount').value.trim();
            const date = document.getElementById('txDate').value.trim();
            const errorBlock = document.getElementById('jsErrorBlock');
            
            let messages = [];

            if (!title) {
                messages.push("Please provide a valid transaction title.");
            }
            if (!amount || parseFloat(amount) <= 0) {
                messages.push("Amount must be a positive number greater than 0.");
            }
            if (!date) {
