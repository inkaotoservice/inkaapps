<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Role Guard
if (!has_role(['admin', 'admin_depok', 'admin_bsd', 'owner', 'spv'])) {
    header("Location: " . BASE_URL . "index.php"); 
    exit();
}

$page_title = 'Laporan Shift Kasir';
$user_branch_id = $_SESSION['branch_id'] ?? null;
$role = get_role();
$today = date('Y-m-d');

// Get current branch name
$branch_name = 'Semua Cabang';
if ($user_branch_id) {
    $stmt_b = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
    $stmt_b->execute([$user_branch_id]);
    $branch_name = $stmt_b->fetchColumn() ?: 'Cabang';
} else if (has_role(['owner', 'spv'])) {
    $branch_name = 'Semua Cabang (Global)';
}

// ── EXPORT EXCEL LOGIC ──────────────────────────────────────────────
if (isset($_GET['export'])) {
    $sql_export = "
        SELECT 
            t.id as transaction_id,
            t.customer_name,
            t.total_amount,
            t.created_at,
            b.customer_phone,
            b.car_model,
            b.license_plate,
            b.created_at as booking_created_at,
            br.name as transaction_branch_name
        FROM transactions t
        LEFT JOIN bookings b ON t.booking_id = b.id
        LEFT JOIN branches br ON t.branch_id = br.id
        WHERE t.status = 'Paid'
    ";
    
    $params_ex = [];
    $export_type = $_GET['export'];
    $filename_suffix = '';

    if ($export_type === 'daily') {
        $sql_export .= " AND DATE(t.created_at) = ?";
        $params_ex[] = $today;
        $filename_suffix = $today;
    } elseif ($export_type === 'monthly') {
        $m = $_GET['month'] ?? date('m');
        $y = $_GET['year'] ?? date('Y');
        $sql_export .= " AND MONTH(t.created_at) = ? AND YEAR(t.created_at) = ?";
        $params_ex[] = $m;
        $params_ex[] = $y;
        $months_id = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $filename_suffix = "Bulan_" . $months_id[(int)$m] . "_" . $y;
    }
    
    if (!has_role(['owner', 'spv']) && $user_branch_id) {
        $sql_export .= " AND t.branch_id = ?";
        $params_ex[] = $user_branch_id;
    }
    
    $sql_export .= " ORDER BY t.created_at ASC";

    $stmt_ex = $pdo->prepare($sql_export);
    $stmt_ex->execute($params_ex);
    $exports = $stmt_ex->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all related items
    $tx_ids = array_column($exports, 'transaction_id');
    $items_by_tx = [];
    if (!empty($tx_ids)) {
        $in  = str_repeat('?,', count($tx_ids) - 1) . '?';
        $sql_items = "SELECT ti.transaction_id, ti.price_at_sale, c.name 
                      FROM transaction_items ti 
                      LEFT JOIN catalog c ON ti.catalog_id = c.id 
                      WHERE ti.transaction_id IN ($in)";
        $stmt_items = $pdo->prepare($sql_items);
        $stmt_items->execute($tx_ids);
        while ($row = $stmt_items->fetch(PDO::FETCH_ASSOC)) {
            $items_by_tx[$row['transaction_id']][] = $row['name'] . " (" . number_format((float)$row['price_at_sale'], 0, ',', '.') . ")";
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Laporan_Kasir_' . str_replace(' ', '_', $branch_name) . '_' . $filename_suffix . '.csv');
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 Excel support
    fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));

    fputcsv($output, [
        'Nama Pelanggan', 
        'No. WhatsApp', 
        'Merek / Tipe Mobil', 
        'Nomor Polisi', 
        'Waktu Transaksi', 
        'Cabang Aktif', 
        'Service yang dilakukan', 
        'Total Transaksi'
    ]);

    foreach ($exports as $row) {
        $services = isset($items_by_tx[$row['transaction_id']]) ? implode(', ', $items_by_tx[$row['transaction_id']]) : '-';
        // Always use transaction created_at for accurate financial reporting time
        $waktu_transaksi = date('d F Y H:i', strtotime($row['created_at']));
        
        fputcsv($output, [
            $row['customer_name'] ?: '-',
            $row['customer_phone'] ?: '-',
            $row['car_model'] ?: '-',
            $row['license_plate'] ?: '-',
            $waktu_transaksi,
            $row['transaction_branch_name'] ?: $branch_name,
            $services,
            (float)$row['total_amount']
        ]);
    }
    
    fclose($output);
    exit();
}

