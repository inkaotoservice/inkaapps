<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Hanya Owner dan Manager Ops yang boleh kelola cabang
if (!is_owner() && !is_manager()) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Kelola Cabang';
$msg = '';
$msg_type = '';

// ── PROSES CRUD (CREATE, UPDATE, DELETE) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. TAMBAH CABANG
    if ($action === 'add') {
        $name    = trim($_POST['name']);
        $address = trim($_POST['address']);
        $phone   = trim($_POST['phone']);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO branches (id, name, address, phone) VALUES (?, ?, ?, ?)");
            $stmt->execute([uuid(), $name, $address, $phone]);
            $msg = "Cabang baru berhasil ditambahkan!"; $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Gagal menambah cabang: " . $e->getMessage(); $msg_type = "error";
        }
    }
    
    // 2. EDIT CABANG
    elseif ($action === 'edit') {
        $id      = $_POST['id'];
        $name    = trim($_POST['name']);
        $address = trim($_POST['address']);
        $phone   = trim($_POST['phone']);
        
        try {
            $stmt = $pdo->prepare("UPDATE branches SET name=?, address=?, phone=? WHERE id=?");
            $stmt->execute([$name, $address, $phone, $id]);
            $msg = "Data cabang berhasil diperbarui!"; $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Gagal memperbarui cabang: " . $e->getMessage(); $msg_type = "error";
        }
    }

    // 3. HAPUS CABANG
    elseif ($action === 'delete') {
        $id = $_POST['id'];
        try {
            // Karena di schema kita set 'ON DELETE SET NULL' di tabel anak, 
            // kita bisa langsung hapus (meski idealnya cek transaksi dulu)
            $stmt = $pdo->prepare("DELETE FROM branches WHERE id=?");
            $stmt->execute([$id]);
            $msg = "Cabang berhasil dihapus secara permanen."; $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Gagal menghapus cabang (kemungkinan ada data terkait): " . $e->getMessage(); $msg_type = "error";
        }
    }
}

// ── AMBIL DATA CABANG & STATISTIKNYA ────────────────────────────
// Mengambil jumlah staff untuk masing-masing cabang
$stmt = $pdo->query("
    SELECT b.*,
           (SELECT COUNT(*) FROM profiles p WHERE p.branch_id = b.id AND p.role != 'member') as staff_count
    FROM branches b
    ORDER BY b.created_at ASC
");
$branches = $stmt->fetchAll();
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
                Manajemen Cabang
            </h1>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="openModal('modalAdd')" class="bg-blue-600 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-blue-700 transition-all flex items-center gap-2 shadow-lg shadow-blue-500/20 active:scale-95">
                <i data-lucide="plus" class="w-4 h-4"></i> Tambah Cabang
            </button>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar bg-slate-50">
        
        <?php if ($msg): ?>
        <div class="mb-6 p-4 rounded-2xl flex items-center gap-3 font-semibold text-sm <?php echo $msg_type === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-red-50 text-red-700 border border-red-100'; ?>">
            <i data-lucide="<?php echo $msg_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5"></i>
            <?php echo $msg; ?>
        </div>
        <?php endif; ?>

        <!-- Grid Cabang -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach ($branches as $branch): ?>
                <div class="bg-white p-6 rounded-3xl shadow-lg border border-slate-100 hover:shadow-xl hover:border-blue-200 transition-all group relative">
                    
                    <!-- Action Buttons -->
                    <div class="absolute top-4 right-4 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($branch)); ?>)" class="w-8 h-8 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center hover:bg-amber-100 transition-colors" title="Edit">
                            <i data-lucide="edit-2" class="w-4 h-4"></i>
                        </button>
                        <form method="POST" action="" onsubmit="return confirm('PERINGATAN: Menghapus cabang bisa berdampak pada data terkait. Yakin ingin menghapus <?php echo addslashes($branch['name']); ?>?');" class="inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $branch['id']; ?>">
                            <button type="submit" class="w-8 h-8 rounded-full bg-red-50 text-red-600 flex items-center justify-center hover:bg-red-100 transition-colors" title="Hapus">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </form>
                    </div>

                    <div class="flex items-start gap-4 mb-5 pr-20">
                        <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center shrink-0">
                            <i data-lucide="building-2" class="w-7 h-7"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-black text-slate-900 tracking-tight mb-1 group-hover:text-blue-600 transition-colors">
                                <?php echo htmlspecialchars($branch['name']); ?>
                            </h3>
                            <p class="text-xs text-slate-500 font-medium flex items-center gap-1.5">
                                <i data-lucide="phone" class="w-3.5 h-3.5"></i>
                                <?php echo htmlspecialchars($branch['phone'] ?? '') ?: 'Tidak ada nomor telepon'; ?>
                            </p>
                        </div>
                    </div>

                    <div class="mb-5 p-4 bg-slate-50 rounded-2xl text-xs font-semibold text-slate-600 flex items-start gap-2 h-20 overflow-hidden">
                        <i data-lucide="map-pin" class="w-4 h-4 mt-0.5 shrink-0 text-slate-400"></i>
                        <span class="line-clamp-3"><?php echo htmlspecialchars($branch['address'] ?? '') ?: 'Alamat belum diatur'; ?></span>
                    </div>
                    
                    <div class="border-t border-slate-100 pt-4">
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Karyawan</p>
                            <p class="text-lg font-black text-slate-900"><?php echo $branch['staff_count']; ?> <span class="text-xs font-semibold text-slate-400">Orang</span></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($branches)): ?>
                <div class="col-span-full py-20 text-center">
                    <div class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto mb-6 text-slate-400">
                        <i data-lucide="building-2" class="w-10 h-10"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-900">Belum Ada Cabang</h3>
                    <p class="text-slate-500 mt-2 text-sm">Anda belum mendaftarkan cabang bengkel satupun.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- ==========================================
     MODAL TAMBAH CABANG
