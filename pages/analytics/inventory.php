<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
auth_ready();

if (!has_role(['owner', 'manager_ops', 'spv'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Analytics - Status Inventori';

// Setup Branch Filter — Default ke cabang sendiri jika SPV
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$spv_branch = (is_spv() && !empty($_SESSION['branch_id'])) ? $_SESSION['branch_id'] : '';
$filter_branch = $_GET['branch_id'] ?? $spv_branch;
$where_branch_cat = $filter_branch ? "AND (c.branch_id = :branch_cat OR c.branch_id IS NULL)" : "";
$where_branch_tx = $filter_branch ? "AND t.branch_id = :branch_tx" : "";

// 1. Fast Moving Parts (Most Qty Sold this month)
$sql_fast = "
    SELECT c.name, SUM(ti.qty) as qty_sold, c.stock 
    FROM transaction_items ti
    JOIN transactions t ON ti.transaction_id = t.id
    JOIN catalog c ON ti.catalog_id = c.id
    WHERE c.category = 'Spare Part' AND MONTH(t.created_at) = MONTH(CURRENT_DATE()) AND t.status = 'Paid' $where_branch_tx
    GROUP BY c.id
    ORDER BY qty_sold DESC LIMIT 5
";
$stmt_fast = $pdo->prepare($sql_fast);
if ($filter_branch) $stmt_fast->bindValue(':branch_tx', $filter_branch);
$stmt_fast->execute();
$fast_parts = $stmt_fast->fetchAll(PDO::FETCH_ASSOC);

// 2. Low Stock Alerts (Stock < 5)
$sql_low = "
    SELECT name, stock, branch_id 
    FROM catalog c 
    WHERE category = 'Spare Part' AND stock < 5 AND is_active = 1 $where_branch_cat
    ORDER BY stock ASC LIMIT 10
";
$stmt_low = $pdo->prepare($sql_low);
if ($filter_branch) $stmt_low->bindValue(':branch_cat', $filter_branch);
$stmt_low->execute();
$low_stock = $stmt_low->fetchAll(PDO::FETCH_ASSOC);

// 3. Dead Stock (No sales in the last 30 days)
$sql_dead = "
    SELECT c.name, c.stock 
    FROM catalog c 
    WHERE c.category = 'Spare Part' AND c.is_active = 1 AND c.stock > 0 $where_branch_cat
    AND c.id NOT IN (
        SELECT ti.catalog_id 
        FROM transaction_items ti
        JOIN transactions t ON ti.transaction_id = t.id
        WHERE t.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) AND t.status = 'Paid' $where_branch_tx
    )
    ORDER BY c.stock DESC LIMIT 10
";
$stmt_dead = $pdo->prepare($sql_dead);
if ($filter_branch) {
    $stmt_dead->bindValue(':branch_cat', $filter_branch);
    $stmt_dead->bindValue(':branch_tx', $filter_branch);
}
$stmt_dead->execute();
$dead_stock = $stmt_dead->fetchAll(PDO::FETCH_ASSOC);

// Total items count
$stmt_total = $pdo->prepare("SELECT COUNT(*) FROM catalog c WHERE category = 'Spare Part' AND is_active = 1 $where_branch_cat");
if ($filter_branch) $stmt_total->bindValue(':branch_cat', $filter_branch);
$stmt_total->execute();
$total_items = $stmt_total->fetchColumn();

// Total stock value (Modal tertanam)
$stmt_value = $pdo->prepare("SELECT SUM(stock * cost_price) FROM catalog c WHERE category = 'Spare Part' AND is_active = 1 $where_branch_cat");
if ($filter_branch) $stmt_value->bindValue(':branch_cat', $filter_branch);
$stmt_value->execute();
$total_value = $stmt_value->fetchColumn() ?: 0;
?>
<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50 relative">
    <header class="h-16 lg:h-20 bg-white/50 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 z-40">
        <div class="flex items-center gap-3">
            <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full'); document.getElementById('sidebar-overlay').classList.toggle('hidden');" class="p-2 lg:hidden bg-slate-100 text-slate-600 rounded-xl">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div class="hidden lg:flex w-10 h-10 bg-amber-50 rounded-xl items-center justify-center text-amber-600">
                <i data-lucide="package" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="text-lg lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Status Inventori</h1>
                <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-0.5">Analisis Stok & Hambatan Barang</p>
            </div>
        </div>
        
        <form method="GET" class="flex items-center gap-2">
            <select name="branch_id" class="px-4 py-2.5 rounded-xl bg-white border border-slate-200 text-sm font-bold text-slate-700 outline-none focus:ring-4 focus:ring-amber-500/10">
                <option value="">Semua Cabang (Global)</option>
                <?php foreach($branches as $b): ?>
                    <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="p-2.5 bg-amber-500 text-white rounded-xl hover:bg-amber-600 transition-colors shadow-lg shadow-amber-500/20">
                <i data-lucide="filter" class="w-4 h-4"></i>
            </button>
        </form>
    </header>

    <div class="flex-1 overflow-y-auto p-4 lg:p-8 custom-scrollbar">
        <div class="max-w-7xl mx-auto space-y-6">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-gradient-to-br from-amber-500 to-orange-600 p-6 rounded-[2rem] shadow-lg shadow-amber-500/20 relative overflow-hidden text-white">
                    <div class="relative z-10">
                        <p class="text-[10px] font-black uppercase tracking-widest text-amber-100 mb-2">Nilai Modal Tertanam (Stok Fisik)</p>
                        <h3 class="text-3xl font-black tracking-tighter"><?php echo short_rupiah($total_value); ?></h3>
                        <p class="text-[10px] text-amber-200 mt-1">Tersebar di <?php echo $total_items; ?> jenis sparepart</p>
                    </div>
                    <i data-lucide="boxes" class="absolute -right-4 -bottom-4 w-24 h-24 text-white/10 transform rotate-12"></i>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Fast Moving -->
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-900 mb-4 flex items-center gap-2">
                        <i data-lucide="zap" class="w-4 h-4 text-emerald-500"></i> Fast Moving (Terlaris Bulan Ini)
                    </h3>
                    <div class="space-y-3">
                        <?php foreach($fast_parts as $p): ?>
                        <div class="flex justify-between items-center p-3 bg-slate-50 rounded-xl border border-slate-100">
                            <div>
                                <h4 class="text-sm font-bold text-slate-900 truncate max-w-[150px]"><?php echo htmlspecialchars($p['name']); ?></h4>
                                <p class="text-[10px] font-semibold text-slate-400">Sisa Stok: <?php echo $p['stock'] ?? 0; ?></p>
                            </div>
                            <div class="text-right">
                                <span class="bg-emerald-100 text-emerald-700 font-black text-[10px] px-2 py-1 rounded">Terjual <?php echo $p['qty_sold']; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($fast_parts)): ?>
                            <p class="text-[10px] text-slate-400 text-center italic py-4">Belum ada penjualan bulan ini.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Low Stock -->
                <div class="bg-white p-6 rounded-[2rem] border border-red-200 shadow-sm shadow-red-500/5">
                    <h3 class="text-xs font-black uppercase tracking-widest text-red-600 mb-4 flex items-center gap-2">
                        <i data-lucide="alert-triangle" class="w-4 h-4 text-red-500"></i> Stok Menipis (< 5)
                    </h3>
                    <div class="space-y-3">
                        <?php foreach($low_stock as $p): ?>
                        <div class="flex justify-between items-center p-3 bg-red-50 rounded-xl border border-red-100">
                            <div>
                                <h4 class="text-sm font-bold text-red-900 truncate max-w-[150px]"><?php echo htmlspecialchars($p['name']); ?></h4>
                            </div>
                            <div class="text-right flex items-center gap-2">
                                <span class="font-black text-red-600 text-sm"><?php echo $p['stock']; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($low_stock)): ?>
                            <p class="text-[10px] text-emerald-500 text-center font-bold py-4">Semua stok aman!</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dead Stock -->
                <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-900 mb-4 flex items-center gap-2">
                        <i data-lucide="x-circle" class="w-4 h-4 text-slate-400"></i> Dead Stock (30 Hari Terakhir)
                    </h3>
                    <p class="text-[9px] text-slate-400 font-semibold mb-3">Barang dengan stok ada namun tidak terjual sama sekali.</p>
                    <div class="space-y-3 max-h-[300px] overflow-y-auto custom-scrollbar pr-2">
                        <?php foreach($dead_stock as $p): ?>
                        <div class="flex justify-between items-center p-3 bg-slate-50 rounded-xl border border-slate-100 opacity-70 hover:opacity-100 transition-opacity">
                            <div>
                                <h4 class="text-sm font-bold text-slate-700 truncate max-w-[150px]"><?php echo htmlspecialchars($p['name']); ?></h4>
                            </div>
                            <div class="text-right flex items-center gap-2">
                                <span class="text-[10px] font-bold text-slate-500">Stok: <?php echo $p['stock']; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($dead_stock)): ?>
                            <p class="text-[10px] text-emerald-500 text-center font-bold py-4">Tidak ada dead stock!</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>
<script>lucide.createIcons();</script>
<?php include '../../includes/footer.php'; ?>
