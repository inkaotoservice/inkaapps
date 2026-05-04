<?php
require_once 'includes/config.php';

echo "<pre>\n";
echo "=== FIX DUPLICATE BRANCHES ===\n\n";

// Step 1: Tampilkan semua branches saat ini
$stmt = $pdo->query("SELECT id, name, created_at FROM branches ORDER BY name, created_at ASC");
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total branches di database: " . count($branches) . "\n";
foreach ($branches as $b) {
    echo "  ID: " . $b['id'] . " | Name: " . $b['name'] . " | Created: " . $b['created_at'] . "\n";
}
echo "\n";

// Step 2: Identifikasi duplikat
$unique_branches = [];
$duplicates = [];

foreach ($branches as $b) {
    $name = strtolower(trim($b['name']));
    if (!isset($unique_branches[$name])) {
        $unique_branches[$name] = $b['id'];
    } else {
        $duplicates[] = [
            'keep_id'   => $unique_branches[$name],
            'delete_id' => $b['id'],
            'name'      => $b['name']
        ];
    }
}

if (empty($duplicates)) {
    echo "✓ Tidak ada duplikat ditemukan.\n";
} else {
    echo "Ditemukan " . count($duplicates) . " duplikat. Memproses...\n\n";

    $tables_with_branch_id = ['profiles', 'catalog', 'bookings', 'transactions', 'expenses'];

    foreach ($duplicates as $dup) {
        $keep = $dup['keep_id'];
        $del  = $dup['delete_id'];
        echo "Merge '". $dup['name'] ."': keep ID=$keep, hapus ID=$del\n";

        try {
            $pdo->beginTransaction();

            foreach ($tables_with_branch_id as $table) {
                // Cek apakah tabel punya kolom branch_id
                $check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'branch_id'")->fetch();
                if ($check) {
                    $affected = $pdo->prepare("UPDATE `$table` SET branch_id = ? WHERE branch_id = ?");
                    $affected->execute([$keep, $del]);
                    $count = $affected->rowCount();
                    if ($count > 0) echo "  - Updated $count rows in $table\n";
                }
            }

            // Hapus duplikat
            $pdo->prepare("DELETE FROM branches WHERE id = ?")->execute([$del]);
            $pdo->commit();
            echo "  ✓ Berhasil hapus duplikat ID=$del\n\n";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "  ✗ Error: " . $e->getMessage() . "\n\n";
        }
    }
}

// Step 3: Tambah UNIQUE constraint supaya tidak duplikat lagi
echo "=== MENAMBAHKAN UNIQUE CONSTRAINT ===\n";
try {
    // Cek apakah unique index sudah ada
    $idx = $pdo->query("SHOW INDEX FROM branches WHERE Key_name = 'unique_branch_name'")->fetch();
    if ($idx) {
        echo "✓ UNIQUE constraint 'unique_branch_name' sudah ada.\n";
    } else {
        $pdo->exec("ALTER TABLE branches ADD UNIQUE INDEX unique_branch_name (name)");
        echo "✓ UNIQUE constraint berhasil ditambahkan ke kolom 'name'.\n";
    }
} catch (Exception $e) {
    echo "✗ Gagal tambah constraint: " . $e->getMessage() . "\n";
    echo "  (Kemungkinan masih ada duplikat yang belum terhapus)\n";
}

// Step 4: Tampilkan hasil akhir
echo "\n=== HASIL AKHIR ===\n";
$stmt = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC");
$final = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total branches sekarang: " . count($final) . "\n";
foreach ($final as $b) {
    echo "  ID: " . $b['id'] . " | " . $b['name'] . "\n";
}
echo "\nSelesai!\n</pre>";
?>
