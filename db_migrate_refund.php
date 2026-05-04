<?php
require_once 'includes/config.php';

$columns = [
    "cancellation_reason TEXT DEFAULT NULL",
    "is_dp_paid TINYINT(1) DEFAULT 0",
    "dp_amount BIGINT DEFAULT 0",
    "refund_status ENUM('pending', 'completed') DEFAULT 'pending'",
    "refund_processed_by VARCHAR(36) DEFAULT NULL",
    "refund_processed_at DATETIME DEFAULT NULL"
];

foreach ($columns as $colDef) {
    preg_match('/^([a-zA-Z_]+)/', $colDef, $matches);
    $colName = $matches[1] ?? 'unknown';
    
    try {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN $colDef");
        echo "Column $colName added successfully.\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21') { 
            echo "Column $colName already exists.\n";
        } else {
            echo "Error adding $colName: " . $e->getMessage() . "\n";
        }
    }
}
echo "Migration finished.\n";
