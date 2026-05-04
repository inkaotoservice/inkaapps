<?php
require_once 'includes/config.php';

try {
    $stmt = $pdo->prepare("UPDATE bookings SET status = 'pending' WHERE status = 'awaiting_dp'");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "Successfully updated $count bookings from 'awaiting_dp' to 'pending'.\n";
} catch (Exception $e) {
    echo "Error updating bookings: " . $e->getMessage() . "\n";
}
?>
