<?php
/**
 * MIGRATION: Buat/Update Akun Supervisor Per Cabang
 * ─────────────────────────────────────────────────
 * Script ini akan:
 * 1. Menampilkan cabang yang ada di database
 * 2. Membuat akun SPV BSD dan SPV Depok jika belum ada
 * 3. Update SPV yang sudah ada untuk assign ke cabang masing-masing
 *
 * Jalankan SEKALI SAJA, lalu hapus file ini.
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

$results = [];
$errors  = [];

// ── 1. CEK CABANG YANG ADA ────────────────────────────────────────
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();

echo "<!DOCTYPE html><html><head>";
echo "<title>Migration SPV Cabang</title>";
echo "<style>body{font-family:sans-serif;max-width:800px;margin:40px auto;padding:20px}";
echo ".ok{color:green;background:#f0fff4;border:1px solid #86efac;padding:10px;border-radius:8px;margin:8px 0}";
echo ".err{color:red;background:#fff0f0;border:1px solid #fca5a5;padding:10px;border-radius:8px;margin:8px 0}";
echo ".info{color:#1e40af;background:#eff6ff;border:1px solid #93c5fd;padding:10px;border-radius:8px;margin:8px 0}";
echo "table{width:100%;border-collapse:collapse;margin:16px 0}th,td{border:1px solid #e2e8f0;padding:10px;text-align:left}th{background:#f8fafc}";
echo "</style></head><body>";

echo "<h2>🔧 Migration: Supervisor Per Cabang</h2>";

// Tampilkan daftar cabang
echo "<h3>📍 Cabang yang Ditemukan:</h3>";
echo "<table><tr><th>No</th><th>ID</th><th>Nama Cabang</th></tr>";
foreach ($branches as $i => $b) {
    echo "<tr><td>" . ($i+1) . "</td><td><code style='font-size:11px'>{$b['id']}</code></td><td><strong>{$b['name']}</strong></td></tr>";
}
echo "</table>";

if (empty($branches)) {
    echo "<div class='err'>❌ Tidak ada cabang ditemukan. Pastikan tabel branches sudah diisi.</div>";
    echo "</body></html>";
    exit;
}

// ── 2. TAMPILKAN SPV YANG SUDAH ADA ──────────────────────────────
$existing_spv = $pdo->query("
    SELECT u.id, u.email, p.full_name, p.branch_id, b.name as branch_name
    FROM users u
    JOIN profiles p ON u.id = p.id
    LEFT JOIN branches b ON p.branch_id = b.id
    WHERE p.role = 'spv'
    ORDER BY p.full_name
")->fetchAll();

echo "<h3>👤 Supervisor yang Sudah Ada:</h3>";
if ($existing_spv) {
    echo "<table><tr><th>Nama</th><th>Email</th><th>Cabang Saat Ini</th><th>Status</th></tr>";
    foreach ($existing_spv as $s) {
        $cab = $s['branch_name'] ?: '<em style="color:#94a3b8">Pusat / Semua Cabang</em>';
        $status = $s['branch_id'] ? '✅ Sudah terikat cabang' : '⚠️ Belum ada cabang';
        echo "<tr><td>{$s['full_name']}</td><td>{$s['email']}</td><td>{$cab}</td><td>{$status}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<div class='info'>ℹ️ Belum ada akun SPV.</div>";
}

// ── 3. FORM ASSIGN CABANG KE SPV ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'create_spv') {
        // Buat akun SPV baru
        $full_name   = trim($_POST['full_name']);
        $email       = trim($_POST['email']);
        $password    = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $branch_id   = $_POST['branch_id'] ?: null;
        
        try {
            // Cek duplikat email
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetchColumn()) {
                throw new Exception("Email '$email' sudah terdaftar.");
            }
            
            $pdo->beginTransaction();
            $user_id = uuid();
            
            $pdo->prepare("INSERT INTO users (id, email, password) VALUES (?, ?, ?)")
                ->execute([$user_id, $email, $password]);
            
            $pdo->prepare("INSERT INTO profiles (id, full_name, role, branch_id) VALUES (?, ?, 'spv', ?)")
                ->execute([$user_id, $full_name, $branch_id]);
            
            $pdo->commit();
            
            $branch_name = 'Pusat';
            foreach ($branches as $b) {
                if ($b['id'] === $branch_id) { $branch_name = $b['name']; break; }
            }
            echo "<div class='ok'>✅ Berhasil membuat akun SPV <strong>$full_name</strong> → Cabang: <strong>$branch_name</strong> | Email: <strong>$email</strong></div>";
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "<div class='err'>❌ Gagal: " . $e->getMessage() . "</div>";
        }
    }
    
    elseif ($_POST['action'] === 'assign_branch') {
        // Update branch_id SPV yang sudah ada
        $spv_id    = $_POST['spv_id'];
        $branch_id = $_POST['branch_id'] ?: null;
        
        try {
            $pdo->prepare("UPDATE profiles SET branch_id = ? WHERE id = ? AND role = 'spv'")
                ->execute([$branch_id, $spv_id]);
            
            $branch_name = $branch_id ? '' : 'Pusat / Semua Cabang';
            foreach ($branches as $b) {
                if ($b['id'] === $branch_id) { $branch_name = $b['name']; break; }
            }
            echo "<div class='ok'>✅ Berhasil assign cabang <strong>$branch_name</strong> ke SPV.</div>";
        } catch (Exception $e) {
            echo "<div class='err'>❌ Gagal: " . $e->getMessage() . "</div>";
        }
    }
    
    // Refresh data setelah aksi
    $existing_spv = $pdo->query("
        SELECT u.id, u.email, p.full_name, p.branch_id, b.name as branch_name
        FROM users u JOIN profiles p ON u.id = p.id
        LEFT JOIN branches b ON p.branch_id = b.id
        WHERE p.role = 'spv' ORDER BY p.full_name
    ")->fetchAll();
}

// ── 4. FORM BUAT SPV BARU ─────────────────────────────────────────
echo "<hr style='margin:24px 0'>";
echo "<h3>➕ Buat Akun SPV Baru</h3>";
echo "<form method='POST' style='background:#f8fafc;padding:20px;border-radius:12px;border:1px solid #e2e8f0'>";
echo "<input type='hidden' name='action' value='create_spv'>";
echo "<div style='display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px'>";
echo "<div><label style='font-size:11px;font-weight:bold;color:#64748b;display:block;margin-bottom:4px'>NAMA LENGKAP</label><input type='text' name='full_name' required placeholder='Contoh: Supervisor BSD' style='width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box'></div>";
echo "<div><label style='font-size:11px;font-weight:bold;color:#64748b;display:block;margin-bottom:4px'>EMAIL LOGIN</label><input type='email' name='email' required placeholder='spv.bsd@inka.com' style='width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box'></div>";
echo "<div><label style='font-size:11px;font-weight:bold;color:#64748b;display:block;margin-bottom:4px'>PASSWORD</label><input type='password' name='password' required value='inka2026' style='width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box'></div>";
echo "<div><label style='font-size:11px;font-weight:bold;color:#64748b;display:block;margin-bottom:4px'>CABANG</label><select name='branch_id' style='width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box'>";
echo "<option value=''>- Pusat / Semua Cabang -</option>";
foreach ($branches as $b) {
    echo "<option value='{$b['id']}'>{$b['name']}</option>";
}
echo "</select></div>";
echo "</div>";
echo "<button type='submit' style='background:#2563eb;color:white;padding:12px 24px;border:none;border-radius:8px;font-weight:bold;cursor:pointer'>Buat Akun SPV</button>";
echo "</form>";

// ── 5. FORM ASSIGN CABANG KE SPV EXISTING ────────────────────────
if (!empty($existing_spv)) {
    echo "<hr style='margin:24px 0'>";
    echo "<h3>🔗 Assign Cabang ke SPV yang Sudah Ada</h3>";
    foreach ($existing_spv as $s) {
        echo "<form method='POST' style='background:#f8fafc;padding:16px;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:12px;display:flex;align-items:center;gap:12px'>";
        echo "<input type='hidden' name='action' value='assign_branch'>";
        echo "<input type='hidden' name='spv_id' value='{$s['id']}'>";
        echo "<div style='flex:1'><strong>{$s['full_name']}</strong><br><span style='color:#64748b;font-size:12px'>{$s['email']}</span></div>";
        echo "<select name='branch_id' style='padding:10px;border:1px solid #cbd5e1;border-radius:8px'>";
        echo "<option value=''>- Pusat / Semua Cabang -</option>";
        foreach ($branches as $b) {
            $sel = ($b['id'] === $s['branch_id']) ? 'selected' : '';
            echo "<option value='{$b['id']}' $sel>{$b['name']}</option>";
        }
        echo "</select>";
        echo "<button type='submit' style='background:#6366f1;color:white;padding:10px 20px;border:none;border-radius:8px;font-weight:bold;cursor:pointer'>Assign</button>";
        echo "</form>";
    }
}

echo "<div class='info' style='margin-top:24px'>⚠️ <strong>Setelah selesai, hapus file ini dari server production untuk keamanan.</strong></div>";
echo "</body></html>";
