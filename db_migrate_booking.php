<?php
require_once 'includes/config.php';

try {
    // 1. Alter status enum
    $pdo->exec("ALTER TABLE bookings MODIFY COLUMN status ENUM('awaiting_dp','pending','processing','completed','cancelled') DEFAULT 'pending'");
    echo "Status ENUM altered.\n";

    // 2. Add dp_receipt
    try {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN dp_receipt VARCHAR(255) DEFAULT NULL");
        echo "dp_receipt column added.\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21') { // Column already exists
            echo "dp_receipt column already exists.\n";
        } else {
            throw $e;
        }
    }

    // 3. Add is_online
    try {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN is_online TINYINT(1) DEFAULT 0");
        echo "is_online column added.\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21') {
            echo "is_online column already exists.\n";
        } else {
            throw $e;
        }
    }

    // 4. Add settings
    $pdo->exec("INSERT IGNORE INTO app_settings (`key`, `value`) VALUES ('booking_dp', '50000')");
    echo "booking_dp setting inserted.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
