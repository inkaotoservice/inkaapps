<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

auth_ready();

$role = get_role();
$is_owner_manager = in_array($role, ['owner', 'manager_ops']);
$is_branch_admin = in_array($role, ['admin', 'admin_depok', 'admin_bsd']);

if (!$is_owner_manager && !$is_branch_admin) {
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$user_branch_id = $_SESSION['branch_id'] ?? null;

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings']) && $is_owner_manager) {
        $booking_dp = preg_replace('/[^0-9]/', '', $_POST['booking_dp'] ?? '0');
        $receipt_logo_url = trim($_POST['receipt_logo_url'] ?? '');
        $receipt_notes = trim($_POST['receipt_notes'] ?? '');
        
        try {
            $pdo->beginTransaction();
            if ($booking_dp !== '') {
                $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('booking_dp', ?) ON DUPLICATE KEY UPDATE `value` = ?")->execute([$booking_dp, $booking_dp]);
            }
            if (isset($_POST['owner_wa_number'])) {
                $owner_wa = trim($_POST['owner_wa_number']);
                $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('owner_wa_number', ?) ON DUPLICATE KEY UPDATE `value` = ?")->execute([$owner_wa, $owner_wa]);
            }
            $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('receipt_logo_url', ?) ON DUPLICATE KEY UPDATE `value` = ?")->execute([$receipt_logo_url, $receipt_logo_url]);
            $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('receipt_notes', ?) ON DUPLICATE KEY UPDATE `value` = ?")->execute([$receipt_notes, $receipt_notes]);
            
            if (isset($_POST['payment_bank_name'])) {
                $bank_name = trim($_POST['payment_bank_name']);
                $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('payment_bank_name', ?) ON DUPLICATE KEY UPDATE `value` = ?")->execute([$bank_name, $bank_name]);
            }
            if (isset($_POST['payment_account_number'])) {
                $acc_number = trim($_POST['payment_account_number']);
                $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('payment_account_number', ?) ON DUPLICATE KEY UPDATE `value` = ?")->execute([$acc_number, $acc_number]);
            }
            if (isset($_POST['payment_account_name'])) {
                $acc_name = trim($_POST['payment_account_name']);
                $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('payment_account_name', ?) ON DUPLICATE KEY UPDATE `value` = ?")->execute([$acc_name, $acc_name]);
            }
            $pdo->commit();
            $success = "Pengaturan global berhasil diperbarui.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Gagal memperbarui pengaturan: " . $e->getMessage();
        }
    }

    if (isset($_POST['update_branch_settings'])) {
        $branch_id = $is_owner_manager ? $_POST['branch_id'] : $user_branch_id;
        $invoice_notes = trim($_POST['invoice_notes'] ?? '');
        $whatsapp_number = trim($_POST['whatsapp_number'] ?? '');
        
        if ($branch_id) {
            $logo_url = $_POST['existing_logo'] ?? '';
            
            if (isset($_FILES['branch_logo']) && $_FILES['branch_logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../assets/uploads/logos/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_tmp = $_FILES['branch_logo']['tmp_name'];
                $file_name = $_FILES['branch_logo']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_ext, $allowed_extensions)) {
                    $new_file_name = 'branch_' . $branch_id . '_' . time() . '.' . $file_ext;
                    $upload_path = $upload_dir . $new_file_name;
                    
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        $logo_url = $new_file_name;
                        // Hapus logo lama jika ada dan bukan URL eksternal
                        if (!empty($_POST['existing_logo']) && file_exists($upload_dir . $_POST['existing_logo'])) {
                            unlink($upload_dir . $_POST['existing_logo']);
                        }
                    } else {
                        $error = "Gagal mengunggah logo cabang.";
                    }
                } else {
                    $error = "Format file tidak didukung. Harap unggah gambar (JPG, PNG, GIF, WEBP).";
                }
            }
            
            if (!$error) {
                try {
                    $stmt = $pdo->prepare("UPDATE branches SET invoice_notes = ?, whatsapp_number = ?, logo_url = ? WHERE id = ?");
                    $stmt->execute([$invoice_notes, $whatsapp_number, $logo_url, $branch_id]);
                    $success = "Pengaturan cabang berhasil diperbarui.";
                } catch (Exception $e) {
                    $error = "Gagal: " . $e->getMessage();
                }
            }
        } else {
            $error = "Cabang tidak valid.";
        }
    }

    if (isset($_POST['update_profile']) && in_array($role, ['owner', 'manager_ops'])) {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $user_id = $_SESSION['user_id'];

        try {
            $pdo->beginTransaction();
            
            // Update Full Name
            if ($full_name !== '') {
                $stmt = $pdo->prepare("UPDATE profiles SET full_name = ? WHERE id = ?");
                $stmt->execute([$full_name, $user_id]);
                $_SESSION['full_name'] = $full_name; // Sync session
            }

            // Update Email (Username)
            if ($email !== '') {
                // Check if email is already taken by another user
                $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt_check->execute([$email, $user_id]);
                if ($stmt_check->fetch()) {
                    throw new Exception("Email '$email' sudah digunakan oleh akun lain.");
                }

                $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                $stmt->execute([$email, $user_id]);
                $_SESSION['email'] = $email; // Sync session
            }

            // Update Password if provided
            if ($new_password !== '') {
                if ($new_password === $confirm_password) {
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $user_id]);
                } else {
                    throw new Exception("Konfirmasi password baru tidak cocok.");
                }
            }

            $pdo->commit();
            $success = "Profil akun berhasil diperbarui.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Gagal memperbarui profil: " . $e->getMessage();
        }
    }
}

