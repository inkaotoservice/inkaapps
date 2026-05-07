<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Hanya Owner, Manager Ops, dan Supervisor yang boleh mengelola akun karyawan
if (!has_role(['owner', 'manager_ops', 'spv'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

// Ensure 'mekanik' is in the ENUM and 'jobdesk' column exists
try {
    $pdo->exec("ALTER TABLE profiles MODIFY COLUMN role ENUM('owner','manager_ops','admin','admin_depok','admin_bsd','spv','mekanik') DEFAULT 'admin'");
    
    // Check if jobdesk column exists (compatibility fix)
    $check_col = $pdo->query("SHOW COLUMNS FROM profiles LIKE 'jobdesk'");
    if (!$check_col->fetch()) {
        $pdo->exec("ALTER TABLE profiles ADD COLUMN jobdesk VARCHAR(100) AFTER full_name");
    }
} catch (Exception $e) {}

$page_title = 'Manajemen Karyawan';
$msg = '';
$msg_type = '';

// ── PROSES CRUD KARYAWAN ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect_url = "staff.php" . (!empty($_GET['branch_id']) ? "?branch_id=" . $_GET['branch_id'] : "");
    
    // 1. TAMBAH KARYAWAN
    if ($action === 'add') {
        $is_pekerja = ($_POST['type'] ?? '') === 'pekerja';
        $full_name = trim($_POST['full_name']);
        $jobdesk   = trim($_POST['jobdesk'] ?? '');
        $branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;
        
        if ($is_pekerja) {
            $role = 'mekanik';
            $email = "pekerja_" . uniqid() . "@inka.internal";
            $password_raw = bin2hex(random_bytes(8));
        } else {
            $role = $_POST['role'];
            $email = trim($_POST['email']);
            $password_raw = $_POST['password'];
        }
        
        $password = password_hash($password_raw, PASSWORD_BCRYPT);
        
        try {
            // Cek email duplikat
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetchColumn()) {
                throw new Exception("Email '$email' sudah terdaftar.");
            }

            $pdo->beginTransaction();
            $user_id = uuid();
            
            // Insert Auth
            $stmt1 = $pdo->prepare("INSERT INTO users (id, email, password) VALUES (?, ?, ?)");
            $stmt1->execute([$user_id, $email, $password]);
            
            // Insert Profile
            $stmt2 = $pdo->prepare("INSERT INTO profiles (id, full_name, jobdesk, role, branch_id) VALUES (?, ?, ?, ?, ?)");
            $stmt2->execute([$user_id, $full_name, $jobdesk, $role, $branch_id]);
            
            $pdo->commit();
            set_flash_msg("Akun pekerja berhasil dibuat!");
            header("Location: $redirect_url"); exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash_msg("Gagal menambah pekerja: " . $e->getMessage(), "error");
            header("Location: $redirect_url"); exit();
        }
    }
    
    elseif ($action === 'edit') {
        $id        = $_POST['id'];
        $full_name = trim($_POST['full_name']);
        $jobdesk   = trim($_POST['jobdesk'] ?? '');
        $role      = $_POST['role'];
        $branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;
        
        try {
            $stmt = $pdo->prepare("UPDATE profiles SET full_name=?, jobdesk=?, role=?, branch_id=? WHERE id=?");
            $stmt->execute([$full_name, $jobdesk, $role, $branch_id, $id]);
            set_flash_msg("Data pekerja berhasil diperbarui!");
            header("Location: $redirect_url"); exit();
        } catch (Exception $e) {
            set_flash_msg("Gagal memperbarui data: " . $e->getMessage(), "error");
            header("Location: $redirect_url"); exit();
        }
    }

    // 3. RESET PASSWORD KARYAWAN
    elseif ($action === 'reset_password') {
        $id       = $_POST['id'];
        $new_pass = password_hash('inka2026', PASSWORD_BCRYPT);
        try {
            $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->execute([$new_pass, $id]);
            set_flash_msg("Password berhasil direset menjadi: inka2026");
            header("Location: $redirect_url"); exit();
        } catch (Exception $e) {
            set_flash_msg("Gagal mereset password: " . $e->getMessage(), "error");
            header("Location: $redirect_url"); exit();
        }
    }

    // 4. HAPUS KARYAWAN
    elseif ($action === 'delete') {
        $id = $_POST['id'];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM profiles WHERE id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            $pdo->commit();
            set_flash_msg("Akun pekerja berhasil dihapus permanen.");
            header("Location: $redirect_url"); exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash_msg("Gagal menghapus pekerja: " . $e->getMessage(), "error");
            header("Location: $redirect_url"); exit();
        }
    }
}

