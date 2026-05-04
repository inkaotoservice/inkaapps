<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Hanya Owner dan SPV yang boleh kelola master data Reward
if (!has_role(['owner', 'manager_ops', 'spv'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Kelola Reward & Promosi';
$msg = '';
$msg_type = '';

// ── PROSES CRUD REWARD ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. TAMBAH REWARD
    if ($action === 'add') {
        $name            = trim($_POST['name']);
        $points_required = (int)str_replace('.', '', $_POST['points_required']);
        $discount_amount = (int)str_replace('.', '', $_POST['discount_amount']);
        $description     = trim($_POST['description'] ?? '');
        
        try {
            // Karena di schema awal belum ada kolom description, kita cek dulu 
            // Untuk amannya, kita simpan name, points_required, discount_amount saja 
            // Jika mau tambah deskripsi di kemudian hari, schema bisa diubah.
            // Di sini kita gunakan struktur dasar yang sudah dibuat di mysql_setup.sql
            $stmt = $pdo->prepare("INSERT INTO rewards (id, name, points_required, discount_amount, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([uuid(), $name, $points_required, $discount_amount]);
            $msg = "Reward baru berhasil ditambahkan!"; $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Gagal menambah reward: " . $e->getMessage(); $msg_type = "error";
        }
    }
    
    // 2. EDIT REWARD
    elseif ($action === 'edit') {
        $id              = $_POST['id'];
        $name            = trim($_POST['name']);
        $points_required = (int)str_replace('.', '', $_POST['points_required']);
        $discount_amount = (int)str_replace('.', '', $_POST['discount_amount']);
        
        try {
            $stmt = $pdo->prepare("UPDATE rewards SET name=?, points_required=?, discount_amount=? WHERE id=?");
            $stmt->execute([$name, $points_required, $discount_amount, $id]);
            $msg = "Reward berhasil diperbarui!"; $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Gagal memperbarui reward: " . $e->getMessage(); $msg_type = "error";
        }
    }

    // 3. HAPUS REWARD (Soft Delete)
    elseif ($action === 'delete') {
        $id = $_POST['id'];
        try {
            $stmt = $pdo->prepare("UPDATE rewards SET is_active=0 WHERE id=?");
            $stmt->execute([$id]);
            $msg = "Reward berhasil dinonaktifkan / dihapus."; $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Gagal menghapus reward: " . $e->getMessage(); $msg_type = "error";
        }
    }
}

// ── AMBIL DATA REWARD AKTIF ─────────────────────────────────────
$stmt = $pdo->query("SELECT * FROM rewards WHERE is_active = 1 ORDER BY points_required ASC");
$rewards = $stmt->fetchAll();
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
                Katalog Reward
            </h1>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="openModal('modalAdd')" class="bg-primary text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-blue-700 transition-all flex items-center gap-2 shadow-lg shadow-blue-500/20 active:scale-95">
                <i data-lucide="gift" class="w-4 h-4"></i> Buat Reward Baru
            </button>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar bg-slate-50">
        
        <?php if ($msg): ?>
        <div class="mb-6 p-4 rounded-2xl flex items-center gap-3 font-semibold text-sm <?php echo $msg_type === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-red-50 text-red-700 border border-red-100'; ?>">
            <i data-lucide="<?php echo $msg_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 shrink-0"></i>
            <?php echo $msg; ?>
        </div>
        <?php endif; ?>

        <!-- Grid Katalog Reward -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php foreach ($rewards as $r): ?>
                <div class="bg-white rounded-3xl shadow-lg border border-slate-100 overflow-hidden group relative hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                    
                    <!-- Action Buttons -->
                    <div class="absolute top-4 right-4 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity z-10">
                        <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($r)); ?>)" class="w-8 h-8 rounded-full bg-amber-500 text-white flex items-center justify-center hover:bg-amber-600 transition-colors shadow-lg" title="Edit">
                            <i data-lucide="edit-2" class="w-3.5 h-3.5"></i>
                        </button>
                        <form method="POST" action="" onsubmit="return confirm('Yakin ingin menonaktifkan reward ini?');" class="inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <button type="submit" class="w-8 h-8 rounded-full bg-red-500 text-white flex items-center justify-center hover:bg-red-600 transition-colors shadow-lg" title="Hapus">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            </button>
                        </form>
                    </div>

                    <!-- Visual Header -->
                    <div class="h-32 bg-gradient-to-br from-indigo-500 to-purple-600 relative flex items-center justify-center overflow-hidden">
                        <i data-lucide="award" class="absolute -right-4 -bottom-4 w-32 h-32 text-white/10 rotate-12"></i>
                        <div class="bg-white/20 backdrop-blur-sm px-6 py-2 rounded-full border border-white/30 text-white font-black text-2xl tracking-tight shadow-inner">
                            - <?php echo short_rupiah($r['discount_amount']); ?>
                        </div>
                    </div>

                    <div class="p-6">
                        <h3 class="text-lg font-black text-slate-900 tracking-tight leading-tight mb-2">
                            <?php echo htmlspecialchars($r['name']); ?>
                        </h3>
                        <p class="text-xs font-semibold text-slate-500 mb-6 line-clamp-2">
                            Dapat ditukarkan dengan potongan harga senilai <?php echo rupiah($r['discount_amount']); ?> pada transaksi berikutnya.
                        </p>

                        <div class="flex items-center justify-between border-t border-slate-100 pt-4">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Syarat Poin</p>
                            <div class="flex items-center gap-1.5 bg-amber-50 text-amber-700 px-3 py-1 rounded-lg border border-amber-100">
                                <i data-lucide="star" class="w-3.5 h-3.5 fill-amber-500 text-amber-500"></i>
                                <span class="font-black text-sm"><?php echo number_format($r['points_required']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($rewards)): ?>
                <div class="col-span-full py-20 text-center">
                    <div class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto mb-6 text-slate-400">
                        <i data-lucide="gift" class="w-10 h-10"></i>
                    </div>
                    <h3 class="text-xl font-black text-slate-900">Belum Ada Reward</h3>
                    <p class="text-slate-500 mt-2 text-sm">Buat reward diskon untuk menarik pelanggan agar kembali servis.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- ==========================================
     MODAL TAMBAH REWARD
=========================================== -->
<div id="modalAdd" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalAdd')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md relative z-10 overflow-hidden transform scale-95 opacity-0 transition-all duration-300" id="modalAddContent">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="font-black text-slate-900 text-lg">Buat Reward Baru</h3>
                <button type="button" onclick="closeModal('modalAdd')" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form action="" method="POST" class="p-6">
                <input type="hidden" name="action" value="add">
                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Reward (Contoh: Potongan 50Rb)</label>
                        <input type="text" name="name" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nilai Diskon (Rp)</label>
                        <input type="text" name="discount_amount" onkeyup="formatRibuan(this)" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-black text-indigo-700">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Harga Poin (Poin yg dibutuhkan)</label>
                        <input type="text" name="points_required" onkeyup="formatRibuan(this)" required class="w-full px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 focus:border-amber-500 outline-none text-sm font-black text-amber-700">
                    </div>
                </div>
                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalAdd')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 shadow-lg shadow-indigo-500/30 uppercase tracking-widest">Simpan Reward</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     MODAL EDIT REWARD
=========================================== -->
<div id="modalEdit" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalEdit')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md relative z-10 overflow-hidden transform scale-95 opacity-0 transition-all duration-300" id="modalEditContent">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-amber-50">
                <h3 class="font-black text-amber-900 text-lg">Edit Reward</h3>
                <button type="button" onclick="closeModal('modalEdit')" class="w-8 h-8 flex items-center justify-center rounded-full bg-white/50 text-amber-700 hover:bg-white"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form action="" method="POST" class="p-6">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Reward</label>
                        <input type="text" name="name" id="editName" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 outline-none text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nilai Diskon (Rp)</label>
                        <input type="text" name="discount_amount" id="editDiscount" onkeyup="formatRibuan(this)" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 outline-none text-sm font-black text-indigo-700">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Harga Poin</label>
                        <input type="text" name="points_required" id="editPoints" onkeyup="formatRibuan(this)" required class="w-full px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 focus:border-amber-500 outline-none text-sm font-black text-amber-700">
                    </div>
                </div>
                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalEdit')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-amber-900 bg-amber-400 hover:bg-amber-500 shadow-lg shadow-amber-500/30 uppercase tracking-widest">Simpan Perubahan</button>
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

    function openEditModal(reward) {
        document.getElementById('editId').value = reward.id;
        document.getElementById('editName').value = reward.name;
        document.getElementById('editDiscount').value = new Intl.NumberFormat('id-ID').format(reward.discount_amount);
        document.getElementById('editPoints').value = new Intl.NumberFormat('id-ID').format(reward.points_required);
        openModal('modalEdit');
    }
</script>
JS;
?>
<?php include '../includes/footer.php'; ?>
