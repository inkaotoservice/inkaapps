<?php
require_once 'includes/config.php';

try {
    // Check if column exists first
    $stmt = $pdo->query("SHOW COLUMNS FROM branches LIKE 'whatsapp_number'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN whatsapp_number VARCHAR(20)");
        echo "Success: Added whatsapp_number to branches table.<br>";
    } else {
        echo "Column whatsapp_number already exists.<br>";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
