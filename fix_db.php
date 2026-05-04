<?php
require_once 'includes/config.php';
try {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN dp_amount INT DEFAULT 0");
    echo "Success: dp_amount added to transactions.";
} catch (Exception $e) {
    echo "Error or Already Exists: " . $e->getMessage();
}
try {
    $pdo->exec("ALTER TABLE bookings ADD COLUMN is_dp_paid TINYINT(1) DEFAULT 0");
    echo "Success: is_dp_paid added to bookings.";
} catch (Exception $e) {
    echo "Error or Already Exists: " . $e->getMessage();
}
try {
    $pdo->exec("ALTER TABLE bookings ADD COLUMN dp_amount INT DEFAULT 0");
    echo "Success: dp_amount added to bookings.";
} catch (Exception $e) {
    echo "Error or Already Exists: " . $e->getMessage();
}
?>
