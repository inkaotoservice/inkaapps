<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
auth_ready();

// Admin langsung ke POS (Opsional: dinonaktifkan agar bisa lihat Dashboard)
// if (is_admin()) { redirect_by_role(); }

$page_title = 'Dashboard Overview';
$role       = get_role();
$role_cfg   = get_role_config();
$first_name = explode(' ', $_SESSION['full_name'])[0];

// ── FILTER CABANG (SPV hanya lihat cabangnya) ─────────────────────
$spv_branch = get_branch_filter(); // null = lihat semua

// ── AMBIL DATA STATISTIK ──────────────────────────────────────────
$month_start = date('Y-m-01 00:00:00');
$today_start = date('Y-m-d 00:00:00');

// Revenue bulan ini
$rev_sql = "SELECT COALESCE(SUM(total_amount),0) as total FROM transactions WHERE status='Paid' AND created_at >= ?";
$rev_params = [$month_start];
if ($spv_branch) { $rev_sql .= " AND branch_id = ?"; $rev_params[] = $spv_branch; }
$rev = $pdo->prepare($rev_sql);
$rev->execute($rev_params);
$revenue = $rev->fetchColumn();

// Booking hari ini
$book_sql = "SELECT COUNT(*) FROM bookings WHERE created_at >= ?";
$book_params = [$today_start];
if ($spv_branch) { $book_sql .= " AND branch_id = ?"; $book_params[] = $spv_branch; }
$book_today = $pdo->prepare($book_sql);
$book_today->execute($book_params);
$today_bookings = $book_today->fetchColumn();

// Pengeluaran bulan ini
$exp_sql = "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE created_at >= ?";
$exp_params = [$month_start];
if ($spv_branch) { $exp_sql .= " AND branch_id = ?"; $exp_params[] = $spv_branch; }
$exp = $pdo->prepare($exp_sql);
$exp->execute($exp_params);
$expenses_month = $exp->fetchColumn();

// Booking terbaru
$rb_sql = "SELECT b.*, br.name as branch_name FROM bookings b LEFT JOIN branches br ON b.branch_id = br.id";
$rb_params = [];
if ($spv_branch) { $rb_sql .= " WHERE b.branch_id = ?"; $rb_params[] = $spv_branch; }
$rb_sql .= " ORDER BY b.created_at DESC LIMIT 5";
$stmt_rb = $pdo->prepare($rb_sql);
$stmt_rb->execute($rb_params);
$recent_bookings = $stmt_rb->fetchAll();

// Data cabang & revenue (SPV hanya lihat cabangnya sendiri)
$br_sql = "SELECT br.id, br.name,
           COALESCE(SUM(t.total_amount),0) as revenue,
           COUNT(t.id) as tx_count
    FROM branches br
    LEFT JOIN transactions t ON t.branch_id = br.id AND t.status='Paid' AND t.created_at >= '{$month_start}'";
if ($spv_branch) { $br_sql .= " WHERE br.id = " . $pdo->quote($spv_branch); }
$br_sql .= " GROUP BY br.id, br.name ORDER BY revenue DESC";
$branches_data = $pdo->query($br_sql)->fetchAll();

$monthly_target = 500000000; // Default target
$overall_pct    = $monthly_target > 0 ? min(round(($revenue / $monthly_target) * 100), 100) : 0;