// Ambil pesan dari flash jika ada
$flash = get_flash_msg();
if ($flash) {
    $msg = $flash['msg'];
    $msg_type = $flash['type'];
}

// ── AMBIL DATA CABANG (Untuk Dropdown) ──────────────────────────
$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();

// ── FILTER CABANG — SPV default ke cabang sendiri ────────────────
$spv_default = (is_spv() && !empty($_SESSION['branch_id'])) ? $_SESSION['branch_id'] : '';
$filter_branch = $_GET['branch_id'] ?? $spv_default;

// ── AMBIL DATA KARYAWAN (Selain Member) ─────────────────────────
$sql_staff = "SELECT u.id, u.email, p.full_name, p.jobdesk, p.role, p.branch_id, b.name as branch_name 
    FROM users u 
    JOIN profiles p ON u.id = p.id 
    LEFT JOIN branches b ON p.branch_id = b.id 
    WHERE p.role != 'member'";
$params_staff = [];

if ($filter_branch) {
    $sql_staff .= " AND p.branch_id = ?";
    $params_staff[] = $filter_branch;
}

$sql_staff .= " ORDER BY p.role, p.full_name";
$stmt = $pdo->prepare($sql_staff);
$stmt->execute($params_staff);
$staff_list = $stmt->fetchAll();

