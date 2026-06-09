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

if (isset($_POST['save_slip'])) {
    try {
        $pdo->beginTransaction();
        
        $slip_id = uuid();
        $employee_id = $_POST['employee_id'];
        $period_month = (int)$_POST['period_month'];
        $period_year = (int)$_POST['period_year'];
        
        // Cek apakah slip untuk bulan & tahun ini sudah ada
        $stmt = $pdo->prepare("SELECT id FROM salary_slips WHERE employee_id = ? AND period_month = ? AND period_year = ?");
        $stmt->execute([$employee_id, $period_month, $period_year]);
        if ($stmt->fetch()) {
            throw new Exception("Slip gaji untuk karyawan ini pada periode tersebut sudah ada.");
        }
        
        // Ambil komponen dari form (sudah dalam bentuk raw tanpa Rp)
        $basic_salary = preg_replace('/[^0-9]/', '', $_POST['basic_salary'] ?? '0');
        $qty_days_present = (int)($_POST['qty_days_present'] ?? 0);
        $daily_allowance_total = preg_replace('/[^0-9]/', '', $_POST['daily_allowance_total'] ?? '0');
        
        $qty_overtime_hours = (int)($_POST['qty_overtime_hours'] ?? 0);
        $overtime_total = preg_replace('/[^0-9]/', '', $_POST['overtime_total'] ?? '0');
        
        $qty_late_minutes = (int)($_POST['qty_late_minutes'] ?? 0);
        $late_penalty_total = preg_replace('/[^0-9]/', '', $_POST['late_penalty_total'] ?? '0');
        
        $qty_absent_days = (int)($_POST['qty_absent_days'] ?? 0);
        $absence_penalty_total = preg_replace('/[^0-9]/', '', $_POST['absence_penalty_total'] ?? '0');
        
        $bpjs_tk_deduction = preg_replace('/[^0-9]/', '', $_POST['bpjs_tk_deduction'] ?? '0');
        $bpjs_deduction = preg_replace('/[^0-9]/', '', $_POST['bpjs_deduction'] ?? '0');
        
        $gross_salary = preg_replace('/[^0-9]/', '', $_POST['gross_salary'] ?? '0');
        
        $deduction_kasbon = preg_replace('/[^0-9]/', '', $_POST['deduction_kasbon'] ?? '0');
        $deduction_tabungan = preg_replace('/[^0-9]/', '', $_POST['deduction_tabungan'] ?? '0');
        $deduction_lain = preg_replace('/[^0-9]/', '', $_POST['deduction_lain'] ?? '0');
        
        $total_deductions = preg_replace('/[^0-9]/', '', $_POST['total_deductions'] ?? '0');
        $net_salary = preg_replace('/[^0-9]/', '', $_POST['net_salary'] ?? '0');
        
        $sisa_cuti = (int)($_POST['remaining_leave_after'] ?? 0);
        
        // Update Sisa Kasbon jika ada potongan
        $remaining_loan_after = 0;
        if ($deduction_kasbon > 0) {
            // Catat ke employee_loans
            $loan_id = uuid();
            $pdo->prepare("INSERT INTO employee_loans (id, employee_id, type, amount, date, description, salary_slip_id) VALUES (?, ?, 'potongan_gaji', ?, ?, ?, ?)")
                ->execute([$loan_id, $employee_id, $deduction_kasbon, date('Y-m-d'), "Potongan Slip Gaji Bulan $period_month/$period_year", $slip_id]);
                
            // Kurangi sisa kasbon di employees
            $pdo->prepare("UPDATE employees SET remaining_loan = remaining_loan - ?, remaining_leave = ? WHERE id = ?")
                ->execute([$deduction_kasbon, $sisa_cuti, $employee_id]);
        } else {
            $pdo->prepare("UPDATE employees SET remaining_leave = ? WHERE id = ?")
                ->execute([$sisa_cuti, $employee_id]);
        }
        
        // Ambil sisa loan terbaru
        $stmt = $pdo->prepare("SELECT remaining_loan FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $remaining_loan_after = $stmt->fetchColumn() ?: 0;
        
        // Insert Salary Slip
        $stmt = $pdo->prepare("
            INSERT INTO salary_slips (
                id, employee_id, period_month, period_year, basic_salary, qty_days_present, daily_allowance_total,
                qty_overtime_hours, overtime_total, qty_late_minutes, late_penalty_total, qty_absent_days, absence_penalty_total,
                bpjs_tk_deduction, bpjs_deduction, gross_salary, deduction_kasbon, deduction_tabungan, deduction_lain,
                total_deductions, net_salary, remaining_leave_after, remaining_loan_after, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $slip_id, $employee_id, $period_month, $period_year, $basic_salary, $qty_days_present, $daily_allowance_total,
            $qty_overtime_hours, $overtime_total, $qty_late_minutes, $late_penalty_total, $qty_absent_days, $absence_penalty_total,
            $bpjs_tk_deduction, $bpjs_deduction, $gross_salary, $deduction_kasbon, $deduction_tabungan, $deduction_lain,
            $total_deductions, $net_salary, $sisa_cuti, $remaining_loan_after, $_SESSION['user_id']
        ]);
        
        $pdo->commit();
        $_SESSION['flash_msg'] = ['type'=>'success', 'msg'=>'Slip Gaji berhasil dibuat!'];
        header("Location: hrd-payroll.php");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Gagal membuat slip gaji: " . $e->getMessage();
    }
}

// Fetch all employees for JS to load their base data
$employees = $pdo->query("SELECT * FROM employees ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$employees_json = json_encode($employees);

$months = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$page_title = 'Buat Slip Gaji Baru';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50 relative">
    <header class="h-16 lg:h-20 bg-white/50 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 z-40 shrink-0">
        <div class="flex items-center gap-3">
            <a href="hrd-payroll.php" class="w-8 h-8 flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl transition-colors">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
            </a>
            <div>
                <h1 class="text-lg lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Kalkulator Penggajian</h1>
                <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-0.5 lg:mt-1">Pembuatan slip gaji otomatis</p>
            </div>
        </div>
    </header>

    <div class="flex-1 overflow-y-auto p-4 lg:p-8">
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-100 text-red-600 p-4 rounded-2xl flex items-center gap-3 text-sm font-semibold mb-6">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="payrollForm" class="max-w-5xl mx-auto space-y-6" onsubmit="return confirm('Apakah Anda yakin data ini sudah benar? Setelah disimpan, potongan kasbon akan langsung memotong sisa pinjaman karyawan.');">
            
            <!-- HEADER (Karyawan & Periode) -->
            <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm p-6 lg:p-8">
                <h3 class="font-black text-slate-800 uppercase tracking-widest text-xs mb-6 flex items-center gap-2">
                    <i data-lucide="user-check" class="w-4 h-4 text-blue-500"></i> Pilih Karyawan & Periode
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="md:col-span-1">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Karyawan</label>
                        <select name="employee_id" id="empSelect" required onchange="loadEmployeeData()" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-bold text-sm text-slate-700">
                            <option value="">-- Pilih Karyawan --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="empPosition" class="text-[10px] font-semibold text-slate-400 mt-2 h-4 uppercase tracking-widest"></p>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Bulan</label>
                        <select name="period_month" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-bold text-sm text-slate-700">
                            <?php foreach($months as $m => $m_name): ?>
                            <option value="<?php echo $m; ?>" <?php echo ($m == date('n')) ? 'selected' : ''; ?>><?php echo $m_name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Tahun</label>
                        <select name="period_year" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-bold text-sm text-slate-700">
                            <?php for($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($y == date('Y')) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- RINCIAN GAJI (Kalkulator) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 relative">
                
                <!-- KIRI: PENDAPATAN -->
                <div class="space-y-6">
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-6 py-5 border-b border-slate-100 bg-emerald-50/50">
                            <h3 class="font-black text-slate-800 uppercase tracking-widest text-xs flex items-center gap-2">
                                <i data-lucide="plus-circle" class="w-4 h-4 text-emerald-500"></i> Rincian Pendapatan
                            </h3>
                        </div>
                        <div class="p-6 space-y-5">
                            
                            <!-- Gaji Pokok -->
                            <div class="flex items-center gap-4">
                                <div class="flex-1">
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Gaji Pokok</label>
                                    <p class="text-xs font-semibold text-slate-400">Bulanan Tetap</p>
                                </div>
                                <div class="w-48 relative">
                                    <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                    <input type="text" name="basic_salary" id="basic_salary" value="0" readonly class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-100 border-none font-bold text-sm text-slate-700 text-right">
                                </div>
                            </div>

                            <!-- Uang Harian -->
                            <div class="flex items-center gap-4">
                                <div class="flex-1">
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Uang Harian</label>
                                    <p class="text-[10px] font-semibold text-slate-400" id="daily_rate_label">Rp 0 / Hari</p>
                                    <input type="hidden" id="daily_allowance_rate" value="0">
                                </div>
                                <div class="w-24">
                                    <div class="relative">
                                        <input type="number" name="qty_days_present" id="qty_days_present" value="0" min="0" oninput="calculatePayroll()" class="w-full px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 focus:border-emerald-500 outline-none font-bold text-sm text-emerald-700 text-center" placeholder="Hari">
                                        <span class="absolute right-3 top-3.5 text-[10px] font-bold text-emerald-600 uppercase">Hr</span>
                                    </div>
                                </div>
                                <div class="w-40 relative">
                                    <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                    <input type="text" name="daily_allowance_total" id="daily_allowance_total" value="0" readonly class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-100 border-none font-bold text-sm text-slate-700 text-right">
                                </div>
                            </div>

                            <!-- Lembur -->
                            <div class="flex items-center gap-4">
                                <div class="flex-1">
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Lembur</label>
                                    <p class="text-[10px] font-semibold text-slate-400" id="overtime_rate_label">Rp 0 / Jam</p>
                                    <input type="hidden" id="overtime_rate" value="0">
                                </div>
                                <div class="w-24">
                                    <div class="relative">
                                        <input type="number" name="qty_overtime_hours" id="qty_overtime_hours" value="0" min="0" oninput="calculatePayroll()" class="w-full px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 focus:border-emerald-500 outline-none font-bold text-sm text-emerald-700 text-center" placeholder="Jam">
                                        <span class="absolute right-3 top-3.5 text-[10px] font-bold text-emerald-600 uppercase">Jm</span>
                                    </div>
                                </div>
                                <div class="w-40 relative">
                                    <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                    <input type="text" name="overtime_total" id="overtime_total" value="0" readonly class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-100 border-none font-bold text-sm text-slate-700 text-right">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KANAN: POTONGAN -->
                <div class="space-y-6">
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-6 py-5 border-b border-slate-100 bg-red-50/50">
                            <h3 class="font-black text-slate-800 uppercase tracking-widest text-xs flex items-center gap-2">
                                <i data-lucide="minus-circle" class="w-4 h-4 text-red-500"></i> Rincian Potongan
                            </h3>
                        </div>
                        <div class="p-6 space-y-5">
                            
                            <!-- Terlambat -->
                            <div class="flex items-center gap-4">
                                <div class="flex-1">
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Terlambat</label>
                                    <p class="text-[10px] font-semibold text-slate-400" id="late_penalty_label">Rp 0 / Menit</p>
                                    <input type="hidden" id="late_penalty_rate" value="0">
                                </div>
                                <div class="w-24">
                                    <div class="relative">
                                        <input type="number" name="qty_late_minutes" id="qty_late_minutes" value="0" min="0" oninput="calculatePayroll()" class="w-full px-4 py-3 rounded-xl bg-red-50 border border-red-200 focus:border-red-500 outline-none font-bold text-sm text-red-700 text-center" placeholder="Min">
                                        <span class="absolute right-3 top-3.5 text-[10px] font-bold text-red-600 uppercase">Mt</span>
                                    </div>
                                </div>
                                <div class="w-40 relative">
                                    <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                    <input type="text" name="late_penalty_total" id="late_penalty_total" value="0" readonly class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-100 border-none font-bold text-sm text-red-600 text-right">
                                </div>
                            </div>

                            <!-- Tidak Hadir -->
                            <div class="flex items-center gap-4">
                                <div class="flex-1">
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Tidak Hadir</label>
                                    <p class="text-[10px] font-semibold text-slate-400" id="absent_penalty_label">Rp 0 / Hari</p>
                                    <input type="hidden" id="absence_penalty_rate" value="0">
                                </div>
                                <div class="w-24">
                                    <div class="relative">
                                        <input type="number" name="qty_absent_days" id="qty_absent_days" value="0" min="0" oninput="calculatePayroll()" class="w-full px-4 py-3 rounded-xl bg-red-50 border border-red-200 focus:border-red-500 outline-none font-bold text-sm text-red-700 text-center" placeholder="Hari">
                                        <span class="absolute right-3 top-3.5 text-[10px] font-bold text-red-600 uppercase">Hr</span>
                                    </div>
                                </div>
                                <div class="w-40 relative">
                                    <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                    <input type="text" name="absence_penalty_total" id="absence_penalty_total" value="0" readonly class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-100 border-none font-bold text-sm text-red-600 text-right">
                                </div>
                            </div>

                            <!-- BPJSTK & BPJS -->
                            <div class="grid grid-cols-2 gap-4 pt-4 border-t border-slate-100">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Iuran BPJSTK</label>
                                    <div class="relative">
                                        <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                        <input type="text" name="bpjs_tk_deduction" id="bpjs_tk_deduction" value="0" readonly class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-100 border-none font-bold text-sm text-red-600 text-right">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Iuran BPJS</label>
                                    <div class="relative">
                                        <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                        <input type="text" name="bpjs_deduction" id="bpjs_deduction" value="0" readonly class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-100 border-none font-bold text-sm text-red-600 text-right">
                                    </div>
                                </div>
                            </div>

                            <!-- Potongan Manual -->
                            <div class="space-y-4 pt-4 border-t border-slate-100">
                                <div class="flex items-center gap-4">
                                    <div class="flex-1">
                                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Potongan Kasbon</label>
                                        <p class="text-[10px] font-bold text-slate-400" id="remaining_loan_label">Sisa Kasbon: Rp 0</p>
                                    </div>
                                    <div class="w-48 relative">
                                        <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                        <input type="text" name="deduction_kasbon" id="deduction_kasbon" value="0" onkeyup="this.value = formatRupiah(this.value); calculatePayroll();" class="w-full pl-10 pr-4 py-3 rounded-xl bg-white border border-slate-200 focus:border-red-500 outline-none font-bold text-sm text-red-600 text-right">
                                    </div>
                                </div>
                                <div class="flex items-center gap-4">
                                    <div class="flex-1">
                                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Tabungan</label>
                                    </div>
                                    <div class="w-48 relative">
                                        <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                        <input type="text" name="deduction_tabungan" id="deduction_tabungan" value="0" onkeyup="this.value = formatRupiah(this.value); calculatePayroll();" class="w-full pl-10 pr-4 py-3 rounded-xl bg-white border border-slate-200 focus:border-red-500 outline-none font-bold text-sm text-red-600 text-right">
                                    </div>
                                </div>
                                <div class="flex items-center gap-4">
                                    <div class="flex-1">
                                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Lain-lain</label>
                                    </div>
                                    <div class="w-48 relative">
                                        <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                                        <input type="text" name="deduction_lain" id="deduction_lain" value="0" onkeyup="this.value = formatRupiah(this.value); calculatePayroll();" class="w-full pl-10 pr-4 py-3 rounded-xl bg-white border border-slate-200 focus:border-red-500 outline-none font-bold text-sm text-red-600 text-right">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- HASIL AKHIR -->
            <div class="bg-gradient-to-br from-slate-800 to-slate-900 rounded-[2rem] border border-slate-700 shadow-xl overflow-hidden mb-6 p-6 lg:p-8">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-12">
                    
                    <div class="text-center md:text-left">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">TOTAL GAJI KOTOR</p>
                        <div class="relative">
                            <input type="text" name="gross_salary" id="gross_salary" value="0" readonly class="w-full bg-transparent border-none text-2xl lg:text-3xl font-black text-white p-0 m-0 outline-none md:text-left text-center">
                        </div>
                    </div>
                    
                    <div class="text-center md:text-left border-t md:border-t-0 md:border-l border-slate-700 pt-6 md:pt-0 md:pl-12">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">TOTAL POTONGAN</p>
                        <div class="relative">
                            <input type="text" name="total_deductions" id="total_deductions" value="0" readonly class="w-full bg-transparent border-none text-2xl lg:text-3xl font-black text-red-400 p-0 m-0 outline-none md:text-left text-center">
                        </div>
                    </div>
                    
                    <div class="text-center md:text-left border-t md:border-t-0 md:border-l border-slate-700 pt-6 md:pt-0 md:pl-12">
                        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-widest mb-1">TOTAL GAJI BERSIH</p>
                        <div class="relative">
                            <input type="text" name="net_salary" id="net_salary" value="0" readonly class="w-full bg-transparent border-none text-3xl lg:text-5xl font-black text-emerald-400 p-0 m-0 outline-none md:text-left text-center">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sisa Cuti -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6 flex justify-between items-center mb-10">
                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest">Update Sisa Cuti (Hari)</label>
                <div class="w-32">
                    <input type="number" name="remaining_leave_after" id="remaining_leave_after" value="0" min="0" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none font-bold text-lg text-slate-700 text-center">
                </div>
            </div>

            <div class="flex justify-end pt-4 pb-20">
                <button type="submit" name="save_slip" id="btnSave" disabled class="px-10 py-4 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white rounded-xl font-black text-sm uppercase tracking-widest shadow-xl shadow-blue-500/30 transition-all active:scale-95 flex items-center gap-2">
                    <i data-lucide="check-circle" class="w-5 h-5"></i> Simpan Slip Gaji
                </button>
            </div>
            
        </form>
    </div>
</main>

<script>
    lucide.createIcons();
    
    const employeesData = <?php echo $employees_json; ?>;
    
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
    
    function getCleanInt(val) {
        if (!val) return 0;
        return parseInt(val.toString().replace(/[^0-9]/g, '')) || 0;
    }

    function loadEmployeeData() {
        const empId = document.getElementById('empSelect').value;
        const btnSave = document.getElementById('btnSave');
        
        if (!empId) {
            btnSave.disabled = true;
            return;
        }
        
        btnSave.disabled = false;
        const emp = employeesData.find(e => e.id === empId);
        if (!emp) return;
        
        document.getElementById('empPosition').textContent = emp.position + ' - ' + (emp.branch_id ? 'Cabang' : 'Pusat');
        
        // Pendapatan Base
        document.getElementById('basic_salary').value = formatRupiah(emp.basic_salary);
        
        document.getElementById('daily_allowance_rate').value = emp.daily_allowance;
        document.getElementById('daily_rate_label').textContent = 'Rp ' + formatRupiah(emp.daily_allowance) + ' / Hari';
        
        document.getElementById('overtime_rate').value = emp.overtime_rate;
        document.getElementById('overtime_rate_label').textContent = 'Rp ' + formatRupiah(emp.overtime_rate) + ' / Jam';
        
        // Potongan Base
        document.getElementById('late_penalty_rate').value = emp.late_penalty_per_minute;
        document.getElementById('late_penalty_label').textContent = 'Rp ' + formatRupiah(emp.late_penalty_per_minute) + ' / Menit';
        
        document.getElementById('absence_penalty_rate').value = emp.absence_penalty_per_day;
        document.getElementById('absent_penalty_label').textContent = 'Rp ' + formatRupiah(emp.absence_penalty_per_day) + ' / Hari';
        
        document.getElementById('bpjs_tk_deduction').value = formatRupiah(emp.bpjs_tk_deduction);
        document.getElementById('bpjs_deduction').value = formatRupiah(emp.bpjs_deduction);
        
        document.getElementById('remaining_loan_label').textContent = 'Sisa Kasbon: Rp ' + formatRupiah(emp.remaining_loan);
        document.getElementById('remaining_leave_after').value = emp.remaining_leave;
        
        // Reset inputs
        document.getElementById('qty_days_present').value = 0;
        document.getElementById('qty_overtime_hours').value = 0;
        document.getElementById('qty_late_minutes').value = 0;
        document.getElementById('qty_absent_days').value = 0;
        document.getElementById('deduction_kasbon').value = 0;
        document.getElementById('deduction_tabungan').value = 0;
        document.getElementById('deduction_lain').value = 0;
        
        calculatePayroll();
    }
    
    function calculatePayroll() {
        const basic = getCleanInt(document.getElementById('basic_salary').value);
        
        const qtyDays = parseInt(document.getElementById('qty_days_present').value) || 0;
        const dailyRate = parseInt(document.getElementById('daily_allowance_rate').value) || 0;
        const dailyTotal = qtyDays * dailyRate;
        document.getElementById('daily_allowance_total').value = formatRupiah(dailyTotal);
        
        const qtyOvertime = parseInt(document.getElementById('qty_overtime_hours').value) || 0;
        const overtimeRate = parseInt(document.getElementById('overtime_rate').value) || 0;
        const overtimeTotal = qtyOvertime * overtimeRate;
        document.getElementById('overtime_total').value = formatRupiah(overtimeTotal);
        
        // Calculate Gross (before absence & late deductions? Actually late and absent are usually deductions before gross or after gross. Let's make Gross = basic + allowances, then net = gross - all deductions)
        // In the image, "Tidak hadir" is listed under earnings section but without total, wait, it's just listed.
        // Let's standard: Gross = Pendapatan. Deductions = Potongan.
        
        const qtyLate = parseInt(document.getElementById('qty_late_minutes').value) || 0;
        const lateRate = parseInt(document.getElementById('late_penalty_rate').value) || 0;
        const lateTotal = qtyLate * lateRate;
        document.getElementById('late_penalty_total').value = formatRupiah(lateTotal);
        
        const qtyAbsent = parseInt(document.getElementById('qty_absent_days').value) || 0;
        const absentRate = parseInt(document.getElementById('absence_penalty_rate').value) || 0;
        const absentTotal = qtyAbsent * absentRate;
        document.getElementById('absence_penalty_total').value = formatRupiah(absentTotal);
        
        // Actually, based on image, BPJS and Tidak Hadir are in the first section (before TOTAL GAJI KOTOR). 
        // So Gross = Basic + Daily + Overtime - Late - Absent - BPJS.
        const bpjstk = getCleanInt(document.getElementById('bpjs_tk_deduction').value);
        const bpjs = getCleanInt(document.getElementById('bpjs_deduction').value);
        
        const grossSalary = basic + dailyTotal + overtimeTotal - lateTotal - absentTotal - bpjstk - bpjs;
        document.getElementById('gross_salary').value = formatRupiah(grossSalary < 0 ? 0 : grossSalary);
        
        const dedKasbon = getCleanInt(document.getElementById('deduction_kasbon').value);
        const dedTabungan = getCleanInt(document.getElementById('deduction_tabungan').value);
        const dedLain = getCleanInt(document.getElementById('deduction_lain').value);
        
        const totalDeductions = dedKasbon + dedTabungan + dedLain;
        document.getElementById('total_deductions').value = formatRupiah(totalDeductions);
        
        const netSalary = (grossSalary < 0 ? 0 : grossSalary) - totalDeductions;
        document.getElementById('net_salary').value = formatRupiah(netSalary < 0 ? 0 : netSalary);
    }
</script>
</body>
</html>
