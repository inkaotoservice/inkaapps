<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SHOW COLUMNS FROM branches");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
