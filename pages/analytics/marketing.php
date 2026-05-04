<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
auth_ready();

if (!has_role(['owner', 'manager_ops'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Analytics - Marketing & Pelanggan';

// Setup Branch Filter — Default ke cabang sendiri jika SPV
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$spv_branch = (is_spv() && !empty($_SESSION['branch_id'])) ? $_SESSION['branch_id'] : '';
$filter_branch = $_GET['branch_id'] ?? $spv_branch;
$where_branch_tx = $filter_branch ? "AND t.branch_id = :branch" : "";

// 1. VIP Customers (Top Spenders)
$sql_vip = "
    SELECT t.customer_name, SUM(t.total_amount) as total_spent, COUNT(t.id) as visit_count 
    FROM transactions t 
    WHERE t.status = 'Paid' $where_branch_tx
    GROUP BY t.customer_name 
    ORDER BY total_spent DESC LIMIT 10
";
$stmt_vip = $pdo->prepare($sql_vip);
if ($filter_branch) $stmt_vip->bindValue(':branch', $filter_branch);
$stmt_vip->execute();
$vip_customers = $stmt_vip->fetchAll(PDO::FETCH_ASSOC);

// 2. Retention (New vs Returning) this month
// Definition: A returning customer is someone who has had a transaction BEFORE this month.
$sql_all_this_month = "
    SELECT DISTINCT customer_name 
    FROM transactions t 
    WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE()) AND status = 'Paid' $where_branch_tx
";
$stmt_atm = $pdo->prepare($sql_all_this_month);
if ($filter_branch) $stmt_atm->bindValue(':branch', $filter_branch);
$stmt_atm->execute();
$customers_this_month = $stmt_atm->fetchAll(PDO::FETCH_COLUMN);

$returning = 0;
$new = 0;

if (!empty($customers_this_month)) {
    foreach($customers_this_month as $cname) {
        $stmt_check = $pdo->prepare("
            SELECT COUNT(*) FROM transactions t 
            WHERE customer_name = :name AND status = 'Paid' AND created_at < DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01') $where_branch_tx
        ");
        $stmt_check->bindValue(':name', $cname);
        if ($filter_branch) $stmt_check->bindValue(':branch', $filter_branch);
        $stmt_check->execute();
        $past_visits = $stmt_check->fetchColumn();
        
        if ($past_visits > 0) {
            $returning++;
        } else {
            $new++;
        }
    }
}
$total_unique = $returning + $new;
$retention_rate = $total_unique > 0 ? round(($returning / $total_unique) * 100) : 0;

?>
<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50 relative">
    <header class="h-16 lg:h-20 bg-white/50 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 z-40">
        <div class="flex items-center gap-3">
            <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full'); document.getElementById('sidebar-overlay').classList.toggle('hidden');" class="p-2 lg:hidden bg-slate-100 text-slate-600 rounded-xl">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div class="hidden lg:flex w-10 h-10 bg-fuchsia-50 rounded-xl items-center justify-center text-fuchsia-600">
                <i data-lucide="users" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="text-lg lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Marketing & Pelanggan</h1>
                <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-0.5">Analisis Retensi & Pelanggan VIP</p>
            </div>
        </div>
        
        <form method="GET" class="flex items-center gap-2">
            <select name="branch_id" class="px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-sm font-bold text-slate-700 outline-none focus:ring-4 focus:ring-fuchsia-500/10">
                <option value="">Semua Cabang (Global)</option>
                <?php foreach($branches as $b): ?>
                    <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="p-2.5 bg-fuchsia-600 text-white rounded-xl hover:bg-fuchsia-700 transition-colors shadow-lg shadow-fuchsia-500/20">
                <i data-lucide="filter" class="w-4 h-4"></i>
            </button>
        </form>
    </header>

    <div class="flex-1 overflow-y-auto p-4 lg:p-8 custom-scrollbar">
        <div class="max-w-7xl mx-auto space-y-6">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Retention Metric -->
                <div class="bg-gradient-to-br from-fuchsia-600 to-purple-700 p-6 rounded-[2rem] shadow-lg shadow-fuchsia-500/20 relative overflow-hidden text-white">
                    <div class="relative z-10">
                        <p class="text-[10px] font-black uppercase tracking-widest text-fuchsia-200 mb-2">Tingkat Retensi (Bulan Ini)</p>
                        <div class="flex items-end gap-3 mb-2">
                            <h3 class="text-5xl font-black tracking-tighter"><?php echo $retention_rate; ?>%</h3>
                        </div>
                        <p class="text-[10px] text-fuchsia-100 font-medium">
                            <?php echo $returning; ?> pelanggan lama kembali dari total <?php echo $total_unique; ?> pelanggan bulan ini.
                        </p>
                    </div>
                    <i data-lucide="heart-handshake" class="absolute -right-4 -bottom-4 w-32 h-32 text-white/10 transform rotate-12"></i>
                </div>

                <!-- Breakdown New vs Returning -->
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-col justify-center">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-900 mb-6 flex items-center gap-2">
                        <i data-lucide="pie-chart" class="w-4 h-4 text-fuchsia-500"></i> Proporsi Pelanggan (Bulan Ini)
                    </h3>
                    <div class="space-y-4">
                        <?php 
                        $pct_ret = $total_unique > 0 ? round(($returning / $total_unique) * 100) : 0;
                        $pct_new = $total_unique > 0 ? round(($new / $total_unique) * 100) : 0;
                        ?>
                        <div>
                            <div class="flex justify-between text-xs font-bold text-slate-700 mb-1">
                                <span>Pelanggan Lama (Returning)</span>
                                <span><?php echo $returning; ?> Org (<?php echo $pct_ret; ?>%)</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                                <div class="bg-fuchsia-500 h-2.5 rounded-full" style="width: <?php echo $pct_ret; ?>%"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs font-bold text-slate-700 mb-1">
                                <span>Pelanggan Baru (New)</span>
                                <span><?php echo $new; ?> Org (<?php echo $pct_new; ?>%)</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                                <div class="bg-blue-500 h-2.5 rounded-full" style="width: <?php echo $pct_new; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- VIP Customers -->
            <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                <h3 class="text-xs font-black uppercase tracking-widest text-slate-900 mb-6 flex items-center gap-2">
                    <i data-lucide="crown" class="w-4 h-4 text-amber-500"></i> Top 10 Pelanggan VIP (Total Belanja Terbesar)
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach($vip_customers as $idx => $v): ?>
                    <div class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-100 hover:border-amber-200 transition-colors">
                        <div class="w-10 h-10 rounded-xl <?php echo $idx < 3 ? 'bg-amber-100 text-amber-600' : 'bg-slate-200 text-slate-500'; ?> font-black flex items-center justify-center shrink-0">
                            #<?php echo $idx + 1; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="text-sm font-bold text-slate-900 truncate"><?php echo htmlspecialchars($v['customer_name'] ?: 'Guest'); ?></h4>
                            <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider"><?php echo $v['visit_count']; ?>x Kunjungan</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-black text-fuchsia-600"><?php echo short_rupiah($v['total_spent']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($vip_customers)): ?>
                        <div class="col-span-full text-center py-10 text-slate-400">
                            <i data-lucide="inbox" class="w-12 h-12 mx-auto mb-3 opacity-20"></i>
                            <p class="text-xs font-bold uppercase tracking-widest">Belum ada data pelanggan</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</main>
<script>lucide.createIcons();</script>
<?php include '../../includes/footer.php'; ?>
