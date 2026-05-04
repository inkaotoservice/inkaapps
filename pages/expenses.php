<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Akses untuk semua (Admin/SPV/Owner), tapi datanya diisolasi berdasarkan Cabang
if (!has_role(['admin','admin_depok','admin_bsd','spv','owner','manager_ops'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Pencatatan Pengeluaran';
$msg = '';
$msg_type = '';
$user_branch = $_SESSION['branch_id'] ?? null;
$user_id = $_SESSION['user_id'];

// ── PROSES INPUT PENGELUARAN ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. TAMBAH PENGELUARAN
    if ($action === 'add') {
        $expense_date = $_POST['expense_date'];
        $category     = $_POST['category'];
        $amount       = (int)str_replace('.', '', $_POST['amount']);
        $description  = trim($_POST['description']);
        // Jika admin cabang, pakai session branch. Jika owner/manager/SPV, ambil dari form.
        $branch_id = (!empty($user_branch) && !is_spv()) ? $user_branch : ($_POST['branch_id'] ?? $user_branch);

        if ($branch_id && $amount > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO expenses (id, branch_id, expense_date, category, amount, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([uuid(), $branch_id, $expense_date, $category, $amount, $description, $user_id]);
                $msg = "Catatan pengeluaran berhasil disimpan!"; $msg_type = "success";
            } catch (Exception $e) {
                $msg = "Gagal menyimpan pengeluaran: " . $e->getMessage(); $msg_type = "error";
            }
        } else {
            $msg = "Harap lengkapi Cabang dan pastikan nominal lebih dari Rp 0."; $msg_type = "error";
        }
    }
    
    // 2. HAPUS PENGELUARAN
    elseif ($action === 'delete') {
        $id = $_POST['id'];
        try {
            // Hanya hapus jika expense ini milik cabangnya, atau dia adalah owner
            $sql = "DELETE FROM expenses WHERE id = ?";
            $params = [$id];
            
            if ($user_branch) {
                $sql .= " AND branch_id = ?";
                $params[] = $user_branch;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $msg = "Pengeluaran berhasil dihapus."; $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Gagal menghapus pengeluaran."; $msg_type = "error";
        }
    }
}

// ── FILTER & DATA ───────────────────────────────────────────────
$filter_month = $_GET['month'] ?? date('m');
$filter_year  = $_GET['year']  ?? date('Y');
$filter_branch= $_GET['branch_id'] ?? $user_branch;

// Query List Pengeluaran
$sql = "SELECT e.*, b.name as branch_name, p.full_name as creator_name 
        FROM expenses e 
        LEFT JOIN branches b ON e.branch_id = b.id 
        LEFT JOIN profiles p ON e.created_by = p.id 
        WHERE MONTH(e.expense_date) = ? AND YEAR(e.expense_date) = ?";
$params = [$filter_month, $filter_year];

if ($filter_branch) {
    $sql .= " AND e.branch_id = ?";
    $params[] = $filter_branch;
}

$sql .= " ORDER BY e.expense_date DESC, e.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Query Total Pengeluaran Bulan Ini
$total_expense = 0;
foreach ($expenses as $e) {
    $total_expense += $e['amount'];
}

// Ambil list cabang untuk dropdown (owner, manager, dan SPV bisa lihat semua cabang)
$branches = [];
if (!$user_branch || is_spv()) {
    $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();
}

$categories = [
    'Restock Sparepart' => 'bg-emerald-100 text-emerald-700',
    'Operasional (Listrik, Air)' => 'bg-blue-100 text-blue-700',
    'Gaji & Lembur Karyawan' => 'bg-purple-100 text-purple-700',
    'Konsumsi / Uang Makan' => 'bg-amber-100 text-amber-700',
    'Lainnya' => 'bg-slate-100 text-slate-700'
];
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
    <!-- Topbar -->
    <header class="h-16 sm:h-20 flex-shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu"></i>
        </button>

        <div class="flex-1 lg:ml-0 px-4">
            <h1 class="text-sm sm:text-lg font-semibold text-slate-900 truncate uppercase tracking-widest opacity-60">
                Pencatatan Pengeluaran
            </h1>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="openModal('modalAdd')" class="bg-red-600 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-red-700 transition-all flex items-center gap-2 shadow-lg shadow-red-500/20 active:scale-95">
                <i data-lucide="minus-circle" class="w-4 h-4"></i> Catat Pengeluaran
            </button>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar bg-slate-50">
        
        <?php if ($msg): ?>
        <div class="mb-6 max-w-7xl mx-auto p-4 rounded-2xl flex items-center gap-3 font-semibold text-sm <?php echo $msg_type === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-red-50 text-red-700 border border-red-100'; ?>">
            <i data-lucide="<?php echo $msg_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 shrink-0"></i>
            <?php echo $msg; ?>
        </div>
        <?php endif; ?>

        <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-4 gap-6">
            
            <!-- PANEL KIRI: FILTER & SUMMARY -->
            <div class="lg:col-span-1 space-y-6">
                
                <!-- Summary Card -->
                <div class="bg-gradient-to-br from-red-500 to-rose-600 rounded-3xl p-6 text-white shadow-xl relative overflow-hidden">
                    <i data-lucide="trending-down" class="absolute -right-4 -bottom-4 w-32 h-32 text-white/10 rotate-12"></i>
                    <div class="relative z-10">
                        <p class="text-[10px] text-white/80 font-black uppercase tracking-widest mb-1">Total Pengeluaran Bulan Ini</p>
                        <h3 class="text-3xl font-black tracking-tighter mb-4"><?php echo short_rupiah($total_expense); ?></h3>
                        <p class="text-xs font-semibold text-white/70">Periode: <?php echo date('F Y', mktime(0, 0, 0, $filter_month, 1, $filter_year)); ?></p>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="bg-white p-6 rounded-3xl shadow-lg border border-slate-100">
                    <h3 class="text-xs font-black text-slate-900 uppercase tracking-widest mb-4 flex items-center gap-2">
                        <i data-lucide="filter" class="w-4 h-4 text-blue-500"></i> Filter Data
                    </h3>
                    <form method="GET" action="">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Bulan</label>
                                <select name="month" class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold">
                                    <?php for($m=1; $m<=12; $m++): ?>
                                        <option value="<?php echo sprintf("%02d", $m); ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Tahun</label>
                                <select name="year" class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold">
                                    <?php for($y=date('Y')-2; $y<=date('Y'); $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <?php if (!$user_branch): ?>
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Cabang</label>
                                <select name="branch_id" class="w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold">
                                    <option value="">Semua Cabang</option>
                                    <?php foreach($branches as $b): ?>
                                        <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl text-xs font-black uppercase tracking-widest hover:bg-slate-800 transition-colors">Terapkan Filter</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- PANEL KANAN: TABEL DATA -->
            <div class="lg:col-span-3">
                <div class="bg-white rounded-3xl shadow-lg border border-slate-100 overflow-hidden h-full">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400 font-black">
                                    <th class="p-5">Tanggal</th>
                                    <th class="p-5">Kategori & Keterangan</th>
                                    <?php if (!$user_branch): ?><th class="p-5">Cabang</th><?php endif; ?>
                                    <th class="p-5 text-right">Nominal</th>
                                    <th class="p-5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($expenses as $e): 
                                    $cat_color = $categories[$e['category']] ?? $categories['Lainnya'];
                                ?>
                                <tr class="hover:bg-slate-50/50 transition-colors group">
                                    <td class="p-5">
                                        <p class="font-black text-slate-900 text-sm"><?php echo date('d M', strtotime($e['expense_date'])); ?></p>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5"><?php echo date('Y', strtotime($e['expense_date'])); ?></p>
                                    </td>
                                    <td class="p-5">
                                        <span class="inline-block px-2.5 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest mb-1.5 <?php echo $cat_color; ?>">
                                            <?php echo htmlspecialchars($e['category']); ?>
                                        </span>
                                        <?php if (strpos($e['description'], 'STRUCT_JSON:') === 0): 
                                            $items = json_decode(substr($e['description'], 12), true);
                                            if (is_array($items)):
                                        ?>
                                            <div class="mt-1 mb-2">
                                                <p class="text-[10px] font-black text-blue-600 uppercase tracking-widest flex items-center gap-1.5 mb-2">
                                                    <i data-lucide="truck" class="w-3 h-3"></i> <?php echo htmlspecialchars($items[0]['supplier_name'] ?? 'Supplier'); ?>
                                                </p>
                                                <div class="space-y-1.5">
                                                    <?php foreach ($items as $it): ?>
                                                        <div class="flex items-center gap-2 bg-slate-50 border border-slate-100 px-3 py-1.5 rounded-xl text-[11px]">
                                                            <div class="w-1.5 h-1.5 rounded-full bg-blue-400"></div>
                                                            <span class="font-bold text-slate-700"><?php echo htmlspecialchars($it['name']); ?></span>
                                                            <span class="text-slate-400 font-bold">(<?php echo $it['qty']; ?>x)</span>
                                                            <span class="ml-auto font-black text-slate-900">@<?php echo number_format($it['cost'], 0, ',', '.'); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-sm font-semibold text-slate-700"><?php echo htmlspecialchars($e['description']); ?></p>
                                        <?php endif; ?>
                                        <?php else: ?>
                                            <p class="text-sm font-semibold text-slate-700"><?php echo htmlspecialchars($e['description']); ?></p>
                                        <?php endif; ?>
                                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-1">Oleh: <?php echo htmlspecialchars($e['creator_name']); ?></p>
                                    </td>
                                    
                                    <?php if (!$user_branch): ?>
                                    <td class="p-5">
                                        <span class="text-xs font-semibold text-slate-600 flex items-center gap-1">
                                            <i data-lucide="map-pin" class="w-3 h-3 text-blue-500"></i> <?php echo htmlspecialchars($e['branch_name']); ?>
                                        </span>
                                    </td>
                                    <?php endif; ?>

                                    <td class="p-5 text-right">
                                        <span class="text-base font-black text-red-600">- <?php echo rupiah($e['amount']); ?></span>
                                    </td>

                                    <td class="p-5 text-right">
                                        <div class="opacity-0 group-hover:opacity-100 transition-opacity">
                                            <form method="POST" action="" onsubmit="return confirm('Hapus catatan pengeluaran ini secara permanen?');" class="inline">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $e['id']; ?>">
                                                <button type="submit" class="w-8 h-8 rounded-full bg-red-50 text-red-600 inline-flex items-center justify-center hover:bg-red-100 transition-colors" title="Hapus">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>

                                <?php if (empty($expenses)): ?>
                                <tr>
                                    <td colspan="5" class="py-20 text-center text-slate-400">
                                        <i data-lucide="receipt" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
                                        <p class="font-semibold text-sm">Tidak ada catatan pengeluaran di periode ini.</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<!-- ==========================================
     MODAL TAMBAH PENGELUARAN
=========================================== -->
<div id="modalAdd" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalAdd')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md relative z-10 overflow-hidden transform scale-95 opacity-0 transition-all duration-300" id="modalAddContent">
            
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-red-50">
                <h3 class="font-black text-red-900 text-lg">Catat Kas Keluar</h3>
                <button type="button" onclick="closeModal('modalAdd')" class="w-8 h-8 flex items-center justify-center rounded-full bg-white/50 text-red-700 hover:bg-white"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>

            <form action="" method="POST" class="p-6">
                <input type="hidden" name="action" value="add">
                
                <div class="space-y-4">
                    <?php if (!$user_branch): ?>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Cabang <span class="text-red-500">*</span></label>
                        <select name="branch_id" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-red-500 outline-none text-sm font-bold text-slate-700">
                            <option value="">- Pilih Cabang -</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Kategori Biaya</label>
                            <select name="category" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-red-500 outline-none text-sm font-bold text-slate-700">
                                <?php foreach(array_keys($categories) as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Tanggal</label>
                            <input type="date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-red-500 outline-none text-sm font-semibold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nominal (Rp) <span class="text-red-500">*</span></label>
                            <input type="text" name="amount" onkeyup="formatRibuan(this)" required class="w-full px-4 py-3 rounded-xl bg-red-50 border border-red-200 focus:border-red-500 outline-none text-sm font-black text-red-700">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Keterangan / Tujuan Biaya <span class="text-red-500">*</span></label>
                        <textarea name="description" rows="2" required placeholder="Contoh: Beli oli mesin 5 dus, Bayar listrik bulan ini..." class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-red-500 outline-none text-sm font-semibold"></textarea>
                    </div>
                </div>

                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalAdd')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-700 shadow-lg shadow-red-500/30 uppercase tracking-widest flex items-center gap-2">
                        <i data-lucide="save" class="w-4 h-4"></i> Simpan Catatan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<JS
<script>
    function formatRibuan(input) {
        let value = input.value.replace(/[^,\d]/g, '').toString();
        let split = value.split(',');
        let sisa  = split[0].length % 3;
        let rupiah = split[0].substr(0, sisa);
        let ribuan = split[0].substr(sisa).match(/\d{3}/gi);
        if (ribuan) {
            let separator = sisa ? '.' : '';
            rupiah += separator + ribuan.join('.');
        }
        input.value = rupiah;
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        const content = document.getElementById(id + 'Content');
        modal.classList.remove('hidden');
        setTimeout(() => {
            content.classList.remove('scale-95', 'opacity-0');
            content.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        const content = document.getElementById(id + 'Content');
        content.classList.remove('scale-100', 'opacity-100');
        content.classList.add('scale-95', 'opacity-0');
        setTimeout(() => { modal.classList.add('hidden'); }, 300);
    }
</script>
JS;
?>
<?php include '../includes/footer.php'; ?>
