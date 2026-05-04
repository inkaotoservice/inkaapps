<?php
require_once 'includes/config.php';
try {
    $pdo->exec("ALTER TABLE branches ADD COLUMN logo_url VARCHAR(255) DEFAULT NULL, ADD COLUMN invoice_notes TEXT DEFAULT NULL;");
    echo "Success altering branches.";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Columns already exist.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
$dir = __DIR__ . '/assets/uploads/logos';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
echo " Directory ready.";
?>