// Helper Role Label
function format_role($role) {
    $roles = [
        'owner'       => ['label'=>'Owner', 'color'=>'bg-purple-100 text-purple-700 border-purple-200'],
        'manager_ops' => ['label'=>'Manager Ops', 'color'=>'bg-indigo-100 text-indigo-700 border-indigo-200'],
        'spv'         => ['label'=>'Supervisor', 'color'=>'bg-indigo-100 text-indigo-700 border-indigo-200'],
        'admin'       => ['label'=>'Admin Pusat', 'color'=>'bg-blue-100 text-blue-700 border-blue-200'],
        'admin_depok' => ['label'=>'Admin Depok', 'color'=>'bg-cyan-100 text-cyan-700 border-cyan-200'],
        'admin_bsd'   => ['label'=>'Admin BSD', 'color'=>'bg-sky-100 text-sky-700 border-sky-200'],
        'mekanik'     => ['label'=>'Mekanik', 'color'=>'bg-orange-100 text-orange-700 border-orange-200'],
    ];
    return $roles[$role] ?? ['label'=>$role, 'color'=>'bg-slate-100 text-slate-700'];
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
    <!-- Topbar -->
    <header class="h-16 sm:h-20 flex-shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu"></i>
        </button>

        <div class="flex-1 lg:ml-0 px-4 flex items-center gap-3">
            <h1 class="text-sm sm:text-lg font-semibold text-slate-900 truncate uppercase tracking-widest opacity-60">
                Manajemen Karyawan
            </h1>
            <?php if (is_spv_branch()): ?>
            <span class="px-3 py-1 bg-indigo-50 text-indigo-700 text-[10px] font-black uppercase tracking-widest rounded-full border border-indigo-100 flex items-center gap-1">
                <i data-lucide="map-pin" class="w-3 h-3"></i>
                <?php echo htmlspecialchars(get_spv_branch_label()); ?>
            </span>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-3">
            <form method="GET" class="hidden md:flex items-center gap-2">
                <?php if (!is_spv_branch()): ?>
                <select name="branch_id" onchange="this.form.submit()" class="px-4 py-2 rounded-xl bg-white border border-slate-200 text-xs font-bold text-slate-700 outline-none focus:ring-4 focus:ring-blue-500/10">
                    <option value="">Semua Cabang</option>
                    <?php foreach($branches as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </form>
            <button onclick="openModal('modalAddLogin')" class="bg-indigo-600 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-indigo-700 transition-all flex items-center gap-2 shadow-lg shadow-indigo-500/20 active:scale-95">
                <i data-lucide="key" class="w-4 h-4"></i> Tambah Akun Login
            </button>
            <button onclick="openModal('modalAdd')" class="bg-blue-600 text-white px-5 py-2.5 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-blue-700 transition-all flex items-center gap-2 shadow-lg shadow-blue-500/20 active:scale-95">
                <i data-lucide="user-plus" class="w-4 h-4"></i> Tambah Pekerja
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

        <!-- Table View -->
        <div class="bg-white rounded-3xl shadow-lg border border-slate-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400 font-black">
                            <th class="p-5">Nama Karyawan</th>
                            <th class="p-5">Jobdesk / Posisi</th>
                            <th class="p-5">Akses Role</th>
                            <th class="p-5">Cabang Penempatan</th>
                            <th class="p-5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($staff_list as $staff): 
                            $r_cfg = format_role($staff['role']);
                        ?>
                        <tr class="hover:bg-slate-50/50 transition-colors group">
                            <td class="p-5">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-500 font-black text-sm uppercase">
                                        <?php echo substr($staff['full_name'], 0, 2); ?>
                                    </div>
                                    <div>
                                        <p class="font-black text-slate-900 text-sm"><?php echo htmlspecialchars($staff['full_name']); ?></p>
                                        <p class="text-xs text-slate-400 font-medium">
                                            <?php echo strpos($staff['email'], '@inka.internal') !== false ? '<span class="italic text-[10px]">Pekerja Lapangan</span>' : htmlspecialchars($staff['email']); ?>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-5">
                                <p class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($staff['jobdesk'] ?: '-'); ?></p>
                            </td>
                            <td class="p-5">
                                <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border <?php echo $r_cfg['color']; ?>">
                                    <?php echo $r_cfg['label']; ?>
                                </span>
                            </td>
                            <td class="p-5">
                                <?php if ($staff['branch_name']): ?>
                                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                                        <i data-lucide="map-pin" class="w-3.5 h-3.5 text-blue-500"></i>
                                        <?php echo htmlspecialchars($staff['branch_name']); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-xs font-semibold text-slate-400 italic">Pusat / Semua Cabang</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-5 text-right">
                                <?php if ($staff['email'] !== 'owner@inka.com'): // Proteksi akun super admin ?>
                                <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <!-- Tombol Reset Password -->
                                    <form method="POST" action="" onsubmit="return confirm('Reset password untuk <?php echo $staff['full_name']; ?> menjadi: inka2026?');" class="inline">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="id" value="<?php echo $staff['id']; ?>">
                                        <button type="submit" class="w-8 h-8 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center hover:bg-slate-200 transition-colors" title="Reset Password">
                                            <i data-lucide="key" class="w-4 h-4"></i>
                                        </button>
                                    </form>

                                    <!-- Tombol Edit -->
                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($staff)); ?>)" class="w-8 h-8 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center hover:bg-amber-100 transition-colors" title="Edit Data">
                                        <i data-lucide="edit-2" class="w-4 h-4"></i>
                                    </button>

                                    <!-- Tombol Hapus -->
                                    <form method="POST" action="" onsubmit="return confirm('PERINGATAN: Hapus permanen akun <?php echo addslashes($staff['full_name']); ?>?');" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $staff['id']; ?>">
                                        <button type="submit" class="w-8 h-8 rounded-full bg-red-50 text-red-600 flex items-center justify-center hover:bg-red-100 transition-colors" title="Hapus Akun">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                </div>
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

<!-- ==========================================
     MODAL TAMBAH KARYAWAN
