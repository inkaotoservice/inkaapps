<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

// Akses untuk Admin (BSD/Depok), SPV, Owner, Manager
if (!has_role(['admin', 'admin_depok', 'admin_bsd', 'spv', 'owner', 'manager_ops'])) {
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$page_title = 'Konfirmasi Booking';
$msg = '';
$msg_type = '';
$branch_id = $_SESSION['branch_id'] ?? null;
$role = get_role();

// ── LOGIK KONFIRMASI (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'confirm_booking') {
        $booking_id = $_POST['booking_id'];
        $input_branch = $branch_id ?: ($_POST['branch_id'] ?? null);

        if ($booking_id && $input_branch) {
            try {
                $pdo->beginTransaction();

                // 1. Update status booking ke 'processing'
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'processing', branch_id = ? WHERE id = ?");
                $stmt->execute([$input_branch, $booking_id]);

                // 2. Ambil data booking untuk buat transaksi
                $stmt_b = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
                $stmt_b->execute([$booking_id]);
                $booking = $stmt_b->fetch();

                // 3. Cek apakah sudah ada transaksi draft untuk booking ini (biar gak double)
                $stmt_check = $pdo->prepare("SELECT id FROM transactions WHERE booking_id = ?");
                $stmt_check->execute([$booking_id]);
                
                if (!$stmt_check->fetch()) {
                    // 4. Buat Transaksi Draft
                    $tx_id = uuid();
                    $stmt_tx = $pdo->prepare("INSERT INTO transactions (id, customer_name, branch_id, booking_id, total_amount, status) VALUES (?, ?, ?, ?, 0, 'Draft')");
                    $stmt_tx->execute([$tx_id, $booking['customer_name'], $input_branch, $booking_id]);
                }

                $pdo->commit();
                $msg = "Booking berhasil dikonfirmasi! Kendaraan telah masuk ke Antrian.";
                $msg_type = "success";
                
                // Jika berhasil via tombol "Konfirmasi", beri delay redirect ke Antrian
                if (isset($_POST['is_list_action'])) {
                    header("Location: antrian.php?success=1");
                    exit();
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "Gagal konfirmasi: " . $e->getMessage();
                $msg_type = "error";
            }
        } else {
            $msg = "Harap pilih cabang terlebih dahulu.";
            $msg_type = "error";
        }
    }
}

// ── PENCARIAN KODE BOOKING ─────────────────────────────────────────
$found_booking = null;
if (isset($_GET['search_code']) && !empty(trim($_GET['search_code']))) {
    $search = strtoupper(trim($_GET['search_code']));
    $stmt_search = $pdo->prepare("SELECT * FROM bookings WHERE booking_code = ?");
    $stmt_search->execute([$search]);
    $found_booking = $stmt_search->fetch();

    if (!$found_booking) {
        $msg = "Kode Booking '$search' tidak ditemukan.";
        $msg_type = "error";
    } elseif ($found_booking['status'] !== 'pending') {
        $msg = "Booking '$search' sudah diproses atau dibatalkan.";
        $msg_type = "error";
        $found_booking = null;
    }
}

// ── AMBIL DATA CABANG (Jika Owner/SPV) ────────────────────────────
$branches = [];
if (!$branch_id) {
    $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();
}

// ── AMBIL LIST BOOKING PENDING (Batas 10 terbaru) ─────────────────
$pending_sql = "SELECT b.*, br.name as branch_name 
                FROM bookings b 
                LEFT JOIN branches br ON b.branch_id = br.id 
                WHERE b.status = 'pending' AND b.booking_type = 'online'";
$pending_params = [];

// Filter cabang jika role-nya admin spesifik
if ($branch_id) {
    $pending_sql .= " AND (b.branch_id = ? OR b.branch_id IS NULL)";
    $pending_params[] = $branch_id;
}

$pending_sql .= " ORDER BY b.service_date ASC, b.service_time ASC LIMIT 10";
$stmt_pending = $pdo->prepare($pending_sql);
$stmt_pending->execute($pending_params);
$pending_list = $stmt_pending->fetchAll();

