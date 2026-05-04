<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$step = $_GET['step'] ?? 1;
$booking_id = $_GET['id'] ?? null;

// Fetch settings
$stmt_dp = $pdo->query("SELECT `value` FROM app_settings WHERE `key` = 'booking_dp'");
$dp_amount = $stmt_dp->fetchColumn() ?: 50000;

// AJAX: Get booked times
if (isset($_GET['action']) && $_GET['action'] === 'get_booked_times') {
    $date = $_GET['date'] ?? '';
    $branch_id = $_GET['branch_id'] ?? '';
    
    if ($date && $branch_id) {
        // Ambil jam yang sudah dibooking dan belum dibatalkan
        $stmt = $pdo->prepare("SELECT service_time FROM bookings WHERE service_date = ? AND branch_id = ? AND status != 'cancelled'");
        $stmt->execute([$date, $branch_id]);
        $booked = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        header('Content-Type: application/json');
        echo json_encode(['booked_times' => $booked]);
        exit();
    }
}

// Step 1: Submit Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_booking'])) {
    $name = trim($_POST['customer_name']);
    $phone = trim($_POST['customer_phone']);
    $car_model = trim($_POST['car_model']);
    $license_plate = trim($_POST['license_plate']);
    $branch_id = $_POST['branch_id'];
    $service_date = $_POST['service_date'];
    $service_time = $_POST['service_time'];
    $notes = trim($_POST['notes']);
    
    // Create new booking with status awaiting_dp
    $id = uuid();
    $booking_code = 'BKG-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
    
    $stmt = $pdo->prepare("
        INSERT INTO bookings (id, customer_name, customer_phone, car_model, license_plate, branch_id, service_date, service_time, notes, booking_code, booking_type, status, is_online)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'online', 'pending', 1)
    ");
    $stmt->execute([$id, $name, $phone, $car_model, $license_plate, $branch_id, $service_date, $service_time, $notes, $booking_code]);
    
    header("Location: booking-online.php?step=2&id=" . urlencode($id));
    exit();
}

// Step 2: Confirm payment info seen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proceed_to_upload'])) {
    header("Location: booking-online.php?step=3&id=" . urlencode($booking_id));
    exit();
}

$error = '';

// Step 3: Proceed to final step (No file upload to system)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_dp'])) {
    // Update booking status to pending
    $stmt = $pdo->prepare("UPDATE bookings SET status = 'pending' WHERE id = ?");
    $stmt->execute([$booking_id]);
    header("Location: booking-online.php?step=4&id=" . urlencode($booking_id));
    exit();
}

// Fetch booking data for step 2 & 3
$booking = null;
if ($booking_id) {
    $stmt = $pdo->prepare("SELECT b.*, br.name as branch_name, br.whatsapp_number as branch_whatsapp FROM bookings b LEFT JOIN branches br ON b.branch_id = br.id WHERE b.id = ?");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    if (!$booking) {
        die("Booking tidak ditemukan.");
    }
}

