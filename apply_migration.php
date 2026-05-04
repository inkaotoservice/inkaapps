<?php
require_once 'includes/config.php';

try {
    // Check if column exists first
    $stmt = $pdo->query("SHOW COLUMNS FROM catalog LIKE 'branch_id'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $pdo->exec("ALTER TABLE catalog ADD COLUMN branch_id VARCHAR(36) NULL AFTER is_active");
        $pdo->exec("ALTER TABLE catalog ADD CONSTRAINT fk_catalog_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL");
        echo "SUCCESS: Column branch_id added to catalog.";
    } else {
        echo "SUCCESS: Column branch_id already exists.";
    }
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}
