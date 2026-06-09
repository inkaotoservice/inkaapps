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

// --- Handle Delete ---
if (isset($_POST['delete_employee'])) {
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Data karyawan berhasil dihapus.";
    } catch (Exception $e) {
        $error = "Gagal menghapus data: " . $e->getMessage();
    }
}

// --- Handle Save (Add/Edit) ---
if (isset($_POST['save_employee'])) {
    $id = $_POST['id'] ?? '';
    $name = trim($_POST['name']);
    $position = trim($_POST['position']);
    $branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;
    
    // Konversi format uang ke integer
    $basic_salary = (int)preg_replace('/[^0-9]/', '', $_POST['basic_salary'] ?? '0');
    $daily_allowance = (int)preg_replace('/[^0-9]/', '', $_POST['daily_allowance'] ?? '0');
    $overtime_rate = (int)preg_replace('/[^0-9]/', '', $_POST['overtime_rate'] ?? '0');
    $absence_penalty_per_day = (int)preg_replace('/[^0-9]/', '', $_POST['absence_penalty_per_day'] ?? '0');
    $late_penalty_per_minute = (int)preg_replace('/[^0-9]/', '', $_POST['late_penalty_per_minute'] ?? '0');
    $bpjs_tk_deduction = (int)preg_replace('/[^0-9]/', '', $_POST['bpjs_tk_deduction'] ?? '0');
    $bpjs_deduction = (int)preg_replace('/[^0-9]/', '', $_POST['bpjs_deduction'] ?? '0');
    
    $remaining_leave = (int)($_POST['remaining_leave'] ?? 0);

    try {
        if ($id) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE employees SET 
                name=?, position=?, branch_id=?, basic_salary=?, daily_allowance=?, 
                overtime_rate=?, absence_penalty_per_day=?, late_penalty_per_minute=?, 
                bpjs_tk_deduction=?, bpjs_deduction=?, remaining_leave=?
                WHERE id=?
            ");
            $stmt->execute([
                $name, $position, $branch_id, $basic_salary, $daily_allowance, 
                $overtime_rate, $absence_penalty_per_day, $late_penalty_per_minute, 
                $bpjs_tk_deduction, $bpjs_deduction, $remaining_leave, $id
            ]);
            $success = "Data karyawan berhasil diperbarui.";
        } else {
            // Insert
            $id = uuid();
            $stmt = $pdo->prepare("
                INSERT INTO employees (
                    id, name, position, branch_id, basic_salary, daily_allowance, 
                    overtime_rate, absence_penalty_per_day, late_penalty_per_minute, 
                    bpjs_tk_deduction, bpjs_deduction, remaining_leave
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id, $name, $position, $branch_id, $basic_salary, $daily_allowance, 
                $overtime_rate, $absence_penalty_per_day, $late_penalty_per_minute, 
                $bpjs_tk_deduction, $bpjs_deduction, $remaining_leave
            ]);
            $success = "Karyawan baru berhasil ditambahkan.";
        }
    } catch (Exception $e) {
        $error = "Gagal menyimpan data: " . $e->getMessage();
    }
}

// Fetch branches for dropdown
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();

// Fetch employees
$query = "SELECT e.*, b.name as branch_name FROM employees e LEFT JOIN branches b ON e.branch_id = b.id ORDER BY e.name ASC";
$employees = $pdo->query($query)->fetchAll();

