<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

auth_ready();

if (!in_array(get_role(), ['owner', 'manager_ops'])) {
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$success = '';
$error = '';

if (isset($_POST['save_loan'])) {
    $employee_id = $_POST['employee_id'];
    $type = $_POST['type']; // 'kasbon' or 'potongan_gaji'
    $amount = (int)preg_replace('/[^0-9]/', '', $_POST['amount'] ?? '0');
    $date = $_POST['date'];
    $description = trim($_POST['description']);
    
    if ($amount > 0) {
        try {
            $pdo->beginTransaction();
            
            $id = uuid();
            $stmt = $pdo->prepare("INSERT INTO employee_loans (id, employee_id, type, amount, date, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $employee_id, $type, $amount, $date, $description]);
            
            // Pastikan row employees ada
            $check = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
            $check->execute([$employee_id]);
            if (!$check->fetch()) {
                $pdo->prepare("INSERT INTO employees (id, remaining_loan) VALUES (?, 0)")->execute([$employee_id]);
            }
            
            if ($type === 'kasbon') {
                $pdo->prepare("UPDATE employees SET remaining_loan = remaining_loan + ? WHERE id = ?")->execute([$amount, $employee_id]);
            } else {
                $pdo->prepare("UPDATE employees SET remaining_loan = remaining_loan - ? WHERE id = ?")->execute([$amount, $employee_id]);
            }
            
            $pdo->commit();
            $success = "Transaksi kasbon berhasil disimpan.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Gagal menyimpan data: " . $e->getMessage();
        }
    } else {
        $error = "Nominal harus lebih dari 0.";
    }
}

if (isset($_POST['delete_loan'])) {
    $loan_id = $_POST['loan_id'];
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM employee_loans WHERE id = ?");
        $stmt->execute([$loan_id]);
        $loan = $stmt->fetch();
        
        if ($loan && !$loan['salary_slip_id']) {
            // Pastikan row employees ada
            $check = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
            $check->execute([$loan['employee_id']]);
            if (!$check->fetch()) {
                $pdo->prepare("INSERT INTO employees (id, remaining_loan) VALUES (?, 0)")->execute([$loan['employee_id']]);
            }
            
            if ($loan['type'] === 'kasbon') {
                $pdo->prepare("UPDATE employees SET remaining_loan = remaining_loan - ? WHERE id = ?")->execute([$loan['amount'], $loan['employee_id']]);
            } else {
                $pdo->prepare("UPDATE employees SET remaining_loan = remaining_loan + ? WHERE id = ?")->execute([$loan['amount'], $loan['employee_id']]);
            }
            $pdo->prepare("DELETE FROM employee_loans WHERE id = ?")->execute([$loan_id]);
            $pdo->commit();
            $success = "Transaksi berhasil dihapus.";
        } else {
            $pdo->rollBack();
            $error = "Transaksi tidak ditemukan atau sudah terikat dengan slip gaji.";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Gagal menghapus: " . $e->getMessage();
    }
}

// Fetch employees for select
$employees = $pdo->query("SELECT p.id, p.full_name as name, COALESCE(e.remaining_loan, 0) as remaining_loan FROM profiles p LEFT JOIN employees e ON p.id = e.id WHERE p.role != 'member' ORDER BY p.full_name")->fetchAll();

// Fetch recent loans
$loans = $pdo->query("SELECT el.*, p.full_name as emp_name FROM employee_loans el JOIN profiles p ON el.employee_id = p.id ORDER BY el.created_at DESC LIMIT 100")->fetchAll();

// Summary
$total_piutang = $pdo->query("SELECT SUM(remaining_loan) FROM employees")->fetchColumn() ?: 0;

$page_title = 'Kasbon Karyawan';
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
                <h1 class="text-lg lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Kasbon & Pinjaman</h1>
                <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-0.5 lg:mt-1">Riwayat pinjaman karyawan</p>
            </div>
        </div>
        <button onclick="openModal()" class="h-10 lg:h-12 px-4 lg:px-6 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest transition-all shadow-lg shadow-blue-500/30 active:scale-95 flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span class="hidden sm:inline">Transaksi Baru</span>
        </button>
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

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-3xl p-6 text-white shadow-lg shadow-red-500/30">
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-12 h-12 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-sm">
                        <i data-lucide="wallet" class="w-6 h-6 text-white"></i>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-red-100 uppercase tracking-widest">Total Piutang Kasbon</p>
                        <h3 class="text-2xl font-black tracking-tight mt-1"><?php echo rupiah($total_piutang); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2rem] shadow-sm overflow-hidden mb-8">
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="font-black text-slate-800 uppercase tracking-widest text-xs flex items-center gap-2">
                    <i data-lucide="users" class="w-4 h-4 text-blue-500"></i> Rekap Sisa Pinjaman per Karyawan
                </h3>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($employees as $emp): if ($emp['remaining_loan'] > 0): ?>
                <div class="border border-slate-200 rounded-2xl p-4 flex justify-between items-center">
                    <div>
                        <p class="font-bold text-slate-900"><?php echo htmlspecialchars($emp['name']); ?></p>
                        <p class="text-xs text-slate-400 mt-1">Sisa Pinjaman</p>
                    </div>
                    <div class="text-right">
                        <p class="font-black text-red-600 text-lg"><?php echo rupiah($emp['remaining_loan']); ?></p>
                    </div>
                </div>
                <?php endif; endforeach; ?>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2rem] shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50">
                <h3 class="font-black text-slate-800 uppercase tracking-widest text-xs flex items-center gap-2">
                    <i data-lucide="history" class="w-4 h-4 text-blue-500"></i> Riwayat Transaksi Terbaru
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400">Tanggal</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400">Karyawan</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400">Jenis</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400 text-right">Nominal</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400">Keterangan</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($loans)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-slate-400 font-medium">Belum ada riwayat transaksi.</td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php foreach ($loans as $loan): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors group">
                            <td class="px-6 py-4">
                                <p class="font-bold text-slate-700"><?php echo date('d M Y', strtotime($loan['date'])); ?></p>
                                <p class="text-[10px] text-slate-400"><?php echo date('H:i', strtotime($loan['created_at'])); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-bold text-slate-900"><?php echo htmlspecialchars($loan['emp_name']); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($loan['type'] === 'kasbon'): ?>
                                <span class="inline-flex items-center gap-1 px-3 py-1 bg-red-50 text-red-600 rounded-lg font-bold text-xs">
                                    <i data-lucide="arrow-up-right" class="w-3 h-3"></i> Ambil Kasbon
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-3 py-1 bg-emerald-50 text-emerald-600 rounded-lg font-bold text-xs">
                                    <i data-lucide="arrow-down-right" class="w-3 h-3"></i> Pembayaran/Potongan
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <p class="font-black <?php echo $loan['type'] === 'kasbon' ? 'text-red-600' : 'text-emerald-600'; ?>"><?php echo rupiah($loan['amount']); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600 max-w-[200px] truncate"><?php echo htmlspecialchars($loan['description']); ?></p>
                                <?php if ($loan['salary_slip_id']): ?>
                                <p class="text-[10px] text-blue-500 font-bold mt-1">Via Slip Gaji</p>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if (!$loan['salary_slip_id']): ?>
                                <form method="POST" onsubmit="return confirm('Hapus transaksi ini? Saldo sisa pinjaman akan disesuaikan kembali.');" class="inline opacity-0 group-hover:opacity-100 transition-opacity">
                                    <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                    <button type="submit" name="delete_loan" class="w-8 h-8 bg-slate-100 hover:bg-red-100 text-slate-400 hover:text-red-600 rounded-lg flex items-center justify-center transition-colors">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<div id="loanModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden items-center justify-center px-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden scale-95 transition-transform duration-300" id="loanModalContent">
        <form method="POST">
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="font-black text-slate-800 text-lg tracking-tight">Transaksi Kasbon Baru</h3>
                <button type="button" onclick="closeModal()" class="w-8 h-8 bg-white rounded-xl flex items-center justify-center text-slate-400 hover:text-red-500 shadow-sm transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            
            <div class="p-6 space-y-5">
                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Karyawan</label>
                    <select name="employee_id" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-bold text-sm text-slate-700">
                        <option value="">-- Pilih Karyawan --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?> (Sisa: <?php echo rupiah($emp['remaining_loan']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Jenis Transaksi</label>
                        <select name="type" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-bold text-sm text-slate-700">
                            <option value="kasbon">Ambil Kasbon</option>
                            <option value="potongan_gaji">Pembayaran Manual</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Tanggal</label>
                        <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-bold text-sm text-slate-700 uppercase">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Nominal (Rp)</label>
                    <div class="relative">
                        <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                        <input type="text" name="amount" required onkeyup="this.value = formatRupiah(this.value)"
                            class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none font-black text-lg text-slate-900">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Keterangan / Catatan</label>
                    <textarea name="description" rows="2" placeholder="Keperluan kasbon..." class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-semibold text-sm"></textarea>
                </div>
            </div>
            
            <div class="p-6 border-t border-slate-100 flex gap-3 justify-end bg-slate-50">
                <button type="button" onclick="closeModal()" class="px-6 py-3 rounded-xl font-bold text-xs uppercase tracking-widest text-slate-500 hover:bg-slate-200 transition-colors">Batal</button>
                <button type="submit" name="save_loan" class="px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest shadow-lg shadow-blue-500/30 transition-all active:scale-95 flex items-center gap-2">
                    <i data-lucide="save" class="w-4 h-4"></i> Simpan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    lucide.createIcons();
    
    function formatRupiah(angka) {
        if (!angka) return "0";
        let number_string = angka.toString().replace(/[^,\d]/g, ''),
        split   = number_string.split(','),
        sisa    = split[0].length % 3,
        rupiah  = split[0].substr(0, sisa),
        ribuan  = split[0].substr(sisa).match(/\d{3}/gi);

        if(ribuan){
            separator = sisa ? '.' : '';
            rupiah += separator + ribuan.join('.');
        }

        rupiah = split[1] != undefined ? rupiah + ',' + split[1] : rupiah;
        return rupiah;
    }

    const modal = document.getElementById('loanModal');
    const modalContent = document.getElementById('loanModalContent');
    
    function openModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modalContent.classList.remove('scale-95');
        }, 10);
    }
    
    function closeModal() {
        modal.classList.add('opacity-0');
        modalContent.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }, 300);
    }
</script>
</body>
</html>