// Get current user profile for the form
$stmt_me = $pdo->prepare("SELECT p.full_name, u.email FROM profiles p JOIN users u ON p.id = u.id WHERE p.id = ?");
$stmt_me->execute([$_SESSION['user_id']]);
$my_profile = $stmt_me->fetch();

$stmt = $pdo->query("SELECT `key`, `value` FROM app_settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['key']] = $row['value'];
}

// Get branches for branch settings
$branches = [];
if ($is_owner_manager) {
    $branches = $pdo->query("SELECT id, name, logo_url, invoice_notes, whatsapp_number FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else if ($user_branch_id) {
    $stmt = $pdo->prepare("SELECT id, name, logo_url, invoice_notes, whatsapp_number FROM branches WHERE id = ?");
    $stmt->execute([$user_branch_id]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$page_title = 'Pengaturan';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

    <main class="flex-1 flex flex-col h-screen overflow-hidden bg-slate-50 relative">
        <!-- Mobile Header -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:hidden z-40">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-slate-900 rounded-xl flex items-center justify-center text-white">
                    <i data-lucide="settings" class="w-4 h-4"></i>
                </div>
                <h1 class="font-black text-slate-900 uppercase tracking-tight">Pengaturan</h1>
            </div>
            <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full'); document.getElementById('sidebar-overlay').classList.toggle('hidden');" class="p-2 bg-slate-100 text-slate-600 rounded-xl">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
        </header>

        <!-- Topbar Desktop -->
        <header class="hidden lg:flex h-20 bg-white/50 backdrop-blur-md border-b border-slate-200 items-center justify-between px-8 z-40">
            <div>
                <h1 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Pengaturan Sistem</h1>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">Konfigurasi Aplikasi Bengkel</p>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-4 lg:p-8">
            <div class="max-w-3xl mx-auto space-y-6">
                
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

                <!-- AKUN SAYA SECTION (Hanya untuk Owner & Manager) -->
                <?php if (in_array($role, ['owner', 'manager_ops'])): ?>
                <form method="POST">
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden mb-6">
                        <div class="px-6 py-5 border-b border-slate-100 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center">
                                <i data-lucide="user" class="w-5 h-5 text-amber-600"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-black uppercase tracking-widest text-slate-900">Profil Akun Saya</h2>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Update nama tampilan dan keamanan</p>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Nama Lengkap (Username Display)</label>
                                    <input type="text" name="full_name" required
                                        value="<?php echo htmlspecialchars($my_profile['full_name'] ?? ''); ?>"
                                        class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 outline-none transition-all font-semibold text-slate-900">
                                </div>
                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Email Login (Username)</label>
                                    <input type="email" name="email" required
                                        value="<?php echo htmlspecialchars($my_profile['email'] ?? ''); ?>"
                                        class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 outline-none transition-all font-semibold text-slate-900">
                                    <p class="mt-2 text-[9px] font-bold text-slate-400 uppercase tracking-tight">Hati-hati: Mengubah email akan mengubah ID login Anda.</p>
                                </div>
                                
                                <div class="md:col-span-2 pt-4 border-t border-slate-100 mt-2">
                                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-4">Ubah Password (Kosongkan jika tidak diganti)</h3>
                                </div>

                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Password Baru</label>
                                    <input type="password" name="new_password" placeholder="••••••••"
                                        class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 outline-none transition-all font-semibold text-slate-900">
                                </div>
                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Konfirmasi Password Baru</label>
                                    <input type="password" name="confirm_password" placeholder="••••••••"
                                        class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-amber-500 focus:ring-4 focus:ring-amber-500/10 outline-none transition-all font-semibold text-slate-900">
                                </div>
                            </div>
                            
                            <div class="mt-8 pt-6 border-t border-slate-100 flex justify-start">
                                <button type="submit" name="update_profile"
                                    class="w-full sm:w-auto h-12 px-8 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest transition-all shadow-lg shadow-blue-500/30 active:scale-95 flex items-center justify-center gap-2">
                                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                                    Simpan Perubahan Profil
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php endif; ?>

                <?php if ($is_owner_manager): ?>
                <form method="POST">
                    <!-- General Settings -->
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                        <div class="px-6 py-5 border-b border-slate-100 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center">
                                <i data-lucide="sliders" class="w-5 h-5 text-blue-600"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-black uppercase tracking-widest text-slate-900">General Settings</h2>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Pengaturan umum bengkel</p>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="space-y-6">
                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Nominal DP Booking Online</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-slate-400 font-semibold">Rp</span>
                                        </div>
                                        <input type="text" name="booking_dp" required
                                            value="<?php echo number_format((int)($settings['booking_dp'] ?? 50000), 0, ',', '.'); ?>"
                                            onkeyup="this.value = formatRupiah(this.value)"
                                            class="w-full pl-12 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Nama Bank</label>
                                        <input type="text" name="payment_bank_name" placeholder="Bank BCA"
                                            value="<?php echo htmlspecialchars($settings['payment_bank_name'] ?? 'Bank BCA'); ?>"
                                            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Nomor Rekening</label>
                                        <input type="text" name="payment_account_number" placeholder="123 456 7890"
                                            value="<?php echo htmlspecialchars($settings['payment_account_number'] ?? '1234567890'); ?>"
                                            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Atas Nama (A/N)</label>
                                        <input type="text" name="payment_account_name" placeholder="PT Inka Otoservice"
                                            value="<?php echo htmlspecialchars($settings['payment_account_name'] ?? 'PT Inka Otoservice'); ?>"
                                            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900">
                                    </div>
                                </div>
                                <div class="pt-4 border-t border-slate-100 flex justify-start">
                                    <button type="submit" name="update_settings"
                                        class="w-full sm:w-auto h-12 px-8 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest transition-all shadow-lg shadow-blue-500/30 active:scale-95 flex items-center justify-center gap-2">
                                        <i data-lucide="save" class="w-4 h-4"></i>
                                        Simpan Pengaturan
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- WA Laporan Settings -->
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden mt-6">
                        <div class="px-6 py-5 border-b border-slate-100 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center">
                                <i data-lucide="send" class="w-5 h-5 text-emerald-600"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-black uppercase tracking-widest text-slate-900">Konfigurasi Laporan WA</h2>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Tujuan pengiriman laporan sistem</p>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="space-y-6">
                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">No. WhatsApp Owner (Tujuan Laporan)</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <i data-lucide="phone" class="w-4 h-4 text-slate-400"></i>
                                        </div>
                                        <input type="text" name="owner_wa_number" placeholder="628123456789"
                                            value="<?php echo htmlspecialchars($settings['owner_wa_number'] ?? '6281234567890'); ?>"
                                            class="w-full pl-11 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 outline-none transition-all font-semibold text-slate-900">
                                    </div>
                                    <p class="mt-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Gunakan format angka diawali 62. Laporan pengambilan barang (supplier) akan dikirim ke nomor ini.</p>
                                </div>
                                <div class="pt-4 border-t border-slate-100 flex justify-start">
                                    <button type="submit" name="update_settings"
                                        class="w-full sm:w-auto h-12 px-8 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest transition-all shadow-lg shadow-blue-500/30 active:scale-95 flex items-center justify-center gap-2">
                                        <i data-lucide="save" class="w-4 h-4"></i>
                                        Simpan Pengaturan
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Receipt Settings -->
                    <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden mt-6">
                        <div class="px-6 py-5 border-b border-slate-100 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-purple-50 flex items-center justify-center">
                                <i data-lucide="receipt" class="w-5 h-5 text-purple-600"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-black uppercase tracking-widest text-slate-900">Pengaturan Nota / Struk</h2>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Kustomisasi tampilan nota cetak dan digital</p>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="space-y-6">
                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">URL Logo Nota (Opsional)</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <i data-lucide="image" class="w-4 h-4 text-slate-400"></i>
                                        </div>
                                        <input type="url" name="receipt_logo_url" placeholder="https://contoh.com/logo.png"
                                            value="<?php echo htmlspecialchars($settings['receipt_logo_url'] ?? ''); ?>"
                                            class="w-full pl-11 pr-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10 outline-none transition-all font-semibold text-slate-900">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Catatan Kaki Nota</label>
                                    <textarea name="receipt_notes" rows="3"
                                        class="w-full p-4 rounded-xl bg-slate-50 border border-slate-200 focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10 outline-none transition-all font-semibold text-slate-900"><?php echo htmlspecialchars($settings['receipt_notes'] ?? "Terima kasih atas kunjungan Anda!\nBarang yang sudah dibeli tidak dapat ditukar atau dikembalikan."); ?></textarea>
                                </div>
                                
                                <div class="pt-4 border-t border-slate-100 flex justify-start">
                                    <button type="submit" name="update_settings"
                                        class="w-full sm:w-auto h-12 px-8 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest transition-all shadow-lg shadow-blue-500/30 active:scale-95 flex items-center justify-center gap-2">
                                        <i data-lucide="save" class="w-4 h-4"></i>
                                        Simpan Pengaturan
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
                <?php endif; ?>

                <?php foreach ($branches as $br): ?>
                <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden mt-6">
                    <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center">
                                <i data-lucide="store" class="w-5 h-5 text-emerald-600"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-black uppercase tracking-widest text-slate-900">Pengaturan Cabang</h2>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($br['name']); ?> — <span class="text-blue-500"><?php echo htmlspecialchars($br['address'] ?? 'Alamat belum diatur'); ?></span></p>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="branch_id" value="<?php echo $br['id']; ?>">
                            <input type="hidden" name="existing_logo" value="<?php echo htmlspecialchars($br['logo_url'] ?? ''); ?>">
                            
                            <div class="space-y-6">
                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Logo Nota Cabang</label>
                                    <div class="flex items-center gap-6">
                                        <div class="w-20 h-20 bg-slate-100 rounded-2xl flex items-center justify-center overflow-hidden border border-slate-200 shrink-0">
                                            <?php if (!empty($br['logo_url'])): ?>
                                                <img src="../assets/uploads/logos/<?php echo htmlspecialchars($br['logo_url']); ?>" class="max-w-full max-h-full object-contain">
                                            <?php else: ?>
                                                <i data-lucide="image" class="w-8 h-8 text-slate-300"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-1">
                                            <input type="file" name="branch_logo" accept="image/*" class="w-full text-sm font-bold text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:uppercase file:tracking-widest file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 transition-all cursor-pointer">
                                            <p class="mt-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Pilih gambar logo baru (PNG/JPG) untuk mengganti yang lama.</p>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">No. WhatsApp Admin Cabang</label>
                                    <input type="text" name="whatsapp_number" value="<?php echo htmlspecialchars($br['whatsapp_number'] ?? ''); ?>" placeholder="628123456789"
                                        class="w-full p-4 rounded-xl bg-slate-50 border border-slate-200 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 outline-none transition-all font-semibold text-slate-900">
                                    <p class="mt-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Gunakan format angka saja diawali 62 (Contoh: 62812...)</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Term & Conditions Cabang</label>
                                    <textarea name="invoice_notes" rows="3" placeholder="Gunakan catatan bawaan global jika dikosongkan..."
                                        class="w-full p-4 rounded-xl bg-slate-50 border border-slate-200 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 outline-none transition-all font-semibold text-slate-900"><?php echo htmlspecialchars($br['invoice_notes'] ?? ""); ?></textarea>
                                    <p class="mt-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Pesan khusus yang tampil di bagian bawah nota untuk cabang ini.</p>
                                </div>
                                
                                <div class="pt-4 border-t border-slate-100 flex justify-start">
                                    <button type="submit" name="update_branch_settings"
                                        class="w-full sm:w-auto h-12 px-8 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs uppercase tracking-widest transition-all shadow-lg shadow-blue-500/30 active:scale-95 flex items-center justify-center gap-2">
                                        <i data-lucide="save" class="w-4 h-4"></i>
                                        Simpan Pengaturan Cabang
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>

            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();
        function formatRupiah(angka) {
            let number_string = angka.replace(/[^,\d]/g, '').toString(),
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
    </script>
</div>
</body>
</html>