// Fetch branches for step 1
$stmt_br = $pdo->query("SELECT * FROM branches ORDER BY name ASC");
$branches = $stmt_br->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Online — Inka Otoservice</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * { font-family: 'Inter', sans-serif; }
    <style>
        * { font-family: 'Inter', sans-serif; }
        .glass { background: rgba(255,255,255,0.92); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
        
        @keyframes slideIn {
            from { transform: translate(-50%, -20px); opacity: 0; }
            to { transform: translate(-50%, 0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translate(-50%, 0); opacity: 1; }
            to { transform: translate(-50%, -20px); opacity: 0; }
        }
        .toast-active { animation: slideIn 0.3s ease-out forwards; }
        .toast-inactive { animation: slideOut 0.3s ease-in forwards; }
    </style>
</head>
<body class="min-h-screen bg-slate-50 py-6 sm:py-12 px-4 relative overflow-y-auto">
    <!-- Toast Notification -->
    <div id="copyToast" class="fixed top-10 left-1/2 -translate-x-1/2 z-[100] hidden">
        <div class="bg-slate-900 text-white px-6 py-3 rounded-2xl shadow-2xl flex items-center gap-3 border border-white/10">
            <div class="w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center">
                <i data-lucide="check" class="w-3.5 h-3.5 text-white"></i>
            </div>
            <span class="text-xs font-bold uppercase tracking-widest">Berhasil Disalin!</span>
        </div>
    </div>

    <!-- Decorative bg -->
    <div class="fixed top-[-10%] left-[-10%] w-[60%] sm:w-[40%] h-[60%] sm:h-[40%] bg-blue-500/10 rounded-full blur-[120px] pointer-events-none z-0"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[60%] sm:w-[40%] h-[60%] sm:h-[40%] bg-indigo-500/10 rounded-full blur-[120px] pointer-events-none z-0"></div>

    <div class="w-full max-w-2xl mx-auto relative z-10">
        
        <!-- Header -->
        <div class="text-center mb-8 sm:mb-10">
            <div class="w-14 h-14 sm:w-16 sm:h-16 bg-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-blue-600/30">
                <i data-lucide="calendar-clock" class="w-7 h-7 sm:w-8 sm:h-8 text-white"></i>
            </div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight uppercase">Booking Online</h1>
            <p class="text-slate-500 mt-2 font-medium text-xs sm:text-sm px-4">Jadwalkan servis kendaraan Anda di Inka Otoservice tanpa antri lama.</p>
        </div>

        <!-- Card -->
        <div class="glass rounded-3xl sm:rounded-[2rem] shadow-xl border border-white/50 overflow-hidden bg-white/60">
            
            <?php if ($step == 1): ?>
            <!-- STEP 1: FORM PENGISIAN -->
            <div class="p-6 sm:p-8">
                <div class="flex items-center gap-3 mb-6 sm:mb-8 pb-4 border-b border-slate-200">
                    <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-sm shrink-0">1</div>
                    <h2 class="font-bold text-base sm:text-lg text-slate-800 uppercase tracking-wide">Detail Kendaraan & Jadwal</h2>
                </div>

                <form method="POST" action="booking-online.php?step=1" class="space-y-5 sm:space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Nama Lengkap</label>
                            <input type="text" name="customer_name" required placeholder="Budi Santoso"
                                class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900 placeholder-slate-400">
                        </div>
                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">No. WhatsApp</label>
                            <input type="text" name="customer_phone" required placeholder="08123456789"
                                class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900 placeholder-slate-400">
                        </div>
                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Merk & Tipe Kendaraan</label>
                            <input type="text" name="car_model" required placeholder="Honda CRV 2020"
                                class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900 placeholder-slate-400">
                        </div>
                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Plat Nomor</label>
                            <input type="text" name="license_plate" required placeholder="B 1234 ABC"
                                class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900 placeholder-slate-400 uppercase">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6 pt-4 border-t border-slate-200">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] sm:text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Cabang Inka Otoservice</label>
                            <select name="branch_id" required class="w-full px-4 py-3 sm:py-3.5 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-bold text-sm text-slate-800">
                                <option value="">-- Pilih Cabang Tujuan --</option>
                                <?php foreach ($branches as $br): ?>
                                    <option value="<?php echo $br['id']; ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] sm:text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Tanggal Kedatangan</label>
                            <div class="relative">
                                <input type="date" name="service_date" required min="<?php echo date('Y-m-d'); ?>"
                                    onclick="this.showPicker()"
                                    class="w-full px-4 py-3 sm:py-3.5 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-bold text-sm text-slate-800 cursor-pointer uppercase">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] sm:text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Jam Kedatangan</label>
                            <select name="service_time" required
                                class="w-full px-4 py-3 sm:py-3.5 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-bold text-sm text-slate-800 cursor-pointer">
                                <option value="">-- Pilih Jam --</option>
                                <option value="08:00">08:00 WIB</option>
                                <option value="09:00">09:00 WIB</option>
                                <option value="10:00">10:00 WIB</option>
                                <option value="11:00">11:00 WIB</option>
                                <option value="13:00">13:00 WIB</option>
                                <option value="14:00">14:00 WIB</option>
                                <option value="15:00">15:00 WIB</option>
                                <option value="16:00">16:00 WIB</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] sm:text-xs font-black text-slate-500 uppercase tracking-widest mb-2">Keluhan / Layanan yang Diinginkan</label>
                        <textarea name="notes" rows="3" placeholder="Contoh: Ganti oli rutin dan cek rem belakang yang bunyi"
                            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-medium text-sm text-slate-900 placeholder-slate-400"></textarea>
                    </div>

                    <div class="pt-6 sm:pt-8">
                        <button type="submit" name="submit_booking"
                            class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white py-4 px-4 rounded-xl font-black text-xs sm:text-sm uppercase tracking-widest shadow-xl shadow-blue-500/30 transition-all active:scale-95 flex items-center justify-center gap-2">
                            <span>Lanjut ke Pembayaran DP</span>
                            <i data-lucide="arrow-right" class="w-4 h-4 shrink-0"></i>
                        </button>
                    </div>
                </form>
            </div>

            <?php elseif ($step == 2 && $booking): ?>
            <!-- STEP 2: INFO PEMBAYARAN -->
            <div class="p-6 sm:p-8 text-center">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-200 text-left">
                    <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-sm shrink-0">2</div>
                    <h2 class="font-bold text-base sm:text-lg text-slate-800 uppercase tracking-wide">Informasi Pembayaran</h2>
                </div>

                <div class="bg-blue-50 border border-blue-100 rounded-2xl p-5 sm:p-6 text-center mb-6 sm:mb-8">
                    <p class="text-xs sm:text-sm font-semibold text-blue-600 mb-1">Jadwal Servis Anda:</p>
                    <p class="text-lg sm:text-xl font-bold text-slate-800"><?php echo date('d M Y', strtotime($booking['service_date'])); ?> - <?php echo $booking['service_time']; ?></p>
                </div>

                <div class="text-center mb-6 sm:mb-8">
                    <p class="text-xs sm:text-sm font-semibold text-slate-500 mb-1">Total DP yang harus ditransfer:</p>
                    <p class="text-3xl sm:text-4xl font-black text-slate-900"><?php echo rupiah($dp_amount); ?></p>
                </div>

                <div class="bg-slate-50 rounded-2xl p-5 sm:p-6 mb-8 border border-slate-200 text-left">
                    <h3 class="font-bold text-xs sm:text-sm text-slate-800 uppercase tracking-widest mb-1 flex items-center gap-2">
                        <i data-lucide="building-2" class="w-4 h-4 text-slate-400"></i> Rekening Pembayaran
                    </h3>
                    <p class="text-[10px] font-semibold text-slate-400 mb-4 italic">Silahkan lakukan pembayaran booking, klik tombol copy rekening, terimakasih</p>
                    <div class="flex items-center justify-between p-4 bg-white rounded-xl border border-slate-100 shadow-sm">
                        <div>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Bank BCA</p>
                            <p class="font-bold text-slate-800 text-lg sm:text-xl">123 456 7890</p>
                            <p class="text-xs sm:text-sm text-slate-500 font-medium">a.n. PT Inka Otoservice</p>
                        </div>
                        <button onclick="copyToClipboard('1234567890')" class="w-10 h-10 bg-blue-50 hover:bg-blue-100 rounded-lg flex items-center justify-center border border-blue-100 transition-all active:scale-90 group">
                            <i data-lucide="copy" class="w-5 h-5 text-blue-600 group-hover:scale-110 transition-transform"></i>
                        </button>
                    </div>
                </div>

                <form method="POST" action="booking-online.php?step=2&id=<?php echo $booking_id; ?>">
                    <button type="submit" name="proceed_to_upload"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white py-4 px-4 rounded-xl font-black text-xs sm:text-sm uppercase tracking-widest shadow-xl shadow-blue-500/30 transition-all active:scale-95 flex items-center justify-center gap-2">
                        <span>Saya Sudah Transfer Lanjut</span>
                        <i data-lucide="arrow-right" class="w-4 h-4 shrink-0"></i>
                    </button>
                </form>
            </div>

            <?php elseif ($step == 3 && $booking): ?>
            <!-- STEP 3: UPLOAD BUKTI -->
            <div class="p-6 sm:p-8">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-200">
                    <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold text-sm shrink-0">3</div>
                    <h2 class="font-bold text-base sm:text-lg text-slate-800 uppercase tracking-wide">Upload Bukti & Konfirmasi</h2>
                </div>

                <?php if ($error): ?>
                <div class="bg-red-50 border border-red-100 text-red-600 p-4 rounded-2xl flex items-center gap-3 text-sm font-semibold mb-6">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    <?php echo $error; ?>
                </div>
                <?php endif; ?>

                <div class="text-center mb-8">
                    <div class="w-16 h-16 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="camera" class="w-8 h-8 text-emerald-600"></i>
                    </div>
                    <p class="text-sm font-semibold text-slate-700 mb-2">PENTING:</p>
                    <p class="text-xs sm:text-sm text-slate-500 leading-relaxed">
                        Silakan **Screenshot** atau **Foto** bukti transfer Anda.<br>
                        Nanti Anda wajib melampirkan foto tersebut di chat WhatsApp Admin.
                    </p>
                </div>

                <form method="POST" action="booking-online.php?step=3&id=<?php echo $booking_id; ?>" class="space-y-6">
                    <div class="pt-2">
                        <button type="submit" name="submit_dp"
                            class="w-full bg-gradient-to-r from-[#25D366] to-[#128C7E] hover:from-[#20bd5a] hover:to-[#075E54] text-white py-4 px-4 rounded-xl font-black text-xs sm:text-sm uppercase tracking-widest shadow-xl shadow-[#25D366]/30 transition-all active:scale-95 flex items-center justify-center gap-2">
                            <i data-lucide="message-circle" class="w-5 h-5 shrink-0"></i>
                            <span class="truncate">Konfirmasi ke WhatsApp Admin</span>
                        </button>
                    </div>
                </form>
            </div>

            <?php elseif ($step == 4 && $booking): ?>
            <!-- STEP 4: SUKSES & KONFIRMASI WA -->
            <div class="p-8 sm:p-10 text-center">
                <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i data-lucide="check-circle-2" class="w-10 h-10 text-emerald-600"></i>
                </div>
                <h2 class="text-2xl font-black text-slate-900 uppercase tracking-tight mb-2">Pendaftaran Berhasil!</h2>
                <p class="text-slate-500 font-medium mb-8 text-sm px-4">Pendaftaran Anda telah tercatat di sistem kami. **Satu langkah lagi:** Silakan kirimkan foto bukti transfer Anda ke WhatsApp Admin untuk konfirmasi akhir.</p>
                
                <div class="bg-slate-50 rounded-2xl p-5 sm:p-6 border border-slate-200 mb-6 sm:mb-8 inline-block text-left w-full max-w-sm">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Kode Booking</p>
                    <p class="text-lg sm:text-xl font-black text-slate-900 font-mono mb-4"><?php echo $booking['booking_code']; ?></p>
                    
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Jadwal Servis</p>
                    <p class="font-bold text-sm sm:text-base text-slate-800"><?php echo date('d M Y', strtotime($booking['service_date'])); ?> - <?php echo $booking['service_time']; ?></p>
                </div>

                <?php 
                    $wa_msg = urlencode("Halo Admin Inka Otoservice 👋\n\nSaya ingin konfirmasi pendaftaran *Booking Online* yang baru saja saya lakukan.\n\nBerikut detail data saya:\n✅ *Kode Booking: " . $booking['booking_code'] . "*\n👤 *Nama:* " . $booking['customer_name'] . "\n🚗 *Kendaraan:* " . $booking['car_model'] . " (" . $booking['license_plate'] . ")\n📅 *Jadwal:* " . date('d M Y', strtotime($booking['service_date'])) . " | " . $booking['service_time'] . " WIB\n\n*(Terlampir foto bukti transfer DP saya di bawah ini)*\n\nMohon bantuannya untuk segera dikonfirmasi agar masuk ke sistem antrian. Terima kasih! 🙏");
                    $wa_number = !empty($booking['branch_whatsapp']) ? $booking['branch_whatsapp'] : "6281234567890"; 
                ?>
                
                <input type="hidden" id="waLink" value="https://wa.me/<?php echo $wa_number; ?>?text=<?php echo $wa_msg; ?>">

                <div class="mb-8 space-y-4">
                    <a href="https://wa.me/<?php echo $wa_number; ?>?text=<?php echo $wa_msg; ?>" class="w-full bg-gradient-to-r from-[#25D366] to-[#128C7E] hover:from-[#20bd5a] hover:to-[#075E54] text-white py-4 px-4 rounded-xl font-black text-xs sm:text-sm uppercase tracking-widest shadow-xl shadow-[#25D366]/30 transition-all active:scale-95 flex items-center justify-center gap-2">
                        <i data-lucide="message-circle" class="w-5 h-5 shrink-0"></i>
                        <span>Kirim Bukti via WhatsApp Sekarang</span>
                    </a>
                    <p class="text-[10px] text-slate-400 font-semibold italic">Aplikasi WhatsApp akan terbuka secara otomatis dalam beberapa saat. Jika tidak, silakan klik tombol di atas.</p>
                </div>

                <a href="booking-online.php" class="text-slate-400 hover:text-slate-600 font-bold text-[10px] sm:text-xs uppercase tracking-widest transition-colors inline-flex items-center gap-1">
                    <i data-lucide="arrow-left" class="w-3 h-3"></i> Buat Booking Baru
                </a>
            </div>

            <?php endif; ?>

        </div>
    </div>

    <script>
        lucide.createIcons();

        // Script to update file name when selected
        const fileInput = document.getElementById('fileInput');
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const fileName = e.target.files[0] ? e.target.files[0].name : 'Klik atau Drag file ke sini';
                document.getElementById('fileName').textContent = fileName;
                document.getElementById('fileName').classList.add('text-blue-600');
            });
        }

        // Script to fetch and disable booked timeslots
        const branchSelect = document.querySelector('select[name="branch_id"]');
        const dateInput = document.querySelector('input[name="service_date"]');
        const timeSelect = document.querySelector('select[name="service_time"]');

        function updateAvailableTimes() {
            if (!branchSelect || !dateInput || !timeSelect) return;
            
            const branch_id = branchSelect.value;
            const date = dateInput.value;
            
            // Reset all options first
            Array.from(timeSelect.options).forEach(opt => {
                if (opt.value !== "") {
                    opt.disabled = false;
                    opt.text = opt.value + ' WIB';
                    opt.classList.remove('text-red-400', 'bg-red-50/50');
                }
            });
            
            if (branch_id && date) {
                fetch(`booking-online.php?action=get_booked_times&date=${date}&branch_id=${branch_id}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.booked_times) {
                            let isCurrentSelectedDisabled = false;
                            
                            Array.from(timeSelect.options).forEach(opt => {
                                // If exact match or starting with the hour (if seconds were included in db)
                                if (data.booked_times.some(bt => bt.startsWith(opt.value))) {
                                    opt.disabled = true;
                                    opt.text = opt.value + ' WIB (Penuh)';
                                    opt.classList.add('text-red-400', 'bg-red-50/50');
                                    
                                    if (timeSelect.value === opt.value) {
                                        isCurrentSelectedDisabled = true;
                                    }
                                }
                            });
                            
                            if (isCurrentSelectedDisabled) {
                                timeSelect.value = ""; // Reset if the currently selected one just got disabled
                            }
                        }
                    })
                    .catch(err => console.error('Error fetching timeslots:', err));
            }
        }

        if (branchSelect && dateInput) {
            branchSelect.addEventListener('change', updateAvailableTimes);
            dateInput.addEventListener('change', updateAvailableTimes);
            // Run once on load just in case values are pre-filled (like form error back)
            updateAvailableTimes();
        }

        // Auto-redirect to WhatsApp in Step 4
        window.addEventListener('load', function() {
            const waLink = document.getElementById('waLink');
            if (waLink && window.location.search.includes('step=4')) {
                setTimeout(() => {
                    window.location.href = waLink.value;
                }, 1500); // Tunggu 1.5 detik agar user sempat melihat pesan sukses
            }
        });

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                const toast = document.getElementById('copyToast');
                toast.classList.remove('hidden', 'toast-inactive');
                toast.classList.add('toast-active');
                
                setTimeout(() => {
                    toast.classList.replace('toast-active', 'toast-inactive');
                    setTimeout(() => {
                        toast.classList.add('hidden');
                    }, 300);
                }, 2000);
            });
        }
    </script>
</body>
</html>
