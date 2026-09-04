<?php
require 'db.php';
session_start();

// Make sure the user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$user_id = $_SESSION['user_id'];

// SQL query to pull expenses grouped by title name
$stmt = $pdo->prepare("SELECT title, SUM(amount) as total FROM transactions WHERE user_id = ? AND type = 'expense' GROUP BY title");
$stmt->execute([$user_id]);
$data = $stmt->fetchAll();

// Send the data out as JSON format so JavaScript can read it
header('Content-Type: application/json');
echo json_encode($data);
exit;
