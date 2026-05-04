<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

if (!has_role(['owner', 'manager_ops', 'spv'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Maintenance Alat';
$msg = '';
$msg_type = '';

// Create table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_logs (
        id VARCHAR(36) PRIMARY KEY,
        tool_name VARCHAR(255) NOT NULL,
        description TEXT,
        maintenance_date DATE NOT NULL,
        cost BIGINT DEFAULT 0,
        status ENUM('Scheduled', 'In Progress', 'Completed') DEFAULT 'Scheduled',
        branch_id VARCHAR(36),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
    )");
} catch (Exception $e) {
    // Ignore error if user lacks privilege or table exists
}

// Proses CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect_url = "maintenance.php" . (!empty($_GET['branch_id']) ? "?branch_id=" . $_GET['branch_id'] : "");
    
    if ($action === 'add') {
        $tool_name = trim($_POST['tool_name']);
        $description = trim($_POST['description']);
        $maintenance_date = $_POST['maintenance_date'];
        $cost = (int)str_replace(['Rp', '.', ' '], '', $_POST['cost']);
        $status = $_POST['status'];
        $branch_id = empty($_POST['branch_id']) ? null : $_POST['branch_id'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO maintenance_logs (id, tool_name, description, maintenance_date, cost, status, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([uuid(), $tool_name, $description, $maintenance_date, $cost, $status, $branch_id]);
            set_flash_msg("Jadwal maintenance berhasil ditambahkan!");
            header("Location: $redirect_url"); exit();
        } catch (Exception $e) {
            set_flash_msg("Gagal menambah data: " . $e->getMessage(), "error");
            header("Location: $redirect_url"); exit();
        }
    }
    elseif ($action === 'edit') {
        $id = $_POST['id'];
        $tool_name = trim($_POST['tool_name']);
        $description = trim($_POST['description']);
        $maintenance_date = $_POST['maintenance_date'];
        $cost = (int)str_replace(['Rp', '.', ' '], '', $_POST['cost']);
        $status = $_POST['status'];
        $branch_id = empty($_POST['branch_id']) ? null : $_POST['branch_id'];
        
        try {
            $stmt = $pdo->prepare("UPDATE maintenance_logs SET tool_name=?, description=?, maintenance_date=?, cost=?, status=?, branch_id=? WHERE id=?");
            $stmt->execute([$tool_name, $description, $maintenance_date, $cost, $status, $branch_id, $id]);
            set_flash_msg("Data maintenance berhasil diperbarui!");
            header("Location: $redirect_url"); exit();
        } catch (Exception $e) {
            set_flash_msg("Gagal memperbarui data: " . $e->getMessage(), "error");
            header("Location: $redirect_url"); exit();
        }
    }
    elseif ($action === 'delete') {
        $id = $_POST['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM maintenance_logs WHERE id=?");
            $stmt->execute([$id]);
            set_flash_msg("Data berhasil dihapus.");
            header("Location: $redirect_url"); exit();
        } catch (Exception $e) {
            set_flash_msg("Gagal menghapus data.", "error");
            header("Location: $redirect_url"); exit();
        }
    }
}

// Ambil pesan flash
$flash = get_flash_msg();
if ($flash) {
    $msg = $flash['msg'];
    $msg_type = $flash['type'];
}

// Fetch Filter
$filter_branch = $_GET['branch_id'] ?? '';
$where = $filter_branch ? "WHERE m.branch_id = :branch" : "";

$sql = "SELECT m.*, b.name as branch_name 
        FROM maintenance_logs m 
        LEFT JOIN branches b ON m.branch_id = b.id 
        $where 
        ORDER BY m.maintenance_date DESC";
$stmt = $pdo->prepare($sql);
if ($filter_branch) $stmt->bindValue(':branch', $filter_branch);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50 relative">
    <header class="h-16 lg:h-20 bg-white/50 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 z-40">
        <div class="flex items-center gap-3">
            <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full'); document.getElementById('sidebar-overlay').classList.toggle('hidden');" class="p-2 lg:hidden bg-slate-100 text-slate-600 rounded-xl">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div class="hidden lg:flex w-10 h-10 bg-indigo-50 rounded-xl items-center justify-center text-indigo-600">
                <i data-lucide="wrench" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="text-lg lg:text-2xl font-black text-slate-900 uppercase tracking-tight">Maintenance Alat</h1>
                <p class="text-[10px] lg:text-xs font-bold text-slate-400 uppercase tracking-widest mt-0.5">Jadwal Perawatan Aset Bengkel</p>
            </div>
        </div>
        
        <div class="flex items-center gap-3">
            <form method="GET" class="hidden md:flex items-center gap-2">
                <select name="branch_id" onchange="this.form.submit()" class="px-4 py-2 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 outline-none focus:ring-4 focus:ring-indigo-500/10">
                    <option value="">Semua Cabang</option>
                    <?php foreach($branches as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button onclick="openModal('modalAdd')" class="bg-indigo-600 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-indigo-700 transition-all flex items-center gap-2 shadow-lg shadow-indigo-500/20 active:scale-95">
                <i data-lucide="plus" class="w-4 h-4"></i> Tambah
            </button>
        </div>
    </header>

    <div class="flex-1 overflow-y-auto p-4 lg:p-8 custom-scrollbar">
        <div class="max-w-7xl mx-auto space-y-6">
            
            <?php if ($msg): ?>
            <div class="mb-6 p-4 rounded-2xl flex items-center gap-3 font-semibold text-sm <?php echo $msg_type === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-red-50 text-red-700 border border-red-100'; ?>">
                <i data-lucide="<?php echo $msg_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5"></i>
                <?php echo $msg; ?>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100">
                                <th class="p-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Nama Alat</th>
                                <th class="p-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Jadwal / Cabang</th>
                                <th class="p-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Status</th>
                                <th class="p-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Biaya</th>
                                <th class="p-4 text-[10px] font-black uppercase tracking-widest text-slate-400 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="p-4">
                                        <p class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($log['tool_name']); ?></p>
                                        <p class="text-[10px] font-semibold text-slate-500 mt-1 line-clamp-1"><?php echo htmlspecialchars($log['description'] ?: '-'); ?></p>
                                    </td>
                                    <td class="p-4">
                                        <p class="text-xs font-bold text-slate-700 flex items-center gap-1.5"><i data-lucide="calendar" class="w-3 h-3 text-slate-400"></i> <?php echo date('d M Y', strtotime($log['maintenance_date'])); ?></p>
                                        <p class="text-[10px] font-semibold text-slate-500 mt-1 uppercase tracking-widest flex items-center gap-1.5"><i data-lucide="building-2" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($log['branch_name'] ?: 'Pusat/Global'); ?></p>
                                    </td>
                                    <td class="p-4">
                                        <?php if($log['status'] == 'Completed'): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-emerald-100 text-emerald-700 text-[10px] font-black uppercase tracking-widest">Selesai</span>
                                        <?php elseif($log['status'] == 'In Progress'): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-amber-100 text-amber-700 text-[10px] font-black uppercase tracking-widest">Diproses</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-slate-100 text-slate-600 text-[10px] font-black uppercase tracking-widest">Terjadwal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4">
                                        <p class="text-sm font-bold text-slate-900"><?php echo short_rupiah($log['cost']); ?></p>
                                    </td>
                                    <td class="p-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($log)); ?>)" class="w-8 h-8 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center hover:bg-indigo-100 transition-colors">
                                                <i data-lucide="edit-2" class="w-4 h-4"></i>
                                            </button>
                                            <form method="POST" action="" onsubmit="return confirm('Yakin ingin menghapus data ini?');" class="inline">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $log['id']; ?>">
                                                <button type="submit" class="w-8 h-8 rounded-full bg-red-50 text-red-600 flex items-center justify-center hover:bg-red-100 transition-colors">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="p-10 text-center">
                                        <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center mx-auto mb-3">
                                            <i data-lucide="inbox" class="w-8 h-8"></i>
                                        </div>
                                        <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Belum ada data maintenance</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</main>

<!-- MODAL TAMBAH -->
<div id="modalAdd" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalAdd')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md relative z-10 overflow-y-auto max-h-[90vh] custom-scrollbar transform scale-95 opacity-0 transition-all duration-300" id="modalAddContent">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="font-black text-slate-900 text-lg">Tambah Jadwal</h3>
                <button type="button" onclick="closeModal('modalAdd')" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
            <form action="" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="add">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Alat / Mesin</label>
                    <input type="text" name="tool_name" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Tanggal Maintenance</label>
                    <input type="date" name="maintenance_date" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Cabang</label>
                    <select name="branch_id" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                        <option value="">Pusat / Global</option>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Estimasi / Total Biaya</label>
                    <input type="number" name="cost" value="0" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Status</label>
                    <select name="status" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                        <option value="Scheduled">Terjadwal</option>
                        <option value="In Progress">Sedang Diproses</option>
                        <option value="Completed">Selesai</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Keterangan</label>
                    <textarea name="description" rows="2" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900"></textarea>
                </div>
                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalAdd')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition-colors uppercase tracking-widest">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL EDIT -->
<div id="modalEdit" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalEdit')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md relative z-10 overflow-y-auto max-h-[90vh] custom-scrollbar transform scale-95 opacity-0 transition-all duration-300" id="modalEditContent">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-indigo-50">
                <h3 class="font-black text-indigo-900 text-lg">Edit Jadwal</h3>
                <button type="button" onclick="closeModal('modalEdit')" class="w-8 h-8 flex items-center justify-center rounded-full bg-white/50 text-indigo-700 hover:bg-white">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
            <form action="" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Alat / Mesin</label>
                    <input type="text" name="tool_name" id="editToolName" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Tanggal Maintenance</label>
                    <input type="date" name="maintenance_date" id="editDate" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Cabang</label>
                    <select name="branch_id" id="editBranchId" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                        <option value="">Pusat / Global</option>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Estimasi / Total Biaya</label>
                    <input type="number" name="cost" id="editCost" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Status</label>
                    <select name="status" id="editStatus" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900">
                        <option value="Scheduled">Terjadwal</option>
                        <option value="In Progress">Sedang Diproses</option>
                        <option value="Completed">Selesai</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Keterangan</label>
                    <textarea name="description" id="editDesc" rows="2" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 outline-none text-sm font-semibold text-slate-900"></textarea>
                </div>
                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalEdit')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-indigo-900 bg-indigo-400 hover:bg-indigo-500 transition-colors uppercase tracking-widest shadow-lg shadow-indigo-500/30">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<JS
<script>
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

    function openEditModal(log) {
        document.getElementById('editId').value = log.id;
        document.getElementById('editToolName').value = log.tool_name;
        document.getElementById('editDate').value = log.maintenance_date;
        document.getElementById('editBranchId').value = log.branch_id || '';
        document.getElementById('editCost').value = log.cost;
        document.getElementById('editStatus').value = log.status;
        document.getElementById('editDesc').value = log.description || '';
        openModal('modalEdit');
    }
</script>
JS;
?>
<?php include '../includes/footer.php'; ?>
