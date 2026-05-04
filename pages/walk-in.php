<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Akses untuk Admin, SPV, dan Owner
if (!has_role(['admin','admin_depok','admin_bsd','spv','owner','manager_ops'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Pendaftaran Service (Walk-in)';
$msg = '';
$msg_type = '';
$branch_id = $_SESSION['branch_id'] ?? null;

// ── PROSES PENDAFTARAN MOBIL BARU ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $customer_name  = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $car_model      = trim($_POST['car_model']);
    $license_plate  = strtoupper(trim($_POST['license_plate']));
    $notes          = trim($_POST['notes']);
    $input_branch   = $branch_id ?: ($_POST['branch_id'] ?? null); // Owner/SPV pilih cabang

    if ($customer_name && $car_model && $license_plate && $input_branch) {
        try {
            // Generate kode unik (Misal: WLK-TGL-RANDOM)
            $booking_code = 'WLK-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
            
            $stmt = $pdo->prepare("
                INSERT INTO bookings (id, customer_name, customer_phone, car_model, license_plate, booking_code, booking_type, service_date, service_time, status, branch_id, notes) 
                VALUES (?, ?, ?, ?, ?, ?, 'walk-in', CURRENT_DATE, CURRENT_TIME, 'pending', ?, ?)
            ");
            $stmt->execute([uuid(), $customer_name, $customer_phone, $car_model, $license_plate, $booking_code, $input_branch, $notes]);
            
            $msg = "Mobil $license_plate berhasil didaftarkan dan masuk ke antrian!"; 
            $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Gagal mendaftarkan service: " . $e->getMessage(); 
            $msg_type = "error";
        }
    } else {
        $msg = "Harap lengkapi semua form wajib (termasuk pemilihan cabang)."; 
        $msg_type = "error";
    }
}

// ── AMBIL DATA CABANG (Jika Owner/SPV) ────────────────────────────
$branches = [];
if (!$branch_id) {
    $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();
}

// ── AMBIL RIWAYAT HARI INI ────────────────────────────────────────
$today_sql = "SELECT b.*, br.name as branch_name 
              FROM bookings b 
              LEFT JOIN branches br ON b.branch_id = br.id 
              WHERE b.service_date = CURRENT_DATE AND b.booking_type = 'walk-in'";
$today_params = [];

if ($branch_id && is_admin()) {
    $today_sql .= " AND b.branch_id = ?";
    $today_params[] = $branch_id;
}
$today_sql .= " ORDER BY b.created_at DESC";

$stmt_today = $pdo->prepare($today_sql);
$stmt_today->execute($today_params);
$recent_walkins = $stmt_today->fetchAll();

$status_colors = [
    'pending'    => 'bg-amber-100 text-amber-700',
    'processing' => 'bg-blue-100 text-blue-700',
    'completed'  => 'bg-emerald-100 text-emerald-700',
    'cancelled'  => 'bg-red-100 text-red-700',
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
                Pendaftaran Service Baru
            </h1>
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

        <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-5 gap-6">
            
            <!-- FORM PENDAFTARAN (Kiri - 3 Kolom) -->
            <div class="lg:col-span-3">
                <div class="bg-white rounded-3xl shadow-lg border border-slate-100 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-blue-600 to-indigo-700 text-white flex items-center gap-4">
                        <div class="w-12 h-12 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-sm shadow-inner">
                            <i data-lucide="car-front" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-black tracking-tight">Form Pendaftaran (Walk-in)</h2>
                            <p class="text-blue-200 text-[10px] uppercase tracking-widest mt-1 font-bold">Data akan otomatis masuk ke Antrian</p>
                        </div>
                    </div>
                    
                    <form action="" method="POST" class="p-6 space-y-6">
                        <input type="hidden" name="action" value="register">

                        <?php if (!$branch_id): // Owner atau SPV harus pilih cabang tempat mobil didaftarkan ?>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Lokasi Bengkel / Cabang <span class="text-red-500">*</span></label>
                            <select name="branch_id" required class="w-full px-4 py-3.5 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-bold text-slate-700">
                                <option value="">- Pilih Cabang -</option>
                                <?php foreach($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Data Pelanggan -->
                            <div class="space-y-4">
                                <h3 class="text-xs font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2 flex items-center gap-2">
                                    <i data-lucide="user" class="w-4 h-4 text-blue-500"></i> Info Pelanggan
                                </h3>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Nama Pelanggan <span class="text-red-500">*</span></label>
                                    <input type="text" name="customer_name" required placeholder="Cth: Budi Santoso" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">No. WhatsApp</label>
                                    <input type="text" name="customer_phone" placeholder="Cth: 08123456789" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                                </div>
                            </div>

                            <!-- Data Kendaraan -->
                            <div class="space-y-4">
                                <h3 class="text-xs font-black text-slate-900 uppercase tracking-widest border-b border-slate-100 pb-2 flex items-center gap-2">
                                    <i data-lucide="car" class="w-4 h-4 text-blue-500"></i> Info Kendaraan
                                </h3>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Merek & Tipe Mobil <span class="text-red-500">*</span></label>
                                    <input type="text" name="car_model" required placeholder="Cth: Honda Brio RS" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Plat Nomor <span class="text-red-500">*</span></label>
                                    <input type="text" name="license_plate" required placeholder="Cth: B 1234 XYZ" style="text-transform:uppercase" class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-black text-slate-900 tracking-widest">
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Keluhan / Catatan (Opsional)</label>
                            <textarea name="notes" rows="3" placeholder="Contoh: AC kurang dingin, rem bunyi berdecit..." class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold leading-relaxed"></textarea>
                        </div>

                        <div class="pt-4 border-t border-slate-100">
                            <button type="submit" class="w-full bg-blue-600 text-white py-4 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-blue-700 transition-all shadow-xl shadow-blue-500/30 flex items-center justify-center gap-2 active:scale-95">
                                <i data-lucide="check-circle" class="w-4 h-4"></i>
                                Daftarkan Kendaraan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- RIWAYAT HARI INI (Kanan - 2 Kolom) -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- Status Box -->
                <div class="bg-gradient-to-br from-slate-900 to-slate-800 rounded-3xl p-6 text-white shadow-xl relative overflow-hidden">
                    <i data-lucide="clipboard-check" class="absolute -right-4 -bottom-4 w-32 h-32 text-white/5 rotate-12"></i>
                    <div class="relative z-10">
                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest mb-1">Pendaftaran Hari Ini</p>
                        <div class="flex items-end gap-3 mb-4">
                            <h3 class="text-5xl font-black tracking-tighter"><?php echo count($recent_walkins); ?></h3>
                            <span class="text-sm font-bold text-slate-400 mb-1.5">Kendaraan</span>
                        </div>
                        <a href="<?php echo BASE_URL; ?>pages/antrian.php" class="inline-flex items-center gap-2 text-xs font-black uppercase tracking-widest text-blue-400 hover:text-blue-300 transition-colors">
                            Lihat Papan Antrian <i data-lucide="arrow-right" class="w-4 h-4"></i>
                        </a>
                    </div>
                </div>

                <!-- List Recent -->
                <div class="bg-white rounded-3xl shadow-lg border border-slate-100 overflow-hidden flex flex-col" style="max-height: 500px;">
                    <div class="p-5 border-b border-slate-100 flex items-center gap-3 bg-slate-50/50">
                        <i data-lucide="clock" class="w-4 h-4 text-slate-400"></i>
                        <h3 class="font-black text-slate-900 text-sm uppercase tracking-wide">Baru Saja Didaftarkan</h3>
                    </div>
                    
                    <div class="overflow-y-auto custom-scrollbar flex-1 divide-y divide-slate-50">
                        <?php foreach ($recent_walkins as $w): ?>
                        <div class="p-5 hover:bg-slate-50 transition-colors">
                            <div class="flex justify-between items-start mb-2">
                                <span class="bg-slate-100 text-slate-900 font-black text-[10px] px-2 py-1 rounded border border-slate-200 tracking-widest uppercase">
                                    <?php echo htmlspecialchars($w['license_plate']); ?>
                                </span>
                                <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest <?php echo $status_colors[$w['status']]; ?>">
                                    <?php echo $w['status']; ?>
                                </span>
                            </div>
                            <p class="font-bold text-slate-900 text-sm truncate"><?php echo htmlspecialchars($w['customer_name']); ?></p>
                            <p class="text-xs text-slate-500 truncate mt-0.5"><?php echo htmlspecialchars($w['car_model']); ?></p>
                            
                            <div class="flex items-center justify-between mt-3 text-[9px] font-bold text-slate-400 uppercase tracking-widest">
                                <span><?php echo substr($w['service_time'], 0, 5); ?> WIB</span>
                                <?php if (!$branch_id): ?>
                                    <span><?php echo htmlspecialchars($w['branch_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if(empty($recent_walkins)): ?>
                        <div class="p-10 text-center text-slate-400 flex flex-col items-center justify-center h-full">
                            <i data-lucide="inbox" class="w-12 h-12 mb-3 opacity-30"></i>
                            <p class="font-semibold text-sm">Belum ada pendaftaran<br>Walk-in hari ini.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
