<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT DISTINCT category FROM catalog");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
