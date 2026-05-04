<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Hanya user dengan role 'member' yang seharusnya menjadikan ini halaman utamanya
// (Admin juga bisa akses untuk sekadar preview)
if (!has_role(['member', 'admin', 'admin_depok', 'admin_bsd', 'spv', 'owner'])) {
    header("Location: " . BASE_URL . "login.php"); exit();
}

$page_title = 'Profil Member Inka';
$user_id = $_SESSION['user_id'];

// Jika Admin/Owner yang sedang preview, kita pakai user_id member fiktif atau biarkan kosong
// Tapi idealnya ini dilihat oleh akun member asli.
$is_preview = !is_member();

// ── AMBIL DATA PROFIL MEMBER ────────────────────────────────────
$stmt = $pdo->prepare("SELECT p.full_name, p.phone, p.total_points, p.created_at, u.email 
                       FROM profiles p JOIN users u ON p.id = u.id WHERE p.id = ?");
$stmt->execute([$user_id]);
$member = $stmt->fetch();

// ── AMBIL STATISTIK BOOKING ────────────────────────────
$stmt_bk = $pdo->prepare("SELECT 
    COUNT(*) as total_bookings,
    SUM(CASE WHEN refund_status = 'completed' THEN 1 ELSE 0 END) as total_refunds
    FROM bookings WHERE customer_phone = ?");
$stmt_bk->execute([$member['phone']]);
$booking_stats = $stmt_bk->fetch();

// ── AMBIL RIWAYAT SERVIS (TRANSAKSI) ────────────────────────────
$stmt_tx = $pdo->prepare("
    SELECT t.transaction_code, t.created_at, t.total_amount, b.name as branch_name
    FROM transactions t
    LEFT JOIN branches b ON t.branch_id = b.id
    WHERE t.member_id = ? AND t.status = 'Paid'
    ORDER BY t.created_at DESC
");
$stmt_tx->execute([$user_id]);
$history = $stmt_tx->fetchAll();

// ── AMBIL VOUCHER YANG BELUM DIPAKAI ────────────────────────────
$stmt_vc = $pdo->prepare("
    SELECT v.code, v.expires_at, r.name as reward_name, r.discount_amount 
    FROM vouchers v
    JOIN rewards r ON v.reward_id = r.id
    WHERE v.member_id = ? AND v.is_used = 0 AND v.expires_at > NOW()
    ORDER BY v.expires_at ASC
");
$stmt_vc->execute([$user_id]);
$vouchers = $stmt_vc->fetchAll();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
    <!-- Topbar (Sederhana untuk Member) -->
    <header class="h-16 sm:h-20 flex-shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu"></i>
        </button>

        <div class="flex-1 lg:ml-0 px-4">
            <img src="https://ui-avatars.com/api/?name=Inka+Otoservice&background=0D8ABC&color=fff&rounded=true&bold=true" class="h-8 w-8 sm:hidden rounded-lg shadow-sm" alt="Logo">
        </div>

        <div class="flex items-center gap-3">
            <div class="text-right hidden sm:block mr-2">
                <p class="text-xs font-black text-slate-900 leading-tight"><?php echo htmlspecialchars($member['full_name'] ?? 'Admin (Preview)'); ?></p>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Member Inka</p>
            </div>
            <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center text-amber-600 shadow-inner">
                <i data-lucide="user" class="w-5 h-5"></i>
            </div>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar bg-slate-50">
        
        <div class="max-w-4xl mx-auto space-y-8 pb-10">

            <?php if ($is_preview): ?>
            <div class="bg-blue-50 border border-blue-200 text-blue-700 p-4 rounded-2xl text-sm font-semibold flex gap-3 mb-6">
                <i data-lucide="info" class="w-5 h-5 shrink-0"></i>
                <p>Anda sedang melihat halaman ini sebagai Admin. Data yang tampil di bawah adalah struktur kasarnya. Untuk melihat halaman asli, silakan <a href="../login.php" class="underline font-black">Login sebagai Member</a> (misal: member@inka.com).</p>
            </div>
            <?php endif; ?>

            <!-- KARTU MEMBER DIGITAL -->
            <div class="bg-gradient-to-br from-slate-900 to-slate-800 rounded-[2rem] p-8 md:p-10 text-white shadow-2xl relative overflow-hidden">
                <!-- Elemen Dekoratif -->
                <div class="absolute -right-20 -top-20 w-64 h-64 bg-white/5 rounded-full blur-3xl"></div>
                <div class="absolute -left-10 -bottom-10 w-40 h-40 bg-blue-500/20 rounded-full blur-2xl"></div>
                
                <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-8">
                    
                    <div>
                        <div class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-md px-3 py-1.5 rounded-full border border-white/10 mb-6">
                            <i data-lucide="shield-check" class="w-4 h-4 text-emerald-400"></i>
                            <span class="text-[10px] font-black uppercase tracking-widest text-emerald-100">Member Terverifikasi</span>
                        </div>
                        
                        <h2 class="text-3xl md:text-4xl font-black tracking-tight mb-2"><?php echo htmlspecialchars($member['full_name'] ?? 'Nama Member'); ?></h2>
                        <p class="text-slate-400 font-medium text-sm flex items-center gap-2">
                            <i data-lucide="mail" class="w-4 h-4"></i> <?php echo htmlspecialchars($member['email'] ?? 'email@domain.com'); ?>
                        </p>
                    </div>

                    <div class="bg-black/30 backdrop-blur-sm p-6 rounded-3xl border border-white/10 md:min-w-[200px] text-center">
                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest mb-2">Total Poin Saya</p>
                        <div class="flex items-center justify-center gap-2 text-amber-400 mb-3">
                            <i data-lucide="star" class="w-8 h-8 fill-amber-400 drop-shadow-[0_0_15px_rgba(251,191,36,0.5)]"></i>
                            <span class="text-5xl font-black tracking-tighter"><?php echo number_format($member['total_points'] ?? 0); ?></span>
                        </div>
                        <div class="flex gap-2 justify-center border-t border-white/10 pt-3">
                            <div class="bg-white/10 rounded-lg px-2.5 py-1 text-[9px] text-slate-300 font-bold uppercase tracking-wider">
                                <?php echo $booking_stats['total_bookings'] ?? 0; ?> Booking
                            </div>
                            <?php if (($booking_stats['total_refunds'] ?? 0) > 0): ?>
                            <div class="bg-red-500/20 text-red-300 border border-red-500/30 rounded-lg px-2.5 py-1 text-[9px] font-bold uppercase tracking-wider">
                                <?php echo $booking_stats['total_refunds']; ?> Refund
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>

            <!-- VOUCHER AKTIF -->
            <?php if (!empty($vouchers)): ?>
            <div>
                <h3 class="font-black text-slate-900 text-lg mb-4 flex items-center gap-2">
                    <i data-lucide="ticket" class="text-indigo-500"></i> Voucher Anda Saat Ini
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($vouchers as $v): ?>
                    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-3xl p-1 shadow-lg shadow-indigo-500/20 relative overflow-hidden group">
                        <!-- Perforated edges effect -->
                        <div class="absolute left-0 top-1/2 -translate-y-1/2 -ml-2 w-4 h-4 bg-slate-50 rounded-full z-10"></div>
                        <div class="absolute right-0 top-1/2 -translate-y-1/2 -mr-2 w-4 h-4 bg-slate-50 rounded-full z-10"></div>

                        <div class="bg-white rounded-[1.3rem] p-5 h-full flex flex-col relative overflow-hidden">
                            <i data-lucide="gift" class="absolute -right-2 -bottom-2 w-20 h-20 text-indigo-50 rotate-12 group-hover:scale-110 transition-transform"></i>
                            
                            <div class="relative z-10">
                                <h4 class="font-black text-slate-900 text-base leading-tight mb-1"><?php echo htmlspecialchars($v['reward_name']); ?></h4>
                                <p class="text-xs font-semibold text-slate-500 mb-4">Potongan Rp <?php echo number_format($v['discount_amount'], 0, ',', '.'); ?></p>
                                
                                <div class="bg-slate-100 border border-slate-200 border-dashed rounded-xl p-3 text-center mb-4">
                                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Tunjukkan Kode Ini Ke Kasir</p>
                                    <p class="text-lg font-black text-indigo-700 tracking-[0.2em]"><?php echo htmlspecialchars($v['code']); ?></p>
                                </div>

                                <p class="text-[10px] font-bold text-red-500 uppercase tracking-widest text-center flex justify-center items-center gap-1">
                                    <i data-lucide="clock" class="w-3 h-3"></i> Kadaluarsa: <?php echo date('d M Y', strtotime($v['expires_at'])); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- RIWAYAT SERVIS -->
            <div>
                <h3 class="font-black text-slate-900 text-lg mb-4 flex items-center gap-2">
                    <i data-lucide="history" class="text-blue-500"></i> Riwayat Servis Anda
                </h3>
                
                <div class="bg-white rounded-3xl shadow-lg border border-slate-100 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400 font-black">
                                    <th class="p-5">Tanggal & Lokasi</th>
                                    <th class="p-5">Kode Invoice</th>
                                    <th class="p-5 text-right">Total Biaya</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($history as $h): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="p-5">
                                        <p class="font-black text-slate-900 text-sm"><?php echo date('d F Y', strtotime($h['created_at'])); ?></p>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5 flex items-center gap-1">
                                            <i data-lucide="map-pin" class="w-3 h-3"></i> <?php echo htmlspecialchars($h['branch_name']); ?>
                                        </p>
                                    </td>
                                    <td class="p-5">
                                        <span class="inline-block bg-slate-100 text-slate-700 font-black text-[10px] px-2.5 py-1.5 rounded-lg uppercase tracking-widest border border-slate-200">
                                            <?php echo htmlspecialchars($h['transaction_code']); ?>
                                        </span>
                                    </td>
                                    <td class="p-5 text-right">
                                        <p class="text-sm font-black text-slate-900"><?php echo rupiah($h['total_amount']); ?></p>
                                        <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mt-1">+ Poin Masuk</p>
                                    </td>
                                </tr>
                                <?php endforeach; ?>

                                <?php if (empty($history)): ?>
                                <tr>
                                    <td colspan="3" class="py-16 text-center text-slate-400">
                                        <i data-lucide="car" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
                                        <p class="font-semibold text-sm">Belum ada riwayat servis tercatat.</p>
                                        <p class="text-xs mt-1">Ayo jadwalkan servis mobil Anda di Inka Otoservice!</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
