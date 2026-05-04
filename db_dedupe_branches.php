<?php
require 'includes/config.php';

echo "Checking branches...\n";
$stmt = $pdo->query("SELECT * FROM branches ORDER BY name, created_at ASC");
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$unique_branches = [];
$duplicates = [];

foreach ($branches as $b) {
    $name = strtolower(trim($b['name']));
    if (!isset($unique_branches[$name])) {
        $unique_branches[$name] = $b['id'];
        echo "Keeping: " . $b['name'] . " (ID: " . $b['id'] . ")\n";
    } else {
        $duplicates[] = [
            'keep_id' => $unique_branches[$name],
            'delete_id' => $b['id'],
            'name' => $b['name']
        ];
    }
}

if (empty($duplicates)) {
    echo "No duplicates found.\n";
} else {
    foreach ($duplicates as $dup) {
        $keep = $dup['keep_id'];
        $del = $dup['delete_id'];
        echo "Merging duplicate '" . $dup['name'] . "' -> updating records to $keep, deleting $del\n";
        
        try {
            $pdo->beginTransaction();
            
            // Update references
            $pdo->prepare("UPDATE profiles SET branch_id = ? WHERE branch_id = ?")->execute([$keep, $del]);
            $pdo->prepare("UPDATE catalog SET branch_id = ? WHERE branch_id = ?")->execute([$keep, $del]);
            $pdo->prepare("UPDATE bookings SET branch_id = ? WHERE branch_id = ?")->execute([$keep, $del]);
            $pdo->prepare("UPDATE transactions SET branch_id = ? WHERE branch_id = ?")->execute([$keep, $del]);
            $pdo->prepare("UPDATE expenses SET branch_id = ? WHERE branch_id = ?")->execute([$keep, $del]);
            
            // Delete duplicate branch
            $pdo->prepare("DELETE FROM branches WHERE id = ?")->execute([$del]);
            
            $pdo->commit();
            echo "Successfully merged and deleted duplicate.\n";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}
