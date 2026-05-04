<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

echo "<h3>Session Data:</h3>";
echo "<pre>"; print_r($_SESSION); echo "</pre>";

echo "<h3>Branches in DB:</h3>";
$stmt = $pdo->query("SELECT id, name FROM branches");
echo "<pre>"; print_r($stmt->fetchAll()); echo "</pre>";

echo "<h3>Recent Bookings:</h3>";
$stmt = $pdo->query("SELECT id, customer_name, branch_id, status, service_date FROM bookings ORDER BY created_at DESC LIMIT 5");
echo "<pre>"; print_r($stmt->fetchAll()); echo "</pre>";
?>