// ── GET DASHBOARD DATA (TODAY) ─────────────────────────────────────────
$sql_stats = "SELECT total_amount, payment_method FROM transactions WHERE DATE(created_at) = ? AND status = 'Paid'";
$params_stats = [$today];

if (!has_role(['owner', 'spv']) && $user_branch_id) {
    $sql_stats .= " AND branch_id = ?";
    $params_stats[] = $user_branch_id;
}

$stmt_stats = $pdo->prepare($sql_stats);
$stmt_stats->execute($params_stats);
$transactions = $stmt_stats->fetchAll(PDO::FETCH_ASSOC);

$totalTransactions = count($transactions);
$totalRevenue = 0;
$totalCash = 0;
$totalTransfer = 0;
$totalQris = 0;
foreach ($transactions as $t) {
    $amt = (float)$t['total_amount'];
    $totalRevenue += $amt;
    if ($t['payment_method'] === 'Cash') $totalCash += $amt;
    elseif ($t['payment_method'] === 'Transfer') $totalTransfer += $amt;
    elseif ($t['payment_method'] === 'QRIS') $totalQris += $amt;
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative bg-slate-50">
    <!-- Topbar -->
    <header class="h-16 sm:h-20 flex-shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu"></i>
        </button>

        <div class="flex-1 lg:ml-0 px-4 flex items-center gap-3">
            <div class="p-2 bg-emerald-600 text-white rounded-xl shadow-lg shadow-emerald-200 hidden sm:flex">
                <i data-lucide="clipboard-list" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="text-sm sm:text-lg font-black text-slate-900 tracking-tight">Laporan Shift Kasir</h1>
                <p class="text-[10px] sm:text-xs text-slate-500 font-medium">Ringkasan & Export Laporan Keuangan.</p>
            </div>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar">
        <div class="max-w-4xl mx-auto space-y-8 pb-10">
            
            <div class="flex flex-col md:flex-row justify-between gap-6 print-hidden">
                <div>
                    <h2 class="text-2xl font-black text-slate-900 tracking-tight">Laporan Shift Kasir</h2>
                    <p class="text-sm text-slate-500 mt-1 font-medium">Ringkasan penerimaan kasir hari ini di <?php echo htmlspecialchars($branch_name); ?></p>
                </div>
                
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    <!-- Export Bulanan -->
                    <form action="shift-report.php" method="GET" class="flex bg-white border border-slate-200 p-1.5 rounded-2xl shadow-sm items-center gap-2">
                        <input type="hidden" name="export" value="monthly">
                        <select name="month" class="px-3 py-2 rounded-xl bg-slate-50 border-none outline-none text-xs font-bold text-slate-700">
                            <?php 
                            $months = [
                                '01'=>'Januari', '02'=>'Februari', '03'=>'Maret', '04'=>'April', 
                                '05'=>'Mei', '06'=>'Juni', '07'=>'Juli', '08'=>'Agustus', 
                                '09'=>'September', '10'=>'Oktober', '11'=>'November', '12'=>'Desember'
                            ];
                            foreach($months as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php echo date('m') === $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="year" class="px-3 py-2 rounded-xl bg-slate-50 border-none outline-none text-xs font-bold text-slate-700">
                            <?php for($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo date('Y') == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest transition-all shadow-md flex items-center gap-2">
                            <i data-lucide="calendar" class="w-4 h-4"></i> Export Bulanan
                        </button>
                    </form>

                    <!-- Export Hari Ini -->
                    <a href="shift-report.php?export=daily" class="inline-flex items-center justify-center bg-emerald-600 hover:bg-emerald-700 text-white shadow-lg shadow-emerald-500/30 h-12 px-6 rounded-2xl font-black text-xs uppercase tracking-widest transition-all active:scale-95 gap-2">
                        <i data-lucide="file-text" class="w-4 h-4"></i> Export Hari Ini
                    </a>
                </div>
            </div>

            <div class="space-y-6">
                <!-- Main Card -->
                <div class="p-6 border-none shadow-xl bg-gradient-to-br from-indigo-600 to-blue-700 text-white rounded-3xl overflow-hidden relative print-card">
                    <div class="relative z-10">
                        <p class="text-[10px] font-black uppercase tracking-widest text-blue-200 mb-2">Total Penerimaan Hari Ini</p>
                        <h3 class="text-4xl font-black tracking-tighter italic">
                            <?php echo rupiah($totalRevenue); ?>
                        </h3>
                        <p class="mt-4 font-medium text-blue-100 flex items-center gap-2">
                            <i data-lucide="calendar-clock" class="w-4 h-4"></i>
                            <?php 
                                $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
                                $months = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                                echo $days[date('w')] . ', ' . date('d') . ' ' . $months[date('n')] . ' ' . date('Y');
                            ?>
                        </p>
                    </div>
                    <i data-lucide="dollar-sign" class="w-48 h-48 absolute -right-6 -bottom-6 text-white/10 transform rotate-12"></i>
                </div>

                <!-- Sub Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 print-hidden">
                    <div class="p-5 border-none shadow-md bg-white flex items-center gap-4 hover:shadow-lg transition-all rounded-2xl">
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-xl">
                            <i data-lucide="wallet" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Uang Masuk</p>
                            <p class="text-lg font-black text-slate-900 tracking-tight"><?php echo rupiah($totalRevenue); ?></p>
                        </div>
                    </div>

                    <div class="p-5 border-none shadow-md bg-white flex items-center gap-4 hover:shadow-lg transition-all rounded-2xl">
                        <div class="p-3 bg-amber-50 text-amber-600 rounded-xl">
                            <i data-lucide="clipboard-list" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Transaksi (Nota)</p>
                            <p class="text-lg font-black text-slate-900 tracking-tight"><?php echo number_format($totalTransactions); ?> Nota</p>
                        </div>
                    </div>
                </div>

                <!-- Breakdown Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 print-hidden">
                    <!-- Cash -->
                    <div class="p-5 bg-white border border-slate-100 shadow-sm rounded-2xl hover:border-blue-200 transition-all flex flex-col justify-center items-center text-center">
                        <div class="w-10 h-10 bg-green-50 text-green-600 rounded-xl flex items-center justify-center mb-3 shadow-inner">
                            <i data-lucide="banknote" class="w-5 h-5"></i>
                        </div>
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Penerimaan Cash</p>
                        <p class="text-xl font-black text-slate-900"><?php echo rupiah($totalCash); ?></p>
                        <p class="text-[9px] text-slate-400 font-medium mt-1">Uang Fisik di Kasir</p>
                    </div>

                    <!-- Transfer -->
                    <div class="p-5 bg-white border border-slate-100 shadow-sm rounded-2xl hover:border-blue-200 transition-all flex flex-col justify-center items-center text-center">
                        <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center mb-3 shadow-inner">
                            <i data-lucide="credit-card" class="w-5 h-5"></i>
                        </div>
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Penerimaan Transfer</p>
                        <p class="text-xl font-black text-slate-900"><?php echo rupiah($totalTransfer); ?></p>
                        <p class="text-[9px] text-slate-400 font-medium mt-1">Mutasi Rekening Bank</p>
                    </div>

                    <!-- QRIS -->
                    <div class="p-5 bg-white border border-slate-100 shadow-sm rounded-2xl hover:border-blue-200 transition-all flex flex-col justify-center items-center text-center">
                        <div class="w-10 h-10 bg-purple-50 text-purple-600 rounded-xl flex items-center justify-center mb-3 shadow-inner">
                            <i data-lucide="scan-line" class="w-5 h-5"></i>
                        </div>
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Penerimaan QRIS</p>
                        <p class="text-xl font-black text-slate-900"><?php echo rupiah($totalQris); ?></p>
                        <p class="text-[9px] text-slate-400 font-medium mt-1">Merchant EDC / GoBiz</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    .print-card, .print-card * {
        visibility: visible;
    }
    .print-card {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 40px;
    }
    .print-hidden {
        display: none !important;
    }
}
</style>

<?php include '../includes/footer.php'; ?>
