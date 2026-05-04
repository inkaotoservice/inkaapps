<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

echo "<h3>All Profiles Check:</h3>";
$stmt = $pdo->query("SELECT * FROM profiles");
echo "<pre>"; print_r($stmt->fetchAll()); echo "</pre>";
?>
