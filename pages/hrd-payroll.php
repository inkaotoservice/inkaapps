<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

auth_ready();

if (!in_array(get_role(), ['owner', 'manager_ops'])) {
    header("Location: " . BASE_URL . "index.php");
    exit();
}

// Handle Delete Slip
$success = '';
$error = '';
if (isset($_POST['delete_slip'])) {
    $slip_id = $_POST['slip_id'];
    try {
        $pdo->beginTransaction();
        
        // Cek apakah ada transaksi kasbon yang terikat dengan slip ini
        $stmt = $pdo->prepare("SELECT id, employee_id, amount FROM employee_loans WHERE salary_slip_id = ? AND type = 'potongan_gaji'");
        $stmt->execute([$slip_id]);
        $loan = $stmt->fetch();
        
        if ($loan) {
            // Kembalikan sisa pinjaman karyawan
            $pdo->prepare("UPDATE employees SET remaining_loan = remaining_loan + ? WHERE id = ?")->execute([$loan['amount'], $loan['employee_id']]);
            // Hapus transaksi pinjaman
            $pdo->prepare("DELETE FROM employee_loans WHERE id = ?")->execute([$loan['id']]);
        }
        
        // Hapus slip
        $pdo->prepare("DELETE FROM salary_slips WHERE id = ?")->execute([$slip_id]);
        
        $pdo->commit();
        $success = "Slip gaji berhasil dihapus. Potongan kasbon pada slip ini juga telah dikembalikan.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Gagal menghapus slip: " . $e->getMessage();
    }
}

// Ambil bulan dan tahun filter
$filter_month = !empty($_GET['month']) ? $_GET['month'] : date('n');
$filter_year = !empty($_GET['year']) ? $_GET['year'] : date('Y');

