<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Owner, Manager Ops, dan SPV yang boleh akses laporan
if (!has_role(['owner', 'manager_ops', 'spv'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Laporan Keuangan & Laba Rugi';
$filter_year = $_GET['year'] ?? date('Y');

// SPV cabang: branch_id terkunci ke cabangnya, tidak bisa ganti
$spv_branch = get_branch_filter();
$filter_branch = $spv_branch ?: ($_GET['branch_id'] ?? '');

// ── AMBIL DATA CABANG UNTUK FILTER ──────────────────────────────
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();

// ── QUERY DATA PER BULAN ────────────────────────────────────────
$months_data = [];
for ($m = 1; $m <= 12; $m++) {
    $months_data[$m] = [
        'month_name' => date('M', mktime(0, 0, 0, $m, 1)),
        'revenue'    => 0,
        'expense'    => 0,
        'profit'     => 0
    ];
}

// 1. Ambil Pemasukan (Revenue)
$sql_rev = "SELECT MONTH(created_at) as m, SUM(total_amount) as total 
            FROM transactions 
            WHERE YEAR(created_at) = ? AND status = 'Paid'";
$param_rev = [$filter_year];
if ($filter_branch) {
    $sql_rev .= " AND branch_id = ?";
    $param_rev[] = $filter_branch;
}
$sql_rev .= " GROUP BY MONTH(created_at)";

$stmt_rev = $pdo->prepare($sql_rev);
$stmt_rev->execute($param_rev);
while ($row = $stmt_rev->fetch()) {
    $months_data[$row['m']]['revenue'] = (int)$row['total'];
}

// 2. Ambil Pengeluaran (Expense)
$sql_exp = "SELECT MONTH(expense_date) as m, SUM(amount) as total 
            FROM expenses 
            WHERE YEAR(expense_date) = ?";
$param_exp = [$filter_year];
if ($filter_branch) {
    $sql_exp .= " AND branch_id = ?";
    $param_exp[] = $filter_branch;
}
$sql_exp .= " GROUP BY MONTH(expense_date)";

$stmt_exp = $pdo->prepare($sql_exp);
$stmt_exp->execute($param_exp);
while ($row = $stmt_exp->fetch()) {
    $months_data[$row['m']]['expense'] = (int)$row['total'];
}

// 3. Kalkulasi Laba
$total_revenue_year = 0;
$total_expense_year = 0;

foreach ($months_data as $m => $data) {
    $months_data[$m]['profit'] = $data['revenue'] - $data['expense'];
    $total_revenue_year += $data['revenue'];
    $total_expense_year += $data['expense'];
}
$net_profit_year = $total_revenue_year - $total_expense_year;

// Siapkan Data untuk Chart.js
$chart_labels = json_encode(array_column($months_data, 'month_name'));
$chart_revenue = json_encode(array_column($months_data, 'revenue'));
$chart_expense = json_encode(array_column($months_data, 'expense'));
$chart_profit  = json_encode(array_column($months_data, 'profit'));
?>

<?php include '../includes/header.php'; ?>
<!-- Tambahkan library Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
    <!-- Topbar -->
    <header class="h-16 sm:h-20 flex-shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu"></i>
        </button>

        <div class="flex-1 lg:ml-0 px-4">
            <h1 class="text-sm sm:text-lg font-semibold text-slate-900 truncate uppercase tracking-widest opacity-60">
                Laporan Keuangan
            </h1>
        </div>
        
        <div class="flex items-center gap-3">
            <button onclick="exportFinanceData()" class="bg-slate-900 border border-slate-900 text-white px-4 py-2.5 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-slate-800 transition-all flex items-center gap-2 shadow-lg shadow-slate-900/20 active:scale-95">
                <i data-lucide="download" class="w-4 h-4"></i> Export Laporan
            </button>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar bg-slate-50">
        
        <div class="max-w-7xl mx-auto space-y-6">

            <!-- FILTER BAR -->
            <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4 print:hidden">
                <div class="flex items-center gap-2 text-slate-500 font-semibold text-sm">
                    <i data-lucide="calendar" class="w-4 h-4"></i> Filter Laporan
                    <?php if (is_spv_branch()): ?>
                    <span class="ml-2 px-3 py-1 bg-indigo-50 text-indigo-700 text-[10px] font-black uppercase tracking-widest rounded-full border border-indigo-100">
                        <i data-lucide="map-pin" class="w-3 h-3 inline-block mr-1"></i>
                        <?php echo htmlspecialchars(get_spv_branch_label()); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <form action="" method="GET" class="flex items-center gap-3 w-full sm:w-auto">
                    <?php if (!is_spv_branch()): ?>
                    <select name="branch_id" class="px-4 py-2 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold flex-1 sm:w-48">
                        <option value="">Semua Cabang</option>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($filter_branch); ?>">
                    <?php endif; ?>
                    <select name="year" class="px-4 py-2 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold w-28">
                        <?php for($y=date('Y')-2; $y<=date('Y'); $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="bg-slate-900 text-white px-5 py-2 rounded-xl text-xs font-black uppercase tracking-widest hover:bg-slate-800 transition-colors">Terapkan</button>
                </form>
            </div>

            <!-- SUMMARY CARDS -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Pemasukan -->
                <div class="bg-white p-6 rounded-3xl shadow-lg border border-slate-100 relative overflow-hidden group">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
                    <div class="relative z-10">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 flex items-center gap-1.5"><i data-lucide="trending-up" class="w-3.5 h-3.5 text-emerald-500"></i> Total Pemasukan Kotor</p>
                        <h3 class="text-3xl font-black text-slate-900 tracking-tighter"><?php echo short_rupiah($total_revenue_year); ?></h3>
                    </div>
                </div>
                
                <!-- Pengeluaran -->
                <div class="bg-white p-6 rounded-3xl shadow-lg border border-slate-100 relative overflow-hidden group">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-rose-50 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
                    <div class="relative z-10">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 flex items-center gap-1.5"><i data-lucide="trending-down" class="w-3.5 h-3.5 text-rose-500"></i> Total Pengeluaran (Beban)</p>
                        <h3 class="text-3xl font-black text-slate-900 tracking-tighter"><?php echo short_rupiah($total_expense_year); ?></h3>
                    </div>
                </div>

                <!-- Laba Bersih -->
                <div class="bg-gradient-to-br from-blue-600 to-indigo-700 p-6 rounded-3xl shadow-xl shadow-blue-500/20 relative overflow-hidden group text-white">
                    <i data-lucide="wallet" class="absolute -right-4 -bottom-4 w-32 h-32 text-white/10 rotate-12 group-hover:rotate-0 transition-transform duration-500"></i>
                    <div class="relative z-10">
                        <p class="text-[10px] font-black text-blue-200 uppercase tracking-widest mb-2">Laba Bersih (Net Profit)</p>
                        <h3 class="text-3xl font-black tracking-tighter"><?php echo short_rupiah($net_profit_year); ?></h3>
                    </div>
                </div>
            </div>

            <!-- CHART & TABLE GRID -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- CHART -->
                <div class="lg:col-span-2 bg-white p-6 rounded-3xl shadow-lg border border-slate-100">
                    <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest mb-6">Grafik Laba Rugi <?php echo $filter_year; ?></h3>
                    <div class="h-80 w-full relative">
                        <canvas id="financeChart"></canvas>
                    </div>
                </div>

                <!-- TABLE DETAIL -->
                <div class="lg:col-span-1 bg-white rounded-3xl shadow-lg border border-slate-100 overflow-hidden flex flex-col h-[400px]">
                    <div class="p-6 border-b border-slate-100 bg-slate-50">
                        <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">Detail Per Bulan</h3>
                    </div>
                    <div class="flex-1 overflow-y-auto custom-scrollbar p-0">
                        <table class="w-full text-left">
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($months_data as $m => $d): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-4">
                                        <p class="font-black text-slate-900 text-xs uppercase tracking-widest"><?php echo $d['month_name']; ?></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <?php if ($d['profit'] > 0): ?>
                                            <span class="text-emerald-600 font-black text-sm">+ <?php echo short_rupiah($d['profit']); ?></span>
                                        <?php elseif ($d['profit'] < 0): ?>
                                            <span class="text-rose-600 font-black text-sm"><?php echo short_rupiah($d['profit']); ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-400 font-bold text-sm">Rp 0</span>
                                        <?php endif; ?>
                                        <div class="text-[9px] text-slate-400 font-semibold mt-1">
                                            In: <?php echo short_rupiah($d['revenue']); ?> | Out: <?php echo short_rupiah($d['expense']); ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>
    </div>
</main>

<script>
    // Inisialisasi Chart.js
    const ctx = document.getElementById('financeChart').getContext('2d');
    
    // Setup Data dari PHP
    const labels = <?php echo $chart_labels; ?>;
    const dataRevenue = <?php echo $chart_revenue; ?>;
    const dataExpense = <?php echo $chart_expense; ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Pemasukan',
                    data: dataRevenue,
                    backgroundColor: '#10b981', // emerald-500
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.6,
                    categoryPercentage: 0.8
                },
                {
                    label: 'Pengeluaran',
                    data: dataExpense,
                    backgroundColor: '#f43f5e', // rose-500
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.6,
                    categoryPercentage: 0.8
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index',
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        font: { family: "'Inter', sans-serif", weight: 'bold', size: 11 }
                    }
                },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleFont: { family: "'Inter', sans-serif", size: 13 },
                    bodyFont: { family: "'Inter', sans-serif", size: 13, weight: 'bold' },
                    padding: 12,
                    cornerRadius: 12,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) { label += ': '; }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9', drawBorder: false },
                    ticks: {
                        font: { family: "'Inter', sans-serif", size: 10, weight: 'bold' },
                        color: '#94a3b8',
                        callback: function(value) {
                            if (value >= 1000000) return 'Rp ' + (value / 1000000) + ' Jt';
                            if (value >= 1000) return 'Rp ' + (value / 1000) + ' Rb';
                            return value;
                        }
                    }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: {
                        font: { family: "'Inter', sans-serif", size: 10, weight: 'bold' },
                        color: '#64748b'
                    }
                }
            }
        }
    });

    const financeDataRaw = <?php echo json_encode($months_data); ?>;
    const currentYear = '<?php echo $filter_year; ?>';
    const currentBranchName = '<?php 
        $bname = "Semua_Cabang";
        if($filter_branch) {
            foreach($branches as $b) {
                if($b['id'] == $filter_branch) $bname = str_replace(" ", "_", $b['name']);
            }
        }
        echo $bname;
    ?>';

    function exportFinanceData() {
        let csvContent = "data:text/csv;charset=utf-8,";
        csvContent += "Bulan,Total Pemasukan (Rp),Total Pengeluaran (Rp),Laba Bersih (Rp)\n";
        
        for (const [m, data] of Object.entries(financeDataRaw)) {
            let row = [
                data.month_name,
                data.revenue,
                data.expense,
                data.profit
            ];
            csvContent += row.join(",") + "\n";
        }
        
        const totalRev = <?php echo $total_revenue_year; ?>;
        const totalExp = <?php echo $total_expense_year; ?>;
        const netProf = <?php echo $net_profit_year; ?>;
        
        csvContent += `\nTOTAL,${totalRev},${totalExp},${netProf}\n`;

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", `Laporan_Keuangan_${currentYear}_${currentBranchName}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
</script>

<?php include '../includes/footer.php'; ?>
