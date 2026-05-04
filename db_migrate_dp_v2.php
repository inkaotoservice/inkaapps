<?php
require_once 'includes/config.php';

try {
    // 1. Add columns to bookings
    $pdo->exec("ALTER TABLE bookings ADD COLUMN is_dp_paid TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE bookings ADD COLUMN dp_amount INT DEFAULT 0");
    
    // 2. Add column to transactions
    $pdo->exec("ALTER TABLE transactions ADD COLUMN dp_amount INT DEFAULT 0");
    
    echo "Database migration successful.";
} catch (Exception $e) {
    echo "Migration failed or already applied: " . $e->getMessage();
}
?>
