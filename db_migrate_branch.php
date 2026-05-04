<?php
require_once 'includes/config.php';

$columns = [
    "logo_url VARCHAR(255) DEFAULT NULL",
    "invoice_notes TEXT DEFAULT NULL",
    "whatsapp_number VARCHAR(50) DEFAULT NULL"
];

foreach ($columns as $colDef) {
    preg_match('/^([a-zA-Z_]+)/', $colDef, $matches);
    $colName = $matches[1] ?? 'unknown';
    
    try {
        $pdo->exec("ALTER TABLE branches ADD COLUMN $colDef");
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
