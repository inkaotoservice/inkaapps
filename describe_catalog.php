<?php
require_once 'includes/config.php';
$stmt = $pdo->query("DESCRIBE catalog");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
