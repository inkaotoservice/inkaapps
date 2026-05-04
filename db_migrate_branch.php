<?php
require_once 'includes/config.php';

echo "<pre>\n";

// ── STEP 1: Add missing columns to branches ──────────────────────
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
echo "\n";

// ── STEP 2: Deduplicate branches ──────────────────────────────────
echo "=== DEDUPLICATING BRANCHES ===\n";
$stmt = $pdo->query("SELECT id, name, created_at FROM branches ORDER BY name, created_at ASC");
$all_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total branch rows: " . count($all_branches) . "\n";
foreach ($all_branches as $b) {
    echo "  [" . $b['id'] . "] " . $b['name'] . "\n";
}
echo "\n";

$seen_names = [];
$duplicates = [];
foreach ($all_branches as $b) {
    $key = strtolower(trim($b['name']));
    if (!isset($seen_names[$key])) {
        $seen_names[$key] = $b['id'];
    } else {
        $duplicates[] = ['keep' => $seen_names[$key], 'del' => $b['id'], 'name' => $b['name']];
    }
}

if (empty($duplicates)) {
    echo "✓ No duplicates found.\n\n";
} else {
    echo "Found " . count($duplicates) . " duplicate(s):\n";
    $related = ['profiles', 'catalog', 'bookings', 'transactions', 'expenses'];
    foreach ($duplicates as $dup) {
        echo "  Fixing '{$dup['name']}' (keep: {$dup['keep']}, delete: {$dup['del']})\n";
        try {
            $pdo->beginTransaction();
            foreach ($related as $tbl) {
                $chk = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'branch_id'")->fetch();
                if ($chk) {
                    $st = $pdo->prepare("UPDATE `$tbl` SET branch_id=? WHERE branch_id=?");
                    $st->execute([$dup['keep'], $dup['del']]);
                    if ($st->rowCount()) echo "    - Updated {$st->rowCount()} rows in $tbl\n";
                }
            }
            $pdo->prepare("DELETE FROM branches WHERE id=?")->execute([$dup['del']]);
            $pdo->commit();
            echo "  ✓ Duplicate removed.\n";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "  ✗ Error: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}

// ── STEP 3: Add UNIQUE constraint ────────────────────────────────
echo "=== ADDING UNIQUE CONSTRAINT ===\n";
try {
    $idx = $pdo->query("SHOW INDEX FROM branches WHERE Key_name = 'unique_branch_name'")->fetch();
    if ($idx) {
        echo "✓ UNIQUE constraint already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE branches ADD UNIQUE INDEX unique_branch_name (name(100))");
        echo "✓ UNIQUE constraint added. No more duplicates possible.\n";
    }
} catch (Exception $e) {
    echo "✗ Failed to add UNIQUE constraint: " . $e->getMessage() . "\n";
}

// ── STEP 4: Final state ───────────────────────────────────────────
echo "\n=== FINAL STATE ===\n";
$final = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
echo "Total branches: " . count($final) . "\n";
foreach ($final as $f) {
    echo "  ✓ [" . $f['id'] . "] " . $f['name'] . "\n";
}

echo "\nMigration finished successfully!\n";
echo "</pre>\n";
?>
