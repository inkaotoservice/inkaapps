<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Akses untuk Owner, SPV, dan semua level Admin
if (!has_role(['admin','admin_depok','admin_bsd','spv','owner','manager_ops'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Data Member / Pelanggan';
$msg = '';
$msg_type = '';

// ── PROSES CRUD MEMBER ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. TAMBAH MEMBER (Cepat oleh Kasir)
    if ($action === 'add') {
        $full_name = trim($_POST['full_name']);
        $email     = trim($_POST['email']);
        $phone     = trim($_POST['phone']);
        // Password default jika Admin yang buatkan
        $password  = password_hash('inka2026', PASSWORD_BCRYPT); 
        
        try {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetchColumn()) throw new Exception("Email '$email' sudah digunakan.");

            $pdo->beginTransaction();
            $user_id = uuid();
            
            $stmt1 = $pdo->prepare("INSERT INTO users (id, email, password) VALUES (?, ?, ?)");
            $stmt1->execute([$user_id, $email, $password]);
            
            $stmt2 = $pdo->prepare("INSERT INTO profiles (id, full_name, role, phone, total_points) VALUES (?, ?, 'member', ?, 0)");
            $stmt2->execute([$user_id, $full_name, $phone]);
            
            $pdo->commit();
            $msg = "Member baru berhasil didaftarkan!"; $msg_type = "success";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "Gagal mendaftar member: " . $e->getMessage(); $msg_type = "error";
        }
    }
    
    // 2. EDIT MEMBER
    elseif ($action === 'edit') {
        $id        = $_POST['id'];
        $full_name = trim($_POST['full_name']);
        $phone     = trim($_POST['phone']);
        $points    = (int)$_POST['points'];
        
        try {
            $stmt = $pdo->prepare("UPDATE profiles SET full_name=?, phone=?, total_points=? WHERE id=?");
            $stmt->execute([$full_name, $phone, $points, $id]);
            $msg = "Data member berhasil diperbarui!"; $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Gagal memperbarui data: " . $e->getMessage(); $msg_type = "error";
        }
    }

    // 3. HAPUS MEMBER
    elseif ($action === 'delete') {
        $id = $_POST['id'];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM profiles WHERE id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            $pdo->commit();
            $msg = "Member berhasil dihapus."; $msg_type = "success";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "Gagal menghapus (pastikan tidak ada riwayat transaksi yang terikat): " . $e->getMessage(); $msg_type = "error";
        }
    }
}

// ── AMBIL DATA MEMBER BESERTA STATISTIKNYA ──────────────────────
// Kita ambil data member sekaligus menghitung berapa kali mereka pernah servis 
// dan total uang yang sudah mereka habiskan.
$stmt = $pdo->query("
    SELECT p.id, p.full_name, p.phone, p.total_points, p.created_at, u.email,
           (SELECT COUNT(*) FROM transactions t WHERE t.member_id = p.id AND t.status = 'Paid') as visit_count,
           (SELECT COALESCE(SUM(total_amount),0) FROM transactions t WHERE t.member_id = p.id AND t.status = 'Paid') as total_spent
    FROM profiles p
    JOIN users u ON p.id = u.id
    WHERE p.role = 'member'
    ORDER BY p.created_at DESC
");
$members = $stmt->fetchAll();
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
                Database Member
            </h1>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="openModal('modalAdd')" class="bg-primary text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-blue-700 transition-all flex items-center gap-2 shadow-lg shadow-blue-500/20 active:scale-95">
                <i data-lucide="user-plus" class="w-4 h-4"></i> Registrasi Member
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

        <!-- Search Bar -->
        <div class="mb-6 flex gap-3 max-w-xl">
            <div class="relative flex-1">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" id="searchMember" oninput="filterMember()" placeholder="Cari nama, email, atau no HP..." class="w-full pl-11 pr-4 py-3.5 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 outline-none shadow-sm text-sm font-semibold">
            </div>
        </div>

        <!-- Table View -->
        <div class="bg-white rounded-3xl shadow-lg border border-slate-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400 font-black">
                            <th class="p-5">Profil Pelanggan</th>
                            <th class="p-5">Kontak</th>
                            <th class="p-5">Total Poin</th>
                            <th class="p-5">Statistik Servis</th>
                            <th class="p-5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50" id="memberTableBody">
                        <?php foreach ($members as $mem): ?>
                        <tr class="member-row hover:bg-slate-50/50 transition-colors group">
                            <!-- Info Dasar -->
                            <td class="p-5 search-target" data-search="<?php echo strtolower($mem['full_name'].' '.$mem['email'].' '.$mem['phone']); ?>">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-2xl bg-amber-50 flex items-center justify-center text-amber-600 font-black text-sm uppercase shadow-inner border border-amber-100 shrink-0">
                                        <?php echo substr($mem['full_name'], 0, 2); ?>
                                    </div>
                                    <div>
                                        <p class="font-black text-slate-900 text-sm"><?php echo htmlspecialchars($mem['full_name']); ?></p>
                                        <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">Gabung: <?php echo date('d M Y', strtotime($mem['created_at'])); ?></p>
                                    </div>
                                </div>
                            </td>
                            
                            <!-- Kontak -->
                            <td class="p-5">
                                <div class="space-y-1 text-xs font-semibold text-slate-600">
                                    <p class="flex items-center gap-1.5"><i data-lucide="mail" class="w-3.5 h-3.5 text-slate-400"></i> <?php echo htmlspecialchars($mem['email']); ?></p>
                                    <p class="flex items-center gap-1.5"><i data-lucide="phone" class="w-3.5 h-3.5 text-slate-400"></i> <?php echo htmlspecialchars($mem['phone']) ?: '-'; ?></p>
                                </div>
                            </td>

                            <!-- Poin -->
                            <td class="p-5">
                                <div class="inline-flex items-center gap-2 bg-amber-50 px-3 py-1.5 rounded-xl border border-amber-100">
                                    <i data-lucide="star" class="w-4 h-4 text-amber-500 fill-amber-500"></i>
                                    <span class="font-black text-amber-700"><?php echo number_format($mem['total_points']); ?></span>
                                </div>
                            </td>

                            <!-- Statistik -->
                            <td class="p-5">
                                <div class="space-y-1">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Kunjungan: <span class="text-blue-600"><?php echo $mem['visit_count']; ?>x</span></p>
                                    <p class="text-xs font-black text-slate-900"><?php echo short_rupiah($mem['total_spent']); ?></p>
                                </div>
                            </td>

                            <!-- Aksi -->
                            <td class="p-5 text-right">
                                <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($mem)); ?>)" class="w-8 h-8 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center hover:bg-amber-100 transition-colors" title="Edit Data">
                                        <i data-lucide="edit-2" class="w-4 h-4"></i>
                                    </button>
                                    
                                    <!-- Hanya Owner dan Manager yang bisa hapus member -->
                                    <?php if(is_owner() || is_manager()): ?>
                                    <form method="POST" action="" onsubmit="return confirm('PERINGATAN: Hapus permanen member <?php echo addslashes($mem['full_name']); ?>?');" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $mem['id']; ?>">
                                        <button type="submit" class="w-8 h-8 rounded-full bg-red-50 text-red-600 flex items-center justify-center hover:bg-red-100 transition-colors" title="Hapus Member">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($members)): ?>
                        <tr>
                            <td colspan="5" class="py-20 text-center text-slate-400">
                                <i data-lucide="users-2" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
                                <p class="font-semibold text-sm">Belum ada pelanggan yang mendaftar Member.</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- ==========================================
     MODAL TAMBAH MEMBER
