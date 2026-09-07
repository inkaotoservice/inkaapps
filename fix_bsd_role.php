<?php
/**
 * Script untuk memperbaiki role user Admin Cabang BSD (bsd@inka.com)
 * Jalankan sekali di production, lalu hapus file ini.
 */
require_once __DIR__ . '/includes/config.php';

echo "<h2>Fix Role Admin BSD</h2>";
echo "<pre>";

// 1. Cek data user saat ini
$stmt = $pdo->prepare("
    SELECT u.id, u.email, p.full_name, p.role, p.branch_id
    FROM users u
    JOIN profiles p ON u.id = p.id
    WHERE u.email = ?
");
$stmt->execute(['bsd@inka.com']);
$user = $stmt->fetch();

if (!$user) {
    echo "ERROR: User bsd@inka.com tidak ditemukan!\n";
    exit;
}

echo "=== DATA SAAT INI ===\n";
echo "ID       : " . $user['id'] . "\n";
echo "Email    : " . $user['email'] . "\n";
echo "Nama     : " . $user['full_name'] . "\n";
echo "Role     : " . $user['role'] . "\n";
echo "Branch ID: " . ($user['branch_id'] ?? 'NULL') . "\n\n";

// 2. Cek branch BSD
$stmt2 = $pdo->query("SELECT id, name FROM branches WHERE name LIKE '%BSD%' OR name LIKE '%bsd%' LIMIT 5");
$branches = $stmt2->fetchAll();
echo "=== BRANCHES (BSD) ===\n";
foreach ($branches as $b) {
    echo "  ID: " . $b['id'] . " | Name: " . $b['name'] . "\n";
}
if (empty($branches)) {
    echo "  Tidak ditemukan branch BSD\n";
    // Cek semua branches
    $stmt3 = $pdo->query("SELECT id, name FROM branches ORDER BY name");
    $all_branches = $stmt3->fetchAll();
    echo "\n=== SEMUA BRANCHES ===\n";
    foreach ($all_branches as $b) {
        echo "  ID: " . $b['id'] . " | Name: " . $b['name'] . "\n";
    }
}

// 3. Fix role jika masih salah
if ($user['role'] !== 'admin_bsd') {
    // Cari branch BSD untuk branch_id
    $bsd_branch_id = null;
    if (!empty($branches)) {
        $bsd_branch_id = $branches[0]['id'];
    }

    $update = $pdo->prepare("UPDATE profiles SET role = 'admin_bsd', branch_id = COALESCE(?, branch_id) WHERE id = ?");
    $update->execute([$bsd_branch_id, $user['id']]);

    echo "\n✅ BERHASIL! Role diubah dari '{$user['role']}' menjadi 'admin_bsd'\n";
    if ($bsd_branch_id) {
        echo "   Branch ID diset ke: $bsd_branch_id\n";
    }

    // Verifikasi
    $stmt->execute(['bsd@inka.com']);
    $updated = $stmt->fetch();
    echo "\n=== DATA SETELAH UPDATE ===\n";
    echo "Nama     : " . $updated['full_name'] . "\n";
    echo "Role     : " . $updated['role'] . "\n";
    echo "Branch ID: " . ($updated['branch_id'] ?? 'NULL') . "\n";
} else {
    echo "\n✅ Role sudah benar: admin_bsd\n";
}

echo "\n⚠️ HAPUS FILE INI SETELAH SELESAI!\n";
echo "</pre>";