// Fetch slips
$stmt = $pdo->prepare("
    SELECT s.*, e.name as emp_name, e.position 
    FROM salary_slips s 
    JOIN employees e ON s.employee_id = e.id 
    WHERE s.period_month = ? AND s.period_year = ? 
    ORDER BY s.created_at DESC
");
$stmt->execute([$filter_month, $filter_year]);
$slips = $stmt->fetchAll();

// Bulan array
$months = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$page_title = 'Penggajian (Payroll)';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50 relative">
    <header class="h-16 lg:h-20 bg-white/50 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 z-40">
        <div class="flex items-center gap-3">
            <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full'); document.getElementById('sidebar-overlay').classList.toggle('hidden');" class="lg:hidden p-2 bg-slate-100 text-slate-600 rounded-xl">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="text-lg lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Penggajian</h1>
                <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-0.5 lg:mt-1">Riwayat Slip Gaji Bulanan</p>
            </div>
        </div>
        <a href="hrd-payroll-create.php" class="h-10 lg:h-12 px-4 lg:px-6 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest transition-all shadow-lg shadow-blue-500/30 active:scale-95 flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span class="hidden sm:inline">Buat Slip Gaji</span>
        </a>
    </header>

    <div class="flex-1 overflow-y-auto p-4 lg:p-8">
        <?php if ($success): ?>
        <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 p-4 rounded-2xl flex items-center gap-3 text-sm font-semibold mb-6">
            <i data-lucide="check-circle-2" class="w-5 h-5"></i>
            <?php echo $success; ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-100 text-red-600 p-4 rounded-2xl flex items-center gap-3 text-sm font-semibold mb-6">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <div class="bg-white border border-slate-200 rounded-[2rem] shadow-sm overflow-hidden mb-6 p-4">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Bulan</label>
                    <select name="month" class="px-4 py-2 rounded-xl bg-slate-50 border border-slate-200 outline-none font-bold text-sm text-slate-700">
                        <?php foreach($months as $m => $m_name): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($m == $filter_month) ? 'selected' : ''; ?>><?php echo $m_name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Tahun</label>
                    <select name="year" class="px-4 py-2 rounded-xl bg-slate-50 border border-slate-200 outline-none font-bold text-sm text-slate-700">
                        <?php for($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y == $filter_year) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="h-[38px] px-6 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl font-bold text-xs uppercase tracking-widest transition-colors flex items-center gap-2">
                    <i data-lucide="filter" class="w-4 h-4"></i> Filter
                </button>
            </form>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2rem] shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400">Periode</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400">Karyawan</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400 text-right">Gaji Kotor</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400 text-right">Potongan</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400 text-right">Gaji Bersih</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($slips)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-slate-400 font-medium">Belum ada slip gaji untuk periode ini.</td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php foreach ($slips as $slip): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors group">
                            <td class="px-6 py-4">
                                <p class="font-bold text-slate-900"><?php echo $months[$slip['period_month']] . ' ' . $slip['period_year']; ?></p>
                                <p class="text-[10px] text-slate-400">Dibuat: <?php echo date('d/m/Y', strtotime($slip['created_at'])); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-bold text-slate-900"><?php echo htmlspecialchars($slip['emp_name']); ?></p>
                                <p class="text-xs text-slate-500"><?php echo htmlspecialchars($slip['position'] ?? ''); ?></p>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <p class="font-bold text-slate-700"><?php echo rupiah($slip['gross_salary']); ?></p>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <p class="font-bold text-red-500"><?php echo rupiah($slip['total_deductions']); ?></p>
                                <?php if($slip['deduction_kasbon'] > 0): ?>
                                <p class="text-[9px] text-red-400 mt-0.5">Termasuk pot. kasbon</p>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <p class="font-black text-emerald-600 text-lg"><?php echo rupiah($slip['net_salary']); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="hrd-slip-print.php?id=<?php echo $slip['id']; ?>" target="_blank"
                                            class="w-8 h-8 bg-purple-50 hover:bg-purple-100 text-purple-600 rounded-lg flex items-center justify-center transition-colors tooltip" title="Cetak/Preview">
                                        <i data-lucide="printer" class="w-4 h-4"></i>
                                    </a>
                                    
                                    <?php
                                        // Generate WA Link
                                        $wa_text = "Halo *" . $slip['emp_name'] . "*,\nBerikut rincian gaji Anda untuk periode *" . $months[$slip['period_month']] . " " . $slip['period_year'] . "*.\n\n*Pendapatan*\nGaji Pokok: " . rupiah($slip['basic_salary']) . "\nUang Harian (" . $slip['qty_days_present'] . "h): " . rupiah($slip['daily_allowance_total']) . "\nLembur (" . $slip['qty_overtime_hours'] . "j): " . rupiah($slip['overtime_total']) . "\n\n*Potongan*\nTerlambat: -" . rupiah($slip['late_penalty_total']) . "\nTidak Hadir: -" . rupiah($slip['absence_penalty_total']) . "\nBPJSTK: -" . rupiah($slip['bpjs_tk_deduction']) . "\nBPJS: -" . rupiah($slip['bpjs_deduction']) . "\nKasbon: -" . rupiah($slip['deduction_kasbon']) . "\nTabungan: -" . rupiah($slip['deduction_tabungan']) . "\nLainnya: -" . rupiah($slip['deduction_lain']) . "\n\n*TOTAL GAJI BERSIH: " . rupiah($slip['net_salary']) . "*\n\nSisa Kasbon saat ini: " . rupiah($slip['remaining_loan_after']) . "\n\nTerima kasih atas kerja kerasnya!";
                                        $wa_url = "https://wa.me/?text=" . urlencode($wa_text);
                                    ?>
                                    <a href="<?php echo $wa_url; ?>" target="_blank"
                                            class="w-8 h-8 bg-[#25D366]/10 hover:bg-[#25D366]/20 text-[#25D366] rounded-lg flex items-center justify-center transition-colors tooltip" title="Kirim WA">
                                        <i data-lucide="send" class="w-4 h-4"></i>
                                    </a>

                                    <form method="POST" onsubmit="return confirm('Hapus slip gaji ini? Potongan kasbon di slip ini akan dikembalikan ke sisa pinjaman.');" class="inline">
                                        <input type="hidden" name="slip_id" value="<?php echo $slip['id']; ?>">
                                        <button type="submit" name="delete_slip" 
                                                class="w-8 h-8 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg flex items-center justify-center transition-colors">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
    lucide.createIcons();
</script>
</body>
</html>