=========================================== -->
<div id="modalAdd" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalAdd')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-lg relative z-10 overflow-hidden transform scale-95 opacity-0 transition-all duration-300" id="modalAddContent">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="font-black text-slate-900 text-lg">Registrasi Member Baru</h3>
                <button type="button" onclick="closeModal('modalAdd')" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form action="" method="POST" class="p-6">
                <input type="hidden" name="action" value="add">
                <div class="space-y-4">
                    <div class="bg-blue-50 border border-blue-100 p-3 rounded-xl text-xs font-semibold text-blue-700 flex items-start gap-2 mb-4">
                        <i data-lucide="info" class="w-4 h-4 mt-0.5 shrink-0"></i>
                        <p>Pelanggan bisa langsung Login ke sistem menggunakan email ini dan password default: <b>inka2026</b></p>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Lengkap <span class="text-red-500">*</span></label>
                        <input type="text" name="full_name" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Email Aktif <span class="text-red-500">*</span></label>
                        <input type="email" name="email" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">No. WhatsApp</label>
                        <input type="text" name="phone" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                    </div>
                </div>
                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalAdd')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/30 uppercase tracking-widest">Daftarkan Member</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     MODAL EDIT MEMBER
=========================================== -->
<div id="modalEdit" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalEdit')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-lg relative z-10 overflow-hidden transform scale-95 opacity-0 transition-all duration-300" id="modalEditContent">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-amber-50">
                <h3 class="font-black text-amber-900 text-lg">Edit Data Member</h3>
                <button type="button" onclick="closeModal('modalEdit')" class="w-8 h-8 flex items-center justify-center rounded-full bg-white/50 text-amber-700 hover:bg-white"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form action="" method="POST" class="p-6">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Lengkap</label>
                        <input type="text" name="full_name" id="editFullName" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 outline-none text-sm font-semibold">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">No. WhatsApp</label>
                            <input type="text" name="phone" id="editPhone" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 outline-none text-sm font-semibold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Total Poin (Penyesuaian)</label>
                            <input type="number" name="points" id="editPoints" class="w-full px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 focus:border-amber-500 outline-none text-sm font-black text-amber-900">
                        </div>
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

    function openEditModal(mem) {
        document.getElementById('editId').value = mem.id;
        document.getElementById('editFullName').value = mem.full_name;
        document.getElementById('editPhone').value = mem.phone || '';
        document.getElementById('editPoints').value = mem.total_points;
        openModal('modalEdit');
    }

    // Live Search
    function filterMember() {
        const keyword = document.getElementById('searchMember').value.toLowerCase();
        const rows = document.querySelectorAll('.member-row');
        rows.forEach(row => {
            const target = row.querySelector('.search-target');
            if (target && target.dataset.search.includes(keyword)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
</script>
JS;
?>
<?php include '../includes/footer.php'; ?>
