<?php
require_once 'includes/config.php';

function addColumnIfNotExists($pdo, $table, $column, $definition) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "Added column $column to $table.<br>";
        } else {
            echo "Column $column already exists in $table.<br>";
        }
    } catch (Exception $e) {
        echo "Error adding $column to $table: " . $e->getMessage() . "<br>";
    }
}

try {
    addColumnIfNotExists($pdo, 'bookings', 'is_dp_paid', "TINYINT(1) DEFAULT 0");
    addColumnIfNotExists($pdo, 'bookings', 'dp_amount', "INT DEFAULT 0");
    addColumnIfNotExists($pdo, 'transactions', 'dp_amount', "INT DEFAULT 0");
    
    echo "Database migration v3 completed.";
} catch (Exception $e) {
    echo "Migration v3 failed: " . $e->getMessage();
}
?>