?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-50 relative">
    <!-- Topbar -->
    <header class="h-16 sm:h-20 flex-shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu"></i>
        </button>

        <div class="flex-1 px-4">
            <h1 class="text-sm sm:text-lg font-semibold text-slate-900 truncate uppercase tracking-widest opacity-60">
                Konfirmasi Booking
            </h1>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar">
        
        <?php if ($msg): ?>
        <div class="mb-6 max-w-4xl mx-auto p-4 rounded-2xl flex items-center gap-3 font-semibold text-sm <?php echo $msg_type === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-red-50 text-red-700 border border-red-100'; ?>">
            <i data-lucide="<?php echo $msg_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 shrink-0"></i>
            <?php echo $msg; ?>
        </div>
        <?php endif; ?>

        <div class="max-w-4xl mx-auto space-y-8">
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- KOLOM PENCARIAN (Kiri - 2 Bagian) -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Search Card -->
                    <div class="bg-white rounded-[2rem] shadow-xl shadow-slate-200 border border-slate-100 p-2">
                        <form action="" method="GET" class="flex flex-col sm:flex-row gap-2">
                            <div class="relative flex-1">
                                <i data-lucide="hash" class="absolute left-6 top-1/2 -translate-y-1/2 text-slate-400 w-5 h-5"></i>
                                <input type="text" name="search_code" placeholder="Cari Kode Booking..." autocomplete="off"
                                       class="w-full h-14 pl-14 pr-6 bg-slate-50 border-none rounded-2xl focus:ring-2 focus:ring-indigo-300 outline-none text-base font-black uppercase tracking-widest placeholder:text-slate-300 placeholder:font-medium placeholder:text-sm placeholder:tracking-normal"
                                       value="<?php echo htmlspecialchars($_GET['search_code'] ?? ''); ?>">
                            </div>
                            <button type="submit" class="h-14 px-8 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-black text-sm uppercase tracking-widest flex items-center justify-center gap-2 transition-all active:scale-95 shadow-lg shadow-indigo-500/20">
                                <i data-lucide="search" class="w-5 h-5"></i> Cari
                            </button>
                        </form>
                    </div>

                    <!-- Result Display -->
                    <?php if ($found_booking): ?>
                    <div class="bg-white border border-slate-100 shadow-2xl rounded-[2rem] overflow-hidden animate-in fade-in zoom-in duration-300">
                        <div class="bg-gradient-to-r from-indigo-600 to-blue-700 p-6 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="bg-white/20 border-none text-white text-[9px] font-black px-3 py-1 rounded-full uppercase tracking-widest mb-3 inline-block">✅ Booking Ditemukan</span>
                                    <h3 class="text-xl font-black"><?php echo htmlspecialchars($found_booking['customer_name']); ?></h3>
                                    <p class="text-blue-200 font-mono text-sm font-bold mt-1 tracking-widest"><?php echo $found_booking['booking_code']; ?></p>
                                </div>
                                <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center backdrop-blur-md">
                                    <i data-lucide="user-check" class="w-6 h-6"></i>
                                </div>
                            </div>
                        </div>

                        <div class="p-6 space-y-6">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <div class="flex items-start gap-4">
                                    <div class="p-3 bg-slate-50 rounded-2xl text-slate-400"><i data-lucide="car" class="w-5 h-5"></i></div>
                                    <div>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Kendaraan</p>
                                        <p class="font-bold text-slate-900"><?php echo htmlspecialchars($found_booking['car_model']); ?></p>
                                        <p class="text-xs text-blue-600 font-mono font-black mt-0.5 uppercase tracking-tighter"><?php echo $found_booking['license_plate']; ?></p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-4">
                                    <div class="p-3 bg-slate-50 rounded-2xl text-slate-400"><i data-lucide="calendar" class="w-5 h-5"></i></div>
                                    <div>
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Jadwal</p>
                                        <p class="font-bold text-slate-900"><?php echo date('d F Y', strtotime($found_booking['service_date'])); ?></p>
                                        <p class="text-xs text-slate-500 font-bold"><?php echo substr($found_booking['service_time'], 0, 5); ?> WIB</p>
                                    </div>
                                </div>
                            </div>

                            <?php if ($found_booking['is_online'] && $found_booking['dp_receipt']): ?>
                            <div class="p-4 bg-blue-50 rounded-2xl border border-blue-100 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-white rounded-xl shadow-sm flex items-center justify-center text-blue-500">
                                        <i data-lucide="receipt" class="w-5 h-5"></i>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-black text-blue-600 uppercase tracking-widest">Bukti Transfer DP</p>
                                        <p class="text-xs font-semibold text-slate-700">Telah diunggah pelanggan</p>
                                    </div>
                                </div>
                                <a href="../assets/uploads/receipts/<?php echo $found_booking['dp_receipt']; ?>" target="_blank"
                                   class="px-4 py-2 bg-white text-blue-600 rounded-xl font-bold text-xs shadow-sm hover:shadow-md transition-all uppercase tracking-widest border border-blue-100">
                                    Lihat Bukti
                                </a>
                            </div>
                            <?php endif; ?>

                            <form action="" method="POST" class="pt-4 border-t border-slate-50">
                                <input type="hidden" name="action" value="confirm_booking">
                                <input type="hidden" name="booking_id" value="<?php echo $found_booking['id']; ?>">
                                
                                <?php if (!$branch_id): ?>
                                <div class="mb-6">
                                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Assign ke Cabang</label>
                                    <select name="branch_id" required class="w-full px-4 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-indigo-500 outline-none font-bold text-slate-700">
                                        <option value="">- Pilih Cabang -</option>
                                        <?php foreach($branches as $b): ?>
                                            <option value="<?php echo $b['id']; ?>" <?php echo $found_booking['branch_id'] === $b['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($b['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <button type="submit" class="w-full h-14 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-black text-sm uppercase tracking-widest shadow-lg shadow-emerald-500/20 transition-all active:scale-95 flex items-center justify-center gap-2">
                                    <i data-lucide="zap" class="w-5 h-5"></i> Konfirmasi & Mulai Proses
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php elseif (!isset($_GET['search_code'])): ?>
                    <div class="bg-white border-2 border-dashed border-slate-200 rounded-3xl p-8 text-center text-slate-400">
                        <i data-lucide="hash" class="w-12 h-12 mx-auto mb-3 opacity-20"></i>
                        <p class="font-bold">Masukkan Kode Booking Pelanggan</p>
                        <p class="text-xs mt-1">Kode booking didapat dari aplikasi mobile pelanggan.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- DAFTAR PENDING (Kanan - 1 Bagian) -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between px-2">
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] flex items-center gap-2">
                            <i data-lucide="clock" class="w-3.5 h-3.5 text-indigo-500"></i> Booking Online
                        </h3>
                        <span class="bg-indigo-50 text-indigo-600 font-black text-[9px] px-2 py-0.5 rounded-full border border-indigo-100">
                            <?php echo count($pending_list); ?> Pending
                        </span>
                    </div>

                    <div class="space-y-4 max-h-[600px] overflow-y-auto pr-2 custom-scrollbar">
                        <?php foreach ($pending_list as $b): ?>
                        <div class="bg-white border border-slate-100 p-4 rounded-3xl hover:border-indigo-200 hover:shadow-xl hover:shadow-indigo-500/5 transition-all group relative">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <p class="text-xs font-black text-slate-900 group-hover:text-indigo-600 transition-colors"><?php echo htmlspecialchars($b['customer_name']); ?></p>
                                    <p class="text-[10px] font-mono font-bold text-slate-400 tracking-wider"><?php echo $b['booking_code']; ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] font-black text-indigo-600"><?php echo substr($b['service_time'], 0, 5); ?></p>
                                    <p class="text-[9px] font-bold text-slate-400"><?php echo date('d M', strtotime($b['service_date'])); ?></p>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-3 py-2.5 border-y border-slate-50 mb-4">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <i data-lucide="car" class="w-3 h-3 text-slate-300"></i>
                                    <p class="text-[10px] font-bold text-slate-500 truncate"><?php echo htmlspecialchars($b['car_model']); ?></p>
                                </div>
                                <div class="w-1 h-1 rounded-full bg-slate-200"></div>
                                <p class="text-[10px] font-mono font-black text-slate-900 uppercase"><?php echo $b['license_plate']; ?></p>
                            </div>
                            
                            <?php if ($b['dp_receipt']): ?>
                            <div class="mb-4">
                                <a href="../assets/uploads/receipts/<?php echo $b['dp_receipt']; ?>" target="_blank"
                                   class="flex items-center justify-center gap-2 w-full py-2 bg-blue-50 text-blue-600 rounded-lg text-[10px] font-black uppercase tracking-widest border border-blue-100 hover:bg-blue-100 transition-colors">
                                    <i data-lucide="receipt" class="w-3 h-3"></i> Lihat Bukti DP
                                </a>
                            </div>
                            <?php endif; ?>

                            <form action="" method="POST">
                                <input type="hidden" name="action" value="confirm_booking">
                                <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                                <input type="hidden" name="is_list_action" value="1">
                                <?php if ($branch_id): ?>
                                <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                                <?php endif; ?>

                                <button type="submit" class="w-full h-10 rounded-xl bg-slate-900 group-hover:bg-indigo-600 text-white font-black text-[10px] uppercase tracking-widest transition-all shadow-md">
                                    Konfirmasi
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>

                        <?php if (empty($pending_list)): ?>
                        <div class="py-16 text-center bg-white rounded-3xl border border-dashed border-slate-100 px-6">
                            <i data-lucide="inbox" class="w-10 h-10 mx-auto mb-3 opacity-20"></i>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Tidak ada booking online pending.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>
