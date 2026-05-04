<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

if (!has_role(['admin','admin_depok','admin_bsd','spv','owner'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Tukar Poin Member';
$msg = '';
$msg_type = '';

$search_query = $_GET['q'] ?? '';
$member = null;

// ── PROSES CARI MEMBER ──────────────────────────────────────────
if ($search_query) {
    $stmt = $pdo->prepare("
        SELECT p.id, p.full_name, p.phone, p.total_points, u.email 
        FROM profiles p
        JOIN users u ON p.id = u.id
        WHERE p.role = 'member' AND (p.full_name LIKE ? OR p.phone LIKE ? OR u.email LIKE ?)
        LIMIT 1
    ");
    $searchTerm = "%$search_query%";
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $member = $stmt->fetch();

    if (!$member) {
        $msg = "Member tidak ditemukan. Coba cari menggunakan email atau no HP yang tepat.";
        $msg_type = "error";
    }
}

// ── PROSES REDEEM (TUKAR POIN) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'redeem') {
    $member_id = $_POST['member_id'];
    $reward_id = $_POST['reward_id'];
    
    try {
        $pdo->beginTransaction();

        // 1. Kunci data member & cek poin
        $stmt_mem = $pdo->prepare("SELECT total_points FROM profiles WHERE id = ? FOR UPDATE");
        $stmt_mem->execute([$member_id]);
        $current_points = $stmt_mem->fetchColumn();

        // 2. Ambil data reward
        $stmt_rew = $pdo->prepare("SELECT name, points_required, discount_amount FROM rewards WHERE id = ?");
        $stmt_rew->execute([$reward_id]);
        $reward = $stmt_rew->fetch();

        if (!$reward) throw new Exception("Reward tidak valid.");
        if ($current_points < $reward['points_required']) throw new Exception("Poin tidak mencukupi.");

        // 3. Potong Poin Member
        $new_points = $current_points - $reward['points_required'];
        $pdo->prepare("UPDATE profiles SET total_points = ? WHERE id = ?")->execute([$new_points, $member_id]);

        // 4. Catat Log Point Transaction
        $pdo->prepare("INSERT INTO point_transactions (id, member_id, points, type, description) VALUES (?, ?, ?, 'redeem', ?)")
            ->execute([uuid(), $member_id, -$reward['points_required'], "Tukar poin: " . $reward['name']]);

        // 5. Generate Voucher Code (Misal: INKA-RANDOM)
        $voucher_code = 'INKA-' . strtoupper(substr(uniqid(), -6));
        
        // Voucher expired dalam 30 hari
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

        $pdo->prepare("INSERT INTO vouchers (id, member_id, reward_id, code, is_used, expires_at) VALUES (?, ?, ?, ?, 0, ?)")
            ->execute([uuid(), $member_id, $reward_id, $voucher_code, $expires]);

        $pdo->commit();
        
        // Sukses
        $msg = "Berhasil menukar poin! Kode Voucher pelanggan: <strong class='text-blue-900 tracking-widest text-lg ml-2'>$voucher_code</strong>";
        $msg_type = "success";
        
        // Refresh data member agar poin update di layar
        $member['total_points'] = $new_points;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "Gagal menukar poin: " . $e->getMessage();
        $msg_type = "error";
    }
}

// ── AMBIL DATA REWARD AKTIF ─────────────────────────────────────
$rewards = $pdo->query("SELECT * FROM rewards WHERE is_active = 1 ORDER BY points_required ASC")->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
    <!-- Topbar -->
    <header class="h-16 sm:h-20 flex-shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu"></i>
        </button>

        <div class="flex-1 lg:ml-0 px-4">
            <h1 class="text-sm sm:text-lg font-semibold text-slate-900 truncate uppercase tracking-widest opacity-60">
                Tukar Poin Member
            </h1>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar bg-slate-50">
        
        <div class="max-w-5xl mx-auto">
            
            <?php if ($msg): ?>
            <div class="mb-6 p-5 rounded-2xl flex items-center gap-3 font-semibold text-sm shadow-sm <?php echo $msg_type === 'success' ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-100'; ?>">
                <i data-lucide="<?php echo $msg_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-6 h-6 shrink-0"></i>
                <div><?php echo $msg; ?></div>
            </div>
            <?php endif; ?>

            <!-- Pencarian Member -->
            <div class="bg-white rounded-3xl shadow-lg border border-slate-100 overflow-hidden mb-8">
                <div class="p-6 md:p-8">
                    <form action="" method="GET" class="flex flex-col md:flex-row gap-4">
                        <div class="relative flex-1">
                            <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                            <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Cari No. HP, Nama, atau Email pelanggan..." required class="w-full pl-12 pr-4 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none text-base font-semibold transition-all">
                        </div>
                        <button type="submit" class="bg-blue-600 text-white px-8 py-4 rounded-2xl font-black text-sm uppercase tracking-widest hover:bg-blue-700 transition-all shadow-lg shadow-blue-500/30 flex items-center justify-center gap-2">
                            Cari Member
                        </button>
                    </form>
                </div>
            </div>

            <!-- Area Hasil Pencarian -->
            <?php if ($member): ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    
                    <!-- Kartu Member -->
                    <div class="lg:col-span-1">
                        <div class="bg-gradient-to-br from-slate-900 to-slate-800 rounded-3xl p-6 md:p-8 text-white shadow-xl relative overflow-hidden">
                            <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-white/5 rounded-full blur-3xl"></div>
                            <i data-lucide="award" class="absolute right-4 top-4 w-24 h-24 text-white/5 rotate-12"></i>
                            
                            <div class="relative z-10">
                                <span class="bg-white/20 text-white text-[10px] font-black px-3 py-1 rounded-full uppercase tracking-widest mb-6 inline-block backdrop-blur-md border border-white/20">
                                    Member Terverifikasi
                                </span>
                                
                                <h3 class="text-2xl font-black mb-1 tracking-tight"><?php echo htmlspecialchars($member['full_name']); ?></h3>
                                <p class="text-slate-400 font-medium text-sm mb-8 flex items-center gap-2">
                                    <i data-lucide="phone" class="w-3.5 h-3.5"></i> <?php echo htmlspecialchars($member['phone'] ?: $member['email']); ?>
                                </p>

                                <div class="bg-black/20 p-4 rounded-2xl border border-white/10">
                                    <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest mb-1">Total Poin Saat Ini</p>
                                    <div class="flex items-center gap-2 text-amber-400">
                                        <i data-lucide="star" class="w-6 h-6 fill-amber-400"></i>
                                        <span class="text-4xl font-black tracking-tighter"><?php echo number_format($member['total_points']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Katalog Reward yang Bisa Ditebus -->
                    <div class="lg:col-span-2">
                        <h3 class="font-black text-slate-900 text-lg mb-4 flex items-center gap-2">
                            <i data-lucide="gift" class="text-blue-500"></i> Pilihan Reward Tersedia
                        </h3>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <?php foreach ($rewards as $r): 
                                $can_afford = $member['total_points'] >= $r['points_required'];
                            ?>
                                <div class="bg-white p-5 rounded-3xl border <?php echo $can_afford ? 'border-amber-200 shadow-lg shadow-amber-500/10' : 'border-slate-100 opacity-60'; ?> transition-all relative overflow-hidden group">
                                    
                                    <h4 class="font-black text-slate-900 text-lg mb-1"><?php echo htmlspecialchars($r['name']); ?></h4>
                                    <p class="text-xs font-semibold text-slate-500 mb-4">Nilai Diskon: <span class="text-indigo-600 font-black"><?php echo rupiah($r['discount_amount']); ?></span></p>
                                    
                                    <div class="flex items-center justify-between mt-auto">
                                        <span class="text-xs font-black uppercase tracking-widest flex items-center gap-1 <?php echo $can_afford ? 'text-amber-600' : 'text-slate-400'; ?>">
                                            <i data-lucide="star" class="w-3.5 h-3.5 <?php echo $can_afford ? 'fill-amber-500 text-amber-500' : ''; ?>"></i>
                                            <?php echo number_format($r['points_required']); ?> Poin
                                        </span>

                                        <?php if ($can_afford): ?>
                                            <form method="POST" action="" onsubmit="return confirm('Tukar <?php echo number_format($r['points_required']); ?> poin dengan <?php echo htmlspecialchars($r['name']); ?>?');">
                                                <input type="hidden" name="action" value="redeem">
                                                <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                <input type="hidden" name="reward_id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="bg-amber-50 text-amber-700 hover:bg-amber-500 hover:text-white px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest transition-colors shadow-sm">
                                                    Tukar
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button disabled class="bg-slate-100 text-slate-400 px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest cursor-not-allowed">
                                                Kurang
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (empty($rewards)): ?>
                                <div class="col-span-full py-10 text-center text-slate-400 bg-white rounded-3xl border border-slate-100">
                                    <p class="font-semibold text-sm">Tidak ada reward yang aktif saat ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            <?php elseif ($search_query): ?>
                <!-- Sudah dihandle oleh alert error di atas -->
            <?php else: ?>
                <!-- State Kosong / Awal -->
                <div class="text-center py-20 px-4 bg-white rounded-3xl border border-slate-100 border-dashed">
                    <div class="w-24 h-24 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i data-lucide="search" class="w-10 h-10 text-slate-300"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-900 mb-2">Cari Pelanggan Dulu</h3>
                    <p class="text-slate-500 text-sm max-w-sm mx-auto">Ketikkan nomor WhatsApp, Nama, atau Email pelanggan di kolom pencarian di atas untuk melihat saldo poin mereka.</p>
                </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