$status_colors = [
    'pending'    => 'bg-amber-100 text-amber-700',
    'processing' => 'bg-blue-100 text-blue-700',
    'completed'  => 'bg-emerald-100 text-emerald-700',
    'cancelled'  => 'bg-red-100 text-red-700',
];
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<!-- MAIN CONTENT -->
<main class="flex-1 flex flex-col min-w-0 overflow-hidden">

    <!-- TOPBAR -->
    <header class="h-16 sm:h-20 shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <div class="flex items-center gap-4">
            <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-xl transition-colors">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="text-sm sm:text-base font-semibold text-slate-900 uppercase tracking-widest opacity-60">Overview</h1>
            </div>
        </div>
        <!-- Profile Badge -->
        <div class="flex items-center gap-3 px-3 py-2 rounded-2xl hover:bg-slate-50 cursor-pointer transition-all">
            <div class="hidden sm:block text-right">
                <p class="text-sm font-bold text-slate-900 leading-none"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                <p class="text-[10px] font-black uppercase tracking-widest <?php echo $role_cfg['color']; ?> mt-0.5"><?php echo $role_cfg['label']; ?></p>
            </div>
            <div class="w-10 h-10 rounded-xl <?php echo $role_cfg['bg']; ?> flex items-center justify-center border border-white/50 shadow-sm">
                <i data-lucide="<?php echo $role_cfg['icon']; ?>" class="w-5 h-5 <?php echo $role_cfg['color']; ?>"></i>
            </div>
        </div>
    </header>

    <!-- PAGE CONTENT -->
    <div class="flex-1 overflow-y-auto custom-scrollbar p-4 sm:p-6 lg:p-10">
        <div class="max-w-7xl mx-auto space-y-8">

            <!-- GREETING -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 class="text-3xl font-black text-slate-900 tracking-tight">Halo, <?php echo htmlspecialchars($first_name); ?>! 👋</h2>
                    <p class="text-slate-500 mt-1 font-medium">Berikut ringkasan bisnis Anda hari ini, <?php echo date('d F Y'); ?>.</p>
                </div>
                <?php if (is_owner() || is_manager() || is_spv()): ?>
                <a href="<?php echo BASE_URL; ?>pages/analytics/finance.php" class="flex items-center gap-2 px-5 py-3 bg-white border border-slate-100 rounded-2xl text-xs font-black uppercase tracking-widest hover:bg-slate-50 hover:shadow-md transition-all shadow-sm">
                    Analisa Detail <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                </a>
                <?php endif; ?>
            </div>

            <!-- STAT CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
                <!-- Revenue -->
                <div class="bg-white p-6 rounded-3xl shadow-lg hover:shadow-xl transition-all border border-slate-50">
                    <div class="flex justify-between items-start mb-5">
                        <div class="p-3 bg-blue-50 rounded-2xl"><i data-lucide="trending-up" class="w-6 h-6 text-blue-600"></i></div>
                    </div>
                    <p class="text-2xl font-black text-slate-900 tracking-tighter"><?php echo short_rupiah($revenue); ?></p>
                    <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest mt-1">Revenue Bulan Ini</p>
                </div>

                <!-- Booking Hari Ini -->
                <div class="bg-white p-6 rounded-3xl shadow-lg hover:shadow-xl transition-all border-l-4 border-blue-500 border border-slate-50">
                    <div class="flex justify-between items-start mb-5">
                        <div class="p-3 bg-blue-50 rounded-2xl"><i data-lucide="calendar-clock" class="w-6 h-6 text-blue-600"></i></div>
                    </div>
                    <p class="text-3xl font-black text-slate-900 tracking-tighter"><?php echo $today_bookings; ?></p>
                    <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest mt-1">Booking Hari Ini</p>
                </div>
                <!-- Pengeluaran -->
                <div class="bg-white p-6 rounded-3xl shadow-lg hover:shadow-xl transition-all border border-slate-50">
                    <div class="flex justify-between items-start mb-5">
                        <div class="p-3 bg-rose-50 rounded-2xl"><i data-lucide="trending-down" class="w-6 h-6 text-rose-600"></i></div>
                    </div>
                    <p class="text-2xl font-black text-slate-900 tracking-tighter"><?php echo short_rupiah($expenses_month); ?></p>
                    <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest mt-1">Pengeluaran Bulan Ini</p>
                </div>
            </div>

            <!-- TARGET PROGRESS -->
            <div class="rounded-3xl bg-gradient-to-r from-blue-700 to-indigo-800 p-8 sm:p-10 text-white relative overflow-hidden shadow-2xl shadow-blue-500/20">
                <div class="relative z-10">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
                        <div>
                            <p class="text-blue-100 text-[10px] font-black uppercase tracking-[0.2em] mb-2">Target Konsolidasi Bulanan</p>
                            <h3 class="text-3xl sm:text-4xl font-black tracking-tighter">
                                <?php echo rupiah($revenue); ?>
                                <span class="text-lg sm:text-xl font-bold text-blue-300 ml-3">/ <?php echo rupiah($monthly_target); ?></span>
                            </h3>
                        </div>
                        <p class="text-5xl sm:text-6xl font-black tracking-tighter italic opacity-90"><?php echo $overall_pct; ?>%</p>
                    </div>
                    <div class="h-4 bg-white/10 rounded-full overflow-hidden border border-white/5 p-1">
                        <div class="h-full rounded-full shadow-lg transition-all duration-1000 <?php echo $overall_pct >= 100 ? 'bg-emerald-400' : 'bg-gradient-to-r from-blue-300 to-white'; ?>"
                             style="width: <?php echo $overall_pct; ?>%"></div>
                    </div>
                </div>
                <i data-lucide="building-2" class="absolute -right-16 -bottom-16 w-56 h-56 text-white/5 rotate-12"></i>
            </div>

            <!-- BRANCHES + RECENT BOOKINGS -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

                <!-- Branches -->
                <div class="xl:col-span-2 space-y-4">
                    <div class="flex items-center justify-between px-1">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Status Cabang</h4>
                        <?php if (is_owner() || is_manager()): ?>
                        <a href="<?php echo BASE_URL; ?>pages/branches.php" class="text-[10px] font-black text-blue-600 uppercase tracking-widest hover:underline">Kelola</a>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($branches_data as $branch): ?>
                    <?php
                    $b_pct = $monthly_target > 0 ? min(round(($branch['revenue'] / ($monthly_target / max(count($branches_data),1))) * 100), 100) : 0;
                    ?>
                    <div class="bg-white p-6 rounded-3xl shadow-lg border border-slate-50 hover:shadow-xl transition-all">
                        <div class="flex items-center gap-4 mb-5">
                            <div class="w-12 h-12 rounded-2xl bg-slate-50 flex items-center justify-center text-blue-600">
                                <i data-lucide="building-2" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <p class="font-black text-slate-900 uppercase tracking-tight"><?php echo htmlspecialchars($branch['name']); ?></p>
                                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5"><?php echo $b_pct; ?>% Target · <?php echo $branch['tx_count']; ?> transaksi</p>
                            </div>
                        </div>
                        <div class="flex justify-between items-baseline mb-3">
                            <p class="text-xl font-black text-slate-900"><?php echo rupiah($branch['revenue']); ?></p>
                        </div>
                        <div class="h-2 w-full bg-slate-50 rounded-full overflow-hidden border border-slate-100">
                            <div class="h-full bg-blue-600 rounded-full transition-all duration-700" style="width: <?php echo $b_pct; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($branches_data)): ?>
                    <div class="bg-white p-8 rounded-3xl shadow-sm text-center text-slate-400">
                        <i data-lucide="building-2" class="w-10 h-10 mx-auto mb-3 opacity-30"></i>
                        <p class="font-semibold text-sm">Belum ada data cabang</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Bookings -->
                <div class="bg-white rounded-3xl shadow-lg border border-slate-50 overflow-hidden">
                    <div class="p-5 border-b border-slate-50 flex items-center justify-between">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Booking Terbaru</h4>
                        <i data-lucide="zap" class="w-4 h-4 text-amber-500"></i>
                    </div>
                    <div class="divide-y divide-slate-50">
                        <?php foreach ($recent_bookings as $rb): ?>
                        <div class="p-4 hover:bg-slate-50 transition-all">
                            <p class="font-black text-slate-900 text-xs uppercase truncate"><?php echo htmlspecialchars($rb['customer_name']); ?></p>
                            <div class="flex items-center justify-between mt-1.5">
                                <p class="text-[10px] text-slate-400 font-bold truncate max-w-[120px]"><?php echo htmlspecialchars($rb['car_model']); ?></p>
                                <span class="text-[9px] font-black px-2 py-0.5 rounded-full <?php echo $status_colors[$rb['status']] ?? 'bg-slate-100 text-slate-500'; ?>">
                                    <?php echo ucfirst($rb['status']); ?>
                                </span>
                            </div>
                            <p class="text-[9px] text-slate-300 mt-1"><?php echo $rb['branch_name'] ?? '-'; ?> · <?php echo time_ago($rb['created_at']); ?></p>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($recent_bookings)): ?>
                        <div class="p-8 text-center text-slate-400">
                            <p class="text-sm font-semibold">Belum ada booking</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo BASE_URL; ?>pages/antrian.php" class="block w-full text-center p-4 text-[9px] font-black text-slate-400 uppercase tracking-widest hover:text-slate-900 transition-colors border-t border-slate-50">
                        Lihat Semua Booking
                    </a>
                </div>
            </div>

        </div><!-- /.max-w -->
    </div><!-- /.content -->
</main>

<?php include 'includes/footer.php'; ?>
