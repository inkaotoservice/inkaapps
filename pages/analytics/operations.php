<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
auth_ready();

if (!has_role(['owner', 'manager_ops', 'spv'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Analytics - Operasional';

// Setup Branch Filter — Default ke cabang sendiri jika SPV
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$spv_branch = (is_spv() && !empty($_SESSION['branch_id'])) ? $_SESSION['branch_id'] : '';
$filter_branch = $_GET['branch_id'] ?? $spv_branch;
$where_branch = $filter_branch ? "AND branch_id = :branch" : "";

// 1. Total Bookings This Month
$sql_bookings = "SELECT COUNT(*) FROM bookings WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE()) $where_branch";
$stmt_b = $pdo->prepare($sql_bookings);
if ($filter_branch) $stmt_b->bindValue(':branch', $filter_branch);
$stmt_b->execute();
$total_bookings = $stmt_b->fetchColumn() ?: 0;

// 2. Cancellation Rate
$sql_cancel = "SELECT COUNT(*) FROM bookings WHERE status = 'cancelled' AND MONTH(created_at) = MONTH(CURRENT_DATE()) $where_branch";
$stmt_c = $pdo->prepare($sql_cancel);
if ($filter_branch) $stmt_c->bindValue(':branch', $filter_branch);
$stmt_c->execute();
$total_cancel = $stmt_c->fetchColumn() ?: 0;
$cancel_rate = $total_bookings > 0 ? round(($total_cancel / $total_bookings) * 100, 1) : 0;

// 3. Average Service Time (in minutes) for completed bookings this month
$sql_time = "
    SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) 
    FROM bookings 
    WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURRENT_DATE()) $where_branch
";
$stmt_t = $pdo->prepare($sql_time);
if ($filter_branch) $stmt_t->bindValue(':branch', $filter_branch);
$stmt_t->execute();
$avg_time = $stmt_t->fetchColumn() ?: 0;
$avg_time_str = $avg_time > 60 ? floor($avg_time / 60) . ' jam ' . round($avg_time % 60) . ' mnt' : round($avg_time) . ' menit';

// 4. Booking Type Ratio
$sql_type = "SELECT is_online, COUNT(*) as total FROM bookings WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) $where_branch GROUP BY is_online";
$stmt_type = $pdo->prepare($sql_type);
if ($filter_branch) $stmt_type->bindValue(':branch', $filter_branch);
$stmt_type->execute();
$types = $stmt_type->fetchAll(PDO::FETCH_ASSOC);
$type_online = 0; $type_walkin = 0;
foreach($types as $t) {
    if($t['is_online']) $type_online += $t['total'];
    else $type_walkin += $t['total'];
}

// 5. Active Queues Right Now
$sql_active = "
    SELECT b.license_plate, b.customer_name, b.status, br.name as branch_name 
    FROM bookings b 
    LEFT JOIN branches br ON b.branch_id = br.id
    WHERE b.status IN ('pending', 'processing') AND DATE(b.service_date) = CURRENT_DATE() $where_branch
    ORDER BY b.created_at ASC
";
$stmt_act = $pdo->prepare($sql_active);
if ($filter_branch) $stmt_act->bindValue(':branch', $filter_branch);
$stmt_act->execute();
$active_queues = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

?>
<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50 relative">
    <header class="h-16 lg:h-20 bg-white/50 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 z-40">
        <div class="flex items-center gap-3">
            <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full'); document.getElementById('sidebar-overlay').classList.toggle('hidden');" class="p-2 lg:hidden bg-slate-100 text-slate-600 rounded-xl">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div class="hidden lg:flex w-10 h-10 bg-rose-50 rounded-xl items-center justify-center text-rose-600">
                <i data-lucide="activity" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="text-lg lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Kinerja Operasional</h1>
                <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-0.5">Analisis Waktu & Hambatan Bulan Ini</p>
            </div>
        </div>
        
        <form method="GET" class="flex items-center gap-2">
            <select name="branch_id" class="px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-sm font-bold text-slate-700 outline-none focus:ring-4 focus:ring-rose-500/10">
                <option value="">Semua Cabang (Global)</option>
                <?php foreach($branches as $b): ?>
                    <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="p-2.5 bg-rose-600 text-white rounded-xl hover:bg-rose-700 transition-colors shadow-lg shadow-rose-500/20">
                <i data-lucide="filter" class="w-4 h-4"></i>
            </button>
        </form>
    </header>

    <div class="flex-1 overflow-y-auto p-4 lg:p-8 custom-scrollbar">
        <div class="max-w-7xl mx-auto space-y-6">
            
            <!-- KPI Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm relative overflow-hidden group hover:shadow-md transition-all">
                    <div class="relative z-10">
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Total Service (Bulan Ini)</p>
                        <h3 class="text-3xl font-black text-slate-900 tracking-tighter"><?php echo $total_bookings; ?> <span class="text-sm text-slate-400">Mobil</span></h3>
                    </div>
                </div>
                
                <div class="bg-gradient-to-br from-rose-500 to-red-600 p-6 rounded-[2rem] shadow-lg shadow-rose-500/20 relative overflow-hidden text-white">
                    <div class="relative z-10">
                        <p class="text-[10px] font-black uppercase tracking-widest text-rose-100 mb-2">Tingkat Batal / Cancel</p>
                        <h3 class="text-3xl font-black tracking-tighter"><?php echo $cancel_rate; ?>%</h3>
                        <p class="text-[10px] text-rose-200 mt-1"><?php echo $total_cancel; ?> booking dibatalkan</p>
                    </div>
                </div>
                
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm relative overflow-hidden group hover:shadow-md transition-all md:col-span-2">
                    <div class="relative z-10 flex items-center gap-6">
                        <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center shrink-0">
                            <i data-lucide="clock" class="w-8 h-8"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Rata-rata Waktu Pengerjaan</p>
                            <h3 class="text-3xl font-black text-slate-900 tracking-tighter"><?php echo $avg_time_str; ?></h3>
                            <p class="text-[10px] font-semibold text-slate-400 mt-1">Dari mobil datang hingga selesai nota</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Ratio Online vs Walk-in -->
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-900 mb-6 flex items-center gap-2">
                        <i data-lucide="users" class="w-4 h-4 text-rose-500"></i> Sumber Kedatangan Pelanggan
                    </h3>
                    <div class="space-y-4">
                        <?php 
                        $pct_online = $total_bookings > 0 ? round(($type_online / $total_bookings) * 100) : 0;
                        $pct_walkin = $total_bookings > 0 ? round(($type_walkin / $total_bookings) * 100) : 0;
                        ?>
                        <div>
                            <div class="flex justify-between text-xs font-bold text-slate-700 mb-1">
                                <span>Booking Online</span>
                                <span><?php echo $type_online; ?> Mobil (<?php echo $pct_online; ?>%)</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                                <div class="bg-indigo-500 h-2.5 rounded-full" style="width: <?php echo $pct_online; ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs font-bold text-slate-700 mb-1">
                                <span>Walk-in (Langsung Datang)</span>
                                <span><?php echo $type_walkin; ?> Mobil (<?php echo $pct_walkin; ?>%)</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                                <div class="bg-blue-500 h-2.5 rounded-full" style="width: <?php echo $pct_walkin; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Queues -->
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xs font-black uppercase tracking-widest text-slate-900 flex items-center gap-2">
                            <i data-lucide="car" class="w-4 h-4 text-emerald-500"></i> Mobil Sedang Dikerjakan Saat Ini
                        </h3>
                        <span class="bg-emerald-100 text-emerald-700 font-black text-[10px] px-2 py-1 rounded-md"><?php echo count($active_queues); ?> Aktif</span>
                    </div>
                    
                    <div class="space-y-3 max-h-[300px] overflow-y-auto custom-scrollbar pr-2">
                        <?php foreach($active_queues as $q): ?>
                        <div class="p-3 rounded-xl border border-slate-100 bg-slate-50 flex items-center justify-between">
                            <div>
                                <h4 class="text-sm font-bold text-slate-900 uppercase tracking-widest"><?php echo htmlspecialchars($q['license_plate']); ?></h4>
                                <p class="text-[10px] font-semibold text-slate-500"><?php echo htmlspecialchars($q['customer_name']); ?> • <?php echo htmlspecialchars($q['branch_name']); ?></p>
                            </div>
                            <div class="text-right">
                                <?php if($q['status'] == 'processing'): ?>
                                    <span class="text-[9px] font-black uppercase tracking-widest text-blue-600 bg-blue-50 px-2 py-1 rounded">Dikerjakan</span>
                                <?php else: ?>
                                    <span class="text-[9px] font-black uppercase tracking-widest text-amber-600 bg-amber-50 px-2 py-1 rounded">Menunggu</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if(empty($active_queues)): ?>
                            <div class="text-center py-10 text-slate-400">
                                <i data-lucide="check-circle-2" class="w-10 h-10 mx-auto mb-2 opacity-30"></i>
                                <p class="text-[10px] font-bold uppercase tracking-widest">Semua antrian selesai</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>
<script>lucide.createIcons();</script>
<?php include '../../includes/footer.php'; ?>
