<?php
$_SERVER['SERVER_NAME'] = 'localhost';
require 'includes/config.php';
try {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN discount INT DEFAULT 0 AFTER total_amount");
    echo "Column discount added.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false || strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), 'SQLSTATE[42S21]') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
