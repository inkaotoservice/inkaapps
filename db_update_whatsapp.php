<?php
require_once 'includes/config.php';

echo "<pre>\n";

// Step 1: Add whatsapp_number column if missing
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM branches LIKE 'whatsapp_number'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN whatsapp_number VARCHAR(50)");
        echo "✓ Added whatsapp_number to branches table.\n";
    } else {
        echo "✓ Column whatsapp_number already exists.\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

// Step 2: Add UNIQUE constraint on name to prevent duplicate branches
echo "\n--- Adding UNIQUE constraint on branches.name ---\n";
try {
    $idx = $pdo->query("SHOW INDEX FROM branches WHERE Key_name = 'unique_branch_name'")->fetch();
    if ($idx) {
        echo "✓ UNIQUE constraint 'unique_branch_name' already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE branches ADD UNIQUE INDEX unique_branch_name (name(100))");
        echo "✓ UNIQUE constraint added. Duplicate branches are now impossible.\n";
    }
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

// Step 3: Show current branches
echo "\n--- Current branches ---\n";
$rows = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  [" . $r['id'] . "] " . $r['name'] . "\n";
}

echo "\nDone!\n</pre>";
?>
