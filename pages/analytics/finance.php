<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
auth_ready();

if (!has_role(['owner', 'manager_ops'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Analytics - Keuangan Cabang';

// Setup Branch Filter — Default ke cabang sendiri jika SPV
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$spv_branch = (is_spv() && !empty($_SESSION['branch_id'])) ? $_SESSION['branch_id'] : '';
$filter_branch = $_GET['branch_id'] ?? $spv_branch;
$where_branch = $filter_branch ? "AND t.branch_id = :branch" : "";
$where_exp = $filter_branch ? "AND e.branch_id = :branch" : "";

// 1. Total Revenue
$sql_rev = "SELECT SUM(total_amount) FROM transactions t WHERE t.status = 'Paid' $where_branch";
$stmt_rev = $pdo->prepare($sql_rev);
if ($filter_branch) $stmt_rev->bindValue(':branch', $filter_branch);
$stmt_rev->execute();
$total_revenue = $stmt_rev->fetchColumn() ?: 0;

// 2. Total Expense
$sql_exp = "SELECT SUM(amount) FROM expenses e WHERE 1=1 $where_exp";
$stmt_exp = $pdo->prepare($sql_exp);
if ($filter_branch) $stmt_exp->bindValue(':branch', $filter_branch);
$stmt_exp->execute();
$total_expense = $stmt_exp->fetchColumn() ?: 0;

$net_profit = $total_revenue - $total_expense;

// 3. Revenue by Branch
$rev_by_branch = $pdo->query("
    SELECT b.name, SUM(t.total_amount) as total 
    FROM transactions t 
    JOIN branches b ON t.branch_id = b.id 
    WHERE t.status = 'Paid' 
    GROUP BY b.id 
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Sales Ratio (Service vs Parts)
$sql_ratio = "
    SELECT c.category, SUM(ti.qty * ti.price_at_sale) as total
    FROM transaction_items ti
    JOIN transactions t ON ti.transaction_id = t.id
    JOIN catalog c ON ti.catalog_id = c.id
    WHERE t.status = 'Paid' $where_branch
    GROUP BY c.category
";
$stmt_ratio = $pdo->prepare($sql_ratio);
if ($filter_branch) $stmt_ratio->bindValue(':branch', $filter_branch);
$stmt_ratio->execute();
$ratio_data = $stmt_ratio->fetchAll(PDO::FETCH_ASSOC);

// 5. Top 5 Selling Items
$sql_top = "
    SELECT c.name, c.category, SUM(ti.qty) as qty_sold, SUM(ti.qty * ti.price_at_sale) as revenue
    FROM transaction_items ti
    JOIN transactions t ON ti.transaction_id = t.id
    JOIN catalog c ON ti.catalog_id = c.id
    WHERE t.status = 'Paid' $where_branch
    GROUP BY ti.catalog_id
    ORDER BY revenue DESC LIMIT 5
";
$stmt_top = $pdo->prepare($sql_top);
if ($filter_branch) $stmt_top->bindValue(':branch', $filter_branch);
$stmt_top->execute();
$top_items = $stmt_top->fetchAll(PDO::FETCH_ASSOC);

?>
<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50 relative">
    <header class="h-16 lg:h-20 bg-white/50 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 z-40">
        <div class="flex items-center gap-3">
            <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full'); document.getElementById('sidebar-overlay').classList.toggle('hidden');" class="p-2 lg:hidden bg-slate-100 text-slate-600 rounded-xl">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div class="hidden lg:flex w-10 h-10 bg-indigo-50 rounded-xl items-center justify-center text-indigo-600">
                <i data-lucide="line-chart" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="text-lg lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Kinerja Keuangan</h1>
                <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-0.5">Analisis Pendapatan & Profitabilitas</p>
            </div>
        </div>
        
        <form method="GET" class="flex items-center gap-2">
            <select name="branch_id" class="px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-sm font-bold text-slate-700 outline-none focus:ring-4 focus:ring-indigo-500/10">
                <option value="">Semua Cabang (Global)</option>
                <?php foreach($branches as $b): ?>
                    <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="p-2.5 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-500/20">
                <i data-lucide="filter" class="w-4 h-4"></i>
            </button>
        </form>
    </header>

    <div class="flex-1 overflow-y-auto p-4 lg:p-8 custom-scrollbar">
        <div class="max-w-7xl mx-auto space-y-6">
            
            <!-- KPI Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm relative overflow-hidden group hover:shadow-md transition-all">
                    <div class="relative z-10">
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Total Pendapatan</p>
                        <h3 class="text-3xl font-black text-slate-900 tracking-tighter"><?php echo short_rupiah($total_revenue); ?></h3>
                    </div>
                    <i data-lucide="arrow-up-right" class="absolute -right-4 -bottom-4 w-24 h-24 text-emerald-50 group-hover:text-emerald-100 transition-colors transform rotate-12"></i>
                </div>
                
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm relative overflow-hidden group hover:shadow-md transition-all">
                    <div class="relative z-10">
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Total Pengeluaran</p>
                        <h3 class="text-3xl font-black text-slate-900 tracking-tighter"><?php echo short_rupiah($total_expense); ?></h3>
                    </div>
                    <i data-lucide="arrow-down-right" class="absolute -right-4 -bottom-4 w-24 h-24 text-red-50 group-hover:text-red-100 transition-colors transform rotate-12"></i>
                </div>
                
                <div class="bg-gradient-to-br from-indigo-600 to-blue-700 p-6 rounded-[2rem] shadow-lg shadow-indigo-500/20 relative overflow-hidden">
                    <div class="relative z-10">
                        <p class="text-[10px] font-black uppercase tracking-widest text-indigo-200 mb-2">Laba Bersih (Net Profit)</p>
                        <h3 class="text-3xl font-black text-white tracking-tighter"><?php echo short_rupiah($net_profit); ?></h3>
                    </div>
                    <i data-lucide="wallet" class="absolute -right-2 -bottom-2 w-24 h-24 text-white/10 transform rotate-12"></i>
                </div>
            </div>

            <!-- Detailed Analysis -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Ratio & Branch Comparison -->
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <h3 class="text-xs font-black uppercase tracking-widest text-slate-900 mb-6 flex items-center gap-2">
                            <i data-lucide="pie-chart" class="w-4 h-4 text-indigo-500"></i> Rasio Pendapatan (Jasa vs Part)
                        </h3>
                        <div class="space-y-4">
                            <?php foreach($ratio_data as $r): 
                                $pct = $total_revenue > 0 ? round(($r['total'] / $total_revenue) * 100) : 0;
                                $color = $r['category'] == 'Service' ? 'bg-blue-500' : 'bg-emerald-500';
                            ?>
                            <div>
                                <div class="flex justify-between text-xs font-bold text-slate-700 mb-1">
                                    <span><?php echo $r['category']; ?></span>
                                    <span><?php echo short_rupiah($r['total']); ?> (<?php echo $pct; ?>%)</span>
                                </div>
                                <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                                    <div class="<?php echo $color; ?> h-2.5 rounded-full" style="width: <?php echo $pct; ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if(!$filter_branch): ?>
                    <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                        <h3 class="text-xs font-black uppercase tracking-widest text-slate-900 mb-6 flex items-center gap-2">
                            <i data-lucide="building-2" class="w-4 h-4 text-indigo-500"></i> Pendapatan Per Cabang
                        </h3>
                        <div class="space-y-4">
                            <?php 
                            $max_rev = !empty($rev_by_branch) ? max(array_column($rev_by_branch, 'total')) : 1;
                            foreach($rev_by_branch as $rb): 
                                $pct = round(($rb['total'] / $max_rev) * 100);
                            ?>
                            <div>
                                <div class="flex justify-between text-xs font-bold text-slate-700 mb-1">
                                    <span><?php echo htmlspecialchars($rb['name']); ?></span>
                                    <span><?php echo short_rupiah($rb['total']); ?></span>
                                </div>
                                <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                                    <div class="bg-indigo-500 h-2.5 rounded-full" style="width: <?php echo $pct; ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Top Selling Items -->
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-900 mb-6 flex items-center gap-2">
                        <i data-lucide="award" class="w-4 h-4 text-amber-500"></i> Top 5 Penjualan Terbesar
                    </h3>
                    <div class="space-y-4">
                        <?php foreach($top_items as $idx => $item): ?>
                        <div class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-100 hover:border-amber-200 transition-colors">
                            <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 font-black flex items-center justify-center shrink-0">
                                #<?php echo $idx + 1; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="text-sm font-bold text-slate-900 truncate"><?php echo htmlspecialchars($item['name']); ?></h4>
                                <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider"><?php echo $item['category']; ?> • Terjual: <?php echo $item['qty_sold']; ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-black text-indigo-600"><?php echo short_rupiah($item['revenue']); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($top_items)): ?>
                            <div class="text-center py-10 text-slate-400">
                                <i data-lucide="inbox" class="w-12 h-12 mx-auto mb-3 opacity-20"></i>
                                <p class="text-xs font-bold uppercase tracking-widest">Belum ada data penjualan</p>
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
