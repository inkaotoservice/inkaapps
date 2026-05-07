<?php
/**
 * INKA OTOSERVICE - DATABASE REPAIR SCRIPT
 * ───────────────────────────────────────
 * Script ini memastikan semua kolom yang dibutuhkan ada di database.
 * Jalankan via browser: http://app.inkaotoservice.id/db_repair.php
 */

require_once 'includes/config.php';

echo "<!DOCTYPE html><html lang='id'><head><meta charset='UTF-8'><title>DB Repair</title>";
echo "<style>body{font-family:sans-serif;max-width:800px;margin:40px auto;padding:20px;background:#f8fafc;color:#1e293b}";
echo ".card{background:white;padding:24px;border-radius:16px;box-shadow:0 4px 6px -1px rgb(0 0 0 / 0.1)}";
echo "h2{margin-top:0;color:#0f172a}.ok{color:#059669}.err{color:#dc2626}.info{color:#2563eb}ul{padding-left:20px}li{margin:8px 0}</style></head><body>";
echo "<div class='card'><h2>🛠 Database Repair</h2><ul>";

function add_column($pdo, $table, $colDef) {
    preg_match('/^([a-zA-Z_0-9]+)/', $colDef, $matches);
    $colName = $matches[1] ?? 'unknown';
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN $colDef");
        echo "<li><span class='ok'>✅</span> Kolom <strong>$colName</strong> berhasil ditambahkan ke tabel <strong>$table</strong>.</li>";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21') { 
            echo "<li><span class='info'>ℹ️</span> Kolom <strong>$colName</strong> sudah ada di tabel <strong>$table</strong>.</li>";
        } else {
            echo "<li><span class='err'>❌</span> Gagal menambahkan <strong>$colName</strong> ke <strong>$table</strong>: " . $e->getMessage() . "</li>";
        }
    }
}

// 1. Repair BOOKINGS
$booking_cols = [
    "cancellation_reason TEXT DEFAULT NULL",
    "is_dp_paid TINYINT(1) DEFAULT 0",
    "dp_amount BIGINT DEFAULT 0",
    "refund_status ENUM('pending', 'completed') DEFAULT 'pending'",
    "refund_processed_by VARCHAR(36) DEFAULT NULL",
    "refund_processed_at DATETIME DEFAULT NULL"
];
foreach ($booking_cols as $col) add_column($pdo, 'bookings', $col);

// 2. Repair BRANCHES
$branch_cols = [
    "logo_url VARCHAR(255) DEFAULT NULL",
    "invoice_notes TEXT DEFAULT NULL",
    "whatsapp_number VARCHAR(50) DEFAULT NULL"
];
foreach ($branch_cols as $col) add_column($pdo, 'branches', $col);

// 3. Ensure Profiles ENUM is updated
try {
    $pdo->exec("ALTER TABLE profiles MODIFY COLUMN role ENUM('owner','manager_ops','admin','admin_depok','admin_bsd','spv','mekanik','member') DEFAULT 'admin'");
    echo "<li><span class='ok'>✅</span> ENUM Role pada tabel <strong>profiles</strong> berhasil diperbarui.</li>";
} catch (Exception $e) {
    echo "<li><span class='err'>❌</span> Gagal update ENUM Role: " . $e->getMessage() . "</li>";
}

echo "</ul><p style='margin-top:20px; font-weight:bold'>Selesai! Silakan coba akses kembali Dashboard Anda.</p>";
echo "<a href='index.php' style='display:inline-block;background:#2563eb;color:white;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:bold'>Ke Dashboard</a>";
echo "</div></body></html>";