$page_title = 'Data Karyawan';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50 relative">
    <!-- Topbar -->
    <header class="h-16 lg:h-20 bg-white/50 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 z-40">
        <div class="flex items-center gap-3">
            <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full'); document.getElementById('sidebar-overlay').classList.toggle('hidden');" class="lg:hidden p-2 bg-slate-100 text-slate-600 rounded-xl">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="text-lg lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Data Karyawan</h1>
                <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-0.5 lg:mt-1">Kelola data profil dan standar gaji</p>
            </div>
        </div>
        <button onclick="openModal()" class="h-10 lg:h-12 px-4 lg:px-6 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest transition-all shadow-lg shadow-blue-500/30 active:scale-95 flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span class="hidden sm:inline">Tambah Karyawan</span>
        </button>
    </header>

    <!-- Content -->
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

        <div class="bg-white border border-slate-200 rounded-[2rem] shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400">Nama Karyawan</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400">Posisi & Cabang</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400">Gaji Pokok</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400 text-center">Sisa Cuti</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400 text-right">Sisa Kasbon</th>
                            <th class="px-6 py-4 font-black text-[10px] uppercase tracking-widest text-slate-400 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-slate-400 font-medium">Belum ada data karyawan.</td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php foreach ($employees as $emp): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors group">
                            <td class="px-6 py-4">
                                <p class="font-bold text-slate-900"><?php echo htmlspecialchars($emp['name']); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-700"><?php echo htmlspecialchars($emp['position'] ?? ''); ?></p>
                                <p class="text-xs text-slate-400"><?php echo htmlspecialchars($emp['branch_name'] ?? 'Pusat'); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-bold text-slate-700"><?php echo rupiah($emp['basic_salary']); ?></p>
                                <p class="text-xs text-slate-400">Harian: <?php echo rupiah($emp['daily_allowance']); ?></p>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="inline-flex items-center justify-center px-3 py-1 bg-blue-50 text-blue-600 rounded-lg font-bold text-xs">
                                    <?php echo $emp['remaining_leave']; ?> Hari
                               </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <p class="font-bold <?php echo $emp['remaining_loan'] > 0 ? 'text-red-600' : 'text-slate-500'; ?>"><?php echo rupiah($emp['remaining_loan']); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button onclick="editEmployee(<?php echo htmlspecialchars(json_encode($emp)); ?>)" 
                                            class="w-8 h-8 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center transition-colors">
                                        <i data-lucide="edit" class="w-4 h-4"></i>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Hapus karyawan ini?');" class="inline">
                                        <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" name="delete_employee" 
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