=========================================== -->
<div id="modalAdd" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalAdd')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-lg relative z-10 overflow-hidden transform scale-95 opacity-0 transition-all duration-300" id="modalAddContent">
            
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="font-black text-slate-900 text-lg">Tambah Cabang Baru</h3>
                <button type="button" onclick="closeModal('modalAdd')" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <form action="" method="POST" class="p-6">
                <input type="hidden" name="action" value="add">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Cabang</label>
                        <input type="text" name="name" required placeholder="Contoh: Cabang Bekasi" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none text-sm font-semibold text-slate-900">
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">No. Telepon</label>
                        <input type="text" name="phone" placeholder="Contoh: 081234567890" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none text-sm font-semibold text-slate-900">
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Alamat Lengkap</label>
                        <textarea name="address" rows="3" placeholder="Masukkan alamat lengkap cabang..." class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none text-sm font-semibold text-slate-900"></textarea>
                    </div>
                </div>

                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalAdd')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 transition-colors uppercase tracking-widest shadow-lg shadow-blue-500/30">Simpan Cabang</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     MODAL EDIT CABANG
=========================================== -->
<div id="modalEdit" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalEdit')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-lg relative z-10 overflow-hidden transform scale-95 opacity-0 transition-all duration-300" id="modalEditContent">
            
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-amber-50">
                <h3 class="font-black text-amber-900 text-lg">Edit Data Cabang</h3>
                <button type="button" onclick="closeModal('modalEdit')" class="w-8 h-8 flex items-center justify-center rounded-full bg-white/50 text-amber-700 hover:bg-white">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <form action="" method="POST" class="p-6">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Cabang</label>
                        <input type="text" name="name" id="editName" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 focus:ring-2 focus:ring-amber-200 outline-none text-sm font-semibold text-slate-900">
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">No. Telepon</label>
                        <input type="text" name="phone" id="editPhone" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 focus:ring-2 focus:ring-amber-200 outline-none text-sm font-semibold text-slate-900">
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Alamat Lengkap</label>
                        <textarea name="address" id="editAddress" rows="3" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 focus:ring-2 focus:ring-amber-200 outline-none text-sm font-semibold text-slate-900"></textarea>
                    </div>
                </div>

                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalEdit')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-amber-900 bg-amber-400 hover:bg-amber-500 transition-colors uppercase tracking-widest shadow-lg shadow-amber-500/30">Simpan Perubahan</button>
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

    function openEditModal(branch) {
        document.getElementById('editId').value = branch.id;
        document.getElementById('editName').value = branch.name;
        document.getElementById('editPhone').value = branch.phone || '';
        document.getElementById('editAddress').value = branch.address || '';
        openModal('modalEdit');
    }
</script>
JS;
?>
<?php include '../includes/footer.php'; ?>