=========================================== -->
<div id="modalAdd" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalAdd')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-lg relative z-10 overflow-y-auto max-h-[90vh] custom-scrollbar transform scale-95 opacity-0 transition-all duration-300" id="modalAddContent">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="font-black text-slate-900 text-lg">Tambah Pekerja Cabang</h3>
                <button type="button" onclick="closeModal('modalAdd')" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form action="" method="POST" class="p-6">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="type" value="pekerja">

                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Lengkap</label>
                        <input type="text" name="full_name" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Jobdesk / Posisi</label>
                        <input type="text" name="jobdesk" placeholder="Misal: Montir, Helper, Driver" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                    </div>
                    <div class="border-t border-slate-100 pt-4">
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Penempatan Cabang</label>
                            <select name="branch_id" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-bold text-slate-700">
                                <option value="">- Pilih Cabang -</option>
                                <?php foreach($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" <?php echo ($filter_branch == $b['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalAdd')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/30 uppercase tracking-widest">Simpan Akun</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     MODAL TAMBAH AKUN LOGIN (SPV, Admin, Owner)
=========================================== -->
<div id="modalAddLogin" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalAddLogin')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-lg relative z-10 overflow-y-auto max-h-[90vh] custom-scrollbar transform scale-95 opacity-0 transition-all duration-300" id="modalAddLoginContent">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-indigo-50">
                <h3 class="font-black text-indigo-900 text-lg">Tambah Akun Login</h3>
                <button type="button" onclick="closeModal('modalAddLogin')" class="w-8 h-8 flex items-center justify-center rounded-full bg-white/50 text-indigo-700 hover:bg-white"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form action="" method="POST" class="p-6">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="type" value="login">

                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Lengkap</label>
                        <input type="text" name="full_name" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 outline-none text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Email Login</label>
                        <input type="email" name="email" required placeholder="email@inka.com" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 outline-none text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Password</label>
                        <input type="password" name="password" required value="inka2026" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 outline-none text-sm font-semibold">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 border-t border-slate-100 pt-4">
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Akses Role</label>
                            <select name="role" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 outline-none text-sm font-bold text-slate-700">
                                <option value="spv">Supervisor</option>
                                <option value="admin">Admin Pusat</option>
                                <option value="admin_depok">Admin Depok</option>
                                <option value="admin_bsd">Admin BSD</option>
                                <option value="manager_ops">Manager Ops</option>
                                <option value="owner">Owner</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Penempatan Cabang</label>
                            <select name="branch_id" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 outline-none text-sm font-bold text-slate-700">
                                <option value="">- Pusat / Semua Cabang -</option>
                                <?php foreach($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('modalAddLogin')" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-colors uppercase tracking-widest">Batal</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 shadow-lg shadow-indigo-500/30 uppercase tracking-widest">Simpan Akun Login</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     MODAL EDIT KARYAWAN
=========================================== -->
<div id="modalEdit" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalEdit')"></div>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-lg relative z-10 overflow-y-auto max-h-[90vh] custom-scrollbar transform scale-95 opacity-0 transition-all duration-300" id="modalEditContent">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-amber-50">
                <h3 class="font-black text-amber-900 text-lg">Edit Data Karyawan</h3>
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
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Jobdesk / Posisi</label>
                        <input type="text" name="jobdesk" id="editJobdesk" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 outline-none text-sm font-semibold">
                    </div>
                    <div id="editEmailField">
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Email (Tidak bisa diubah)</label>
                        <input type="email" id="editEmail" readonly class="w-full px-4 py-3 rounded-xl bg-slate-100 border border-slate-200 text-sm font-semibold text-slate-400 cursor-not-allowed">
                    </div>
                    <div class="grid grid-cols-2 gap-4 border-t border-slate-100 pt-4">
                        <div id="editRoleField">
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Akses Role</label>
                            <select name="role" id="editRole" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 outline-none text-sm font-bold text-slate-700">
                                <option value="admin">Admin Pusat</option>
                                <option value="admin_depok">Admin Depok</option>
                                <option value="admin_bsd">Admin BSD</option>
                                <option value="spv">Supervisor</option>
                                <option value="manager_ops">Manager Ops</option>
                                <option value="owner">Owner</option>
                                <option value="mekanik">Mekanik (Tanpa Akses Login)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Penempatan Cabang</label>
                            <select name="branch_id" id="editBranch" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 outline-none text-sm font-bold text-slate-700">
                                <option value="">- Pusat / Semua Cabang -</option>
                                <?php foreach($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
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

    function openEditModal(staff) {
        document.getElementById('editId').value = staff.id;
        document.getElementById('editFullName').value = staff.full_name;
        document.getElementById('editJobdesk').value = staff.jobdesk || '';
        document.getElementById('editEmail').value = staff.email;
        document.getElementById('editRole').value = staff.role;
        document.getElementById('editBranch').value = staff.branch_id || '';
        
        // Sembunyikan Email & Role jika ini adalah pekerja lapangan (@inka.internal)
        const isWorker = staff.email.includes('@inka.internal');
        const emailField = document.getElementById('editEmailField');
        const roleField = document.getElementById('editRoleField');
        
        if (isWorker) {
            emailField.classList.add('hidden');
            roleField.classList.add('hidden');
        } else {
            emailField.classList.remove('hidden');
            roleField.classList.remove('hidden');
        }

        openModal('modalEdit');
    }
</script>
JS;
?>
<?php include '../includes/footer.php'; ?>
