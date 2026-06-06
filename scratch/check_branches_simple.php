<?php
$_SERVER['SERVER_NAME'] = 'localhost';
require 'includes/config.php';
$stmt = $pdo->query('SELECT * FROM branches');
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
