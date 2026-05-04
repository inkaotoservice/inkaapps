<?php
require_once 'includes/config.php';

echo "<pre>\n";
echo "=== FIX DUPLICATE BRANCHES (PRODUCTION SAFE) ===\n\n";

// STEP 1: Show all current branches
echo "--- Current branches in DB ---\n";
$stmt = $pdo->query("SELECT id, name, created_at FROM branches ORDER BY name, created_at ASC");
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total rows: " . count($all) . "\n";
foreach ($all as $b) {
    echo "  [" . $b['id'] . "] " . $b['name'] . " (created: " . ($b['created_at'] ?? 'unknown') . ")\n";
}
echo "\n";

// STEP 2: Deduplicate (keep oldest per name)
$seen = [];
$to_delete = [];
foreach ($all as $b) {
    $key = strtolower(trim($b['name']));
    if (!isset($seen[$key])) {
        $seen[$key] = $b['id'];
    } else {
        $to_delete[] = ['del' => $b['id'], 'keep' => $seen[$key], 'name' => $b['name']];
    }
}

if (empty($to_delete)) {
    echo "✓ No duplicates found. Database is clean.\n\n";
} else {
    echo "Found " . count($to_delete) . " duplicate(s) to remove:\n";
    $related_tables = ['profiles', 'catalog', 'bookings', 'transactions', 'expenses'];
    
    foreach ($to_delete as $dup) {
        echo "  Merging '{$dup['name']}': delete [{$dup['del']}] → keep [{$dup['keep']}]\n";
        try {
            $pdo->beginTransaction();
            foreach ($related_tables as $tbl) {
                $chk = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'branch_id'")->fetch();
                if ($chk) {
                    $st = $pdo->prepare("UPDATE `$tbl` SET branch_id=? WHERE branch_id=?");
                    $st->execute([$dup['keep'], $dup['del']]);
                    if ($st->rowCount()) echo "    updated {$st->rowCount()} rows in $tbl\n";
                }
            }
            $pdo->prepare("DELETE FROM branches WHERE id=?")->execute([$dup['del']]);
            $pdo->commit();
            echo "  ✓ Done\n";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "  ✗ Error: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}

// STEP 3: Add UNIQUE constraint on name
echo "--- Adding UNIQUE constraint on branches.name ---\n";
try {
    $idx = $pdo->query("SHOW INDEX FROM branches WHERE Key_name = 'unique_branch_name'")->fetch();
    if ($idx) {
        echo "✓ UNIQUE index 'unique_branch_name' already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE branches ADD UNIQUE INDEX unique_branch_name (name(100))");
        echo "✓ UNIQUE index added successfully.\n";
    }
} catch (Exception $e) {
    echo "✗ Could not add UNIQUE index: " . $e->getMessage() . "\n";
}

// STEP 4: Show final state
echo "\n--- Final state ---\n";
$final = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
echo "Total branches: " . count($final) . "\n";
foreach ($final as $f) {
    echo "  ✓ [" . $f['id'] . "] " . $f['name'] . "\n";
}

echo "\n=== DONE ===\n";
echo "</pre>\n";
echo "<p style='font-family:sans-serif;color:green;'><strong>✓ Script completed. You can delete this file after running.</strong></p>";
?>
