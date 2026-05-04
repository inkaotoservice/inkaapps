<?php
/**
 * INKA OTOSERVICE - USER SEEDER
 * Jalankan SEKALI via browser: http://localhost/bengkel-pro-php/setup_users.php
 * Setelah selesai, HAPUS file ini untuk keamanan.
 */

require_once 'includes/config.php';

function uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

$password = password_hash('inka2026', PASSWORD_BCRYPT);
$errors   = [];
$success  = [];

// -----------------------------------------------
// BRANCHES
// -----------------------------------------------
$branches = [
    ['id' => uuid(), 'name' => 'Cabang Depok', 'address' => 'Depok, Jawa Barat'],
    ['id' => uuid(), 'name' => 'Cabang BSD',   'address' => 'BSD, Tangerang Selatan'],
];

foreach ($branches as $b) {
    try {
        $pdo->prepare("INSERT IGNORE INTO branches (id, name, address) VALUES (?,?,?)")
            ->execute([$b['id'], $b['name'], $b['address']]);
        $success[] = "Cabang '{$b['name']}' OK";
    } catch (Exception $e) {
        $errors[] = "Branch error: " . $e->getMessage();
    }
}

// Re-fetch branch IDs
$depok_id = $pdo->query("SELECT id FROM branches WHERE name='Cabang Depok' LIMIT 1")->fetchColumn();
$bsd_id   = $pdo->query("SELECT id FROM branches WHERE name='Cabang BSD' LIMIT 1")->fetchColumn();

// -----------------------------------------------
// USERS
// -----------------------------------------------
$users = [
    ['email' => 'owner@inka.com',  'full_name' => 'Owner Inka',       'role' => 'owner',      'branch_id' => null],
    ['email' => 'spv@inka.com',    'full_name' => 'Supervisor Inka',   'role' => 'spv',        'branch_id' => null],
    ['email' => 'manager@inka.com','full_name' => 'Manager Ops',       'role' => 'manager_ops','branch_id' => null],
    ['email' => 'depok@inka.com',  'full_name' => 'Admin Depok',       'role' => 'admin_depok','branch_id' => $depok_id],
    ['email' => 'bsd@inka.com',    'full_name' => 'Admin BSD',         'role' => 'admin_bsd',  'branch_id' => $bsd_id],
];

foreach ($users as $u) {
    $uid = uuid();
    try {
        // Cek apakah email sudah ada
        $exist = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $exist->execute([$u['email']]);
        $existing_id = $exist->fetchColumn();

        if ($existing_id) {
            // Update password
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$password, $existing_id]);
            $uid = $existing_id;
            $success[] = "User '{$u['email']}' password diperbarui.";
        } else {
            // Insert baru
            $pdo->prepare("INSERT INTO users (id,email,password) VALUES (?,?,?)")
                ->execute([$uid, $u['email'], $password]);
            $success[] = "User '{$u['email']}' dibuat.";
        }

        // Upsert profile
        $exist_profile = $pdo->prepare("SELECT id FROM profiles WHERE id=?");
        $exist_profile->execute([$uid]);

        if ($exist_profile->fetchColumn()) {
            $pdo->prepare("UPDATE profiles SET full_name=?,role=?,branch_id=? WHERE id=?")
                ->execute([$u['full_name'], $u['role'], $u['branch_id'], $uid]);
        } else {
            $pdo->prepare("INSERT INTO profiles (id,full_name,role,branch_id) VALUES (?,?,?,?)")
                ->execute([$uid, $u['full_name'], $u['role'], $u['branch_id']]);
        }
        $success[] = "Profile '{$u['full_name']}' ({$u['role']}) OK";

    } catch (Exception $e) {
        $errors[] = "Error '{$u['email']}': " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Setup Users - Inka Otoservice</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="bg-slate-50 p-10">
<div class="max-w-xl mx-auto">
    <div class="bg-white rounded-3xl shadow-xl p-8">
        <h1 class="text-2xl font-black text-slate-900 mb-2">Setup Users — Inka Otoservice</h1>
        <p class="text-slate-500 text-sm mb-6">Script ini hanya perlu dijalankan <strong>satu kali</strong>. Hapus file ini setelah selesai.</p>

        <?php if ($errors): ?>
            <div class="bg-red-50 border border-red-100 rounded-2xl p-4 mb-6">
                <p class="font-black text-red-600 text-xs uppercase tracking-widest mb-3">❌ Error</p>
                <?php foreach ($errors as $e): ?>
                    <p class="text-red-500 text-sm">• <?php echo $e; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-4 mb-6">
                <p class="font-black text-emerald-600 text-xs uppercase tracking-widest mb-3">✅ Berhasil</p>
                <?php foreach ($success as $s): ?>
                    <p class="text-emerald-600 text-sm">• <?php echo $s; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bg-slate-50 rounded-2xl p-6 mt-4">
            <p class="font-black text-slate-700 text-xs uppercase tracking-widest mb-3">Akun yang Dibuat:</p>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-slate-400 text-[10px] uppercase tracking-widest border-b border-slate-100">
                    <th class="pb-2">Email</th><th class="pb-2">Password</th><th class="pb-2">Role</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-50">
                    <tr><td class="py-2 font-semibold">owner@inka.com</td><td class="py-2 text-slate-500">inka2026</td><td class="py-2"><span class="bg-purple-100 text-purple-700 text-[10px] font-black px-2 py-0.5 rounded-full">owner</span></td></tr>
                    <tr><td class="py-2 font-semibold">spv@inka.com</td><td class="py-2 text-slate-500">inka2026</td><td class="py-2"><span class="bg-indigo-100 text-indigo-700 text-[10px] font-black px-2 py-0.5 rounded-full">spv</span></td></tr>
                    <tr><td class="py-2 font-semibold">manager@inka.com</td><td class="py-2 text-slate-500">inka2026</td><td class="py-2"><span class="bg-blue-100 text-blue-700 text-[10px] font-black px-2 py-0.5 rounded-full">manager_ops</span></td></tr>
                    <tr><td class="py-2 font-semibold">depok@inka.com</td><td class="py-2 text-slate-500">inka2026</td><td class="py-2"><span class="bg-blue-100 text-blue-700 text-[10px] font-black px-2 py-0.5 rounded-full">admin_depok</span></td></tr>
                    <tr><td class="py-2 font-semibold">bsd@inka.com</td><td class="py-2 text-slate-500">inka2026</td><td class="py-2"><span class="bg-blue-100 text-blue-700 text-[10px] font-black px-2 py-0.5 rounded-full">admin_bsd</span></td></tr>
                </tbody>
            </table>
        </div>

        <a href="login.php" class="mt-8 w-full bg-blue-600 text-white py-4 rounded-2xl font-black text-xs uppercase tracking-widest flex items-center justify-center gap-2 hover:bg-blue-700 transition-all">
            ✅ Selesai — Pergi ke Halaman Login
        </a>
    </div>
</div>
</body>
</html>