<!-- Modal Add/Edit -->
<div id="employeeModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden items-center justify-center px-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden scale-95 transition-transform duration-300" id="employeeModalContent">
        <form method="POST">
            <input type="hidden" name="id" id="emp_id">
            
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="font-black text-slate-800 text-lg tracking-tight" id="modalTitle">Tambah Karyawan</h3>
                <button type="button" onclick="closeModal()" class="w-8 h-8 bg-white rounded-xl flex items-center justify-center text-slate-400 hover:text-red-500 shadow-sm transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            
            <div class="p-6 max-h-[70vh] overflow-y-auto custom-scrollbar">
                <!-- Info Dasar -->
                <h4 class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-4 flex items-center gap-2"><i data-lucide="user" class="w-3 h-3"></i> Informasi Dasar</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Nama Lengkap</label>
                        <input type="text" name="name" id="emp_name" required
                            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-semibold text-sm">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Posisi / Jabatan</label>
                        <input type="text" name="position" id="emp_position" placeholder="Cth: Mekanik Kepala" required
                            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-semibold text-sm">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Cabang Penempatan</label>
                        <select name="branch_id" id="emp_branch" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-bold text-sm text-slate-700">
                            <option value="">Pusat / Head Office</option>
                            <?php foreach ($branches as $br): ?>
                                <option value="<?php echo $br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Sisa Cuti (Hari)</label>
                        <input type="number" name="remaining_leave" id="emp_leave" value="0" min="0" required
                            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none transition-all font-semibold text-sm text-center">
                    </div>
                </div>

                <!-- Komponen Gaji -->
                <h4 class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-4 pt-4 border-t border-slate-100 flex items-center gap-2"><i data-lucide="banknote" class="w-3 h-3"></i> Komponen Pendapatan</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Gaji Pokok (Bulanan)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                            <input type="text" name="basic_salary" id="emp_basic" value="0" onkeyup="this.value = formatRupiah(this.value)"
                                class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none font-semibold text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Uang Harian / Makan</label>
                        <div class="relative">
                            <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                            <input type="text" name="daily_allowance" id="emp_daily" value="0" onkeyup="this.value = formatRupiah(this.value)"
                                class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none font-semibold text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Tarif Lembur (Per Jam)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                            <input type="text" name="overtime_rate" id="emp_overtime" value="0" onkeyup="this.value = formatRupiah(this.value)"
                                class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none font-semibold text-sm">
                        </div>
                    </div>
                </div>

                <!-- Komponen Potongan -->
                <h4 class="text-[10px] font-black text-red-600 uppercase tracking-widest mb-4 pt-4 border-t border-slate-100 flex items-center gap-2"><i data-lucide="trending-down" class="w-3 h-3"></i> Komponen Pengurang/Potongan</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Potongan Tidak Hadir (Per Hari)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                            <input type="text" name="absence_penalty_per_day" id="emp_abs_penalty" value="0" onkeyup="this.value = formatRupiah(this.value)"
                                class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-red-500 outline-none font-semibold text-sm text-red-600">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Potongan Terlambat (Per Menit)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                            <input type="text" name="late_penalty_per_minute" id="emp_late_penalty" value="0" onkeyup="this.value = formatRupiah(this.value)"
                                class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-red-500 outline-none font-semibold text-sm text-red-600">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Iuran BPJSTK (Tetap)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                            <input type="text" name="bpjs_tk_deduction" id="emp_bpjstk" value="0" onkeyup="this.value = formatRupiah(this.value)"
                                class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-red-500 outline-none font-semibold text-sm text-red-600">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Iuran BPJS Kes (Tetap)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm">Rp</span>
                            <input type="text" name="bpjs_deduction" id="emp_bpjs" value="0" onkeyup="this.value = formatRupiah(this.value)"
                                class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-red-500 outline-none font-semibold text-sm text-red-600">
                        </div>
                    </div>
                </div>

            </div>
            
            <div class="p-6 border-t border-slate-100 flex gap-3 justify-end bg-slate-50">
                <button type="button" onclick="closeModal()" class="px-6 py-3 rounded-xl font-bold text-xs uppercase tracking-widest text-slate-500 hover:bg-slate-200 transition-colors">Batal</button>
                <button type="submit" name="save_employee" class="px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest shadow-lg shadow-blue-500/30 transition-all active:scale-95 flex items-center gap-2">
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

    const modal = document.getElementById('employeeModal');
    const modalContent = document.getElementById('employeeModalContent');
    
    function openModal() {
        document.getElementById('modalTitle').textContent = 'Tambah Karyawan';
        document.getElementById('emp_id').value = '';
        document.getElementById('emp_name').value = '';
        document.getElementById('emp_position').value = '';
        document.getElementById('emp_branch').value = '';
        document.getElementById('emp_leave').value = '0';
        document.getElementById('emp_basic').value = '0';
        document.getElementById('emp_daily').value = '0';
        document.getElementById('emp_overtime').value = '0';
        document.getElementById('emp_abs_penalty').value = '0';
        document.getElementById('emp_late_penalty').value = '0';
        document.getElementById('emp_bpjstk').value = '0';
        document.getElementById('emp_bpjs').value = '0';
        
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
    
    function editEmployee(emp) {
        document.getElementById('modalTitle').textContent = 'Edit Karyawan';
        document.getElementById('emp_id').value = emp.id;
        document.getElementById('emp_name').value = emp.name;
        document.getElementById('emp_position').value = emp.position;
        document.getElementById('emp_branch').value = emp.branch_id || '';
        document.getElementById('emp_leave').value = emp.remaining_leave;
        
        document.getElementById('emp_basic').value = formatRupiah(emp.basic_salary);
        document.getElementById('emp_daily').value = formatRupiah(emp.daily_allowance);
        document.getElementById('emp_overtime').value = formatRupiah(emp.overtime_rate);
        document.getElementById('emp_abs_penalty').value = formatRupiah(emp.absence_penalty_per_day);
        document.getElementById('emp_late_penalty').value = formatRupiah(emp.late_penalty_per_minute);
        document.getElementById('emp_bpjstk').value = formatRupiah(emp.bpjs_tk_deduction);
        document.getElementById('emp_bpjs').value = formatRupiah(emp.bpjs_deduction);
        
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modalContent.classList.remove('scale-95');
        }, 10);
    }
</script>
</body>
</html>
