<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$step = $_GET['step'] ?? 1;
$booking_id = $_GET['id'] ?? null;

// Fetch settings
$stmt_settings = $pdo->query("SELECT `key`, `value` FROM app_settings");
$settings = [];
while ($row = $stmt_settings->fetch()) {
    $settings[$row['key']] = $row['value'];
}
$dp_amount = $settings['booking_dp'] ?? 50000;
$bank_name = $settings['payment_bank_name'] ?? 'Bank BCA';
$bank_account = $settings['payment_account_number'] ?? '123 456 7890';
$bank_account_name = $settings['payment_account_name'] ?? 'PT Inka Otoservice';

// --- AJAX Handler: Ubah status jadi pending saat klik WA ---
if (isset($_GET['action']) && $_GET['action'] == 'mark_pending' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("UPDATE bookings SET status = 'pending' WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    exit();
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
    $service_type = $_POST['service_type'] ?? '';
    
    // Gabungkan jenis layanan ke dalam catatan agar admin tahu
    if ($service_type) {
        $notes = "[" . strtoupper($service_type) . "] " . $notes;
    }
    
    // Create new booking with status awaiting_dp
    $id = uuid();
    $booking_code = 'BKG-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
    
    $stmt = $pdo->prepare("
        INSERT INTO bookings (id, customer_name, customer_phone, car_model, license_plate, branch_id, service_date, service_time, notes, booking_code, booking_type, status, is_online)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'online', 'awaiting_dp', 1)
    ");
    $stmt->execute([$id, $name, $phone, $car_model, $license_plate, $branch_id, $service_date, $service_time, $notes, $booking_code]);
    
    header("Location: booking-online.php?step=2&id=" . urlencode($id));
    exit();
}

// Step 2 (and 3, 4) handling logic removed as it's now integrated into a single warning page
$error = '';

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
        * { font-family: 'Inter', sans-serif; box-sizing: border-box; }
        body { -webkit-tap-highlight-color: transparent; }
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
        
        /* Mobile specific adjustments */
        @media (max-width: 640px) {
            input, select, textarea { font-size: 16px !important; } /* Prevents iOS zoom on focus */
        }
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

                    <!-- JENIS LAYANAN (Radio Cards) -->
                    <div class="pt-4 border-t border-slate-200">
                        <label class="block text-[10px] sm:text-xs font-black text-slate-500 uppercase tracking-widest mb-3">Pilih Jenis Layanan</label>
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Service AC -->
                            <label class="relative flex flex-col p-4 cursor-pointer rounded-2xl border-2 border-slate-100 bg-slate-50/50 hover:bg-white hover:border-blue-500 transition-all group has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50">
                                <input type="radio" name="service_type" value="AC" class="sr-only peer">
                                <div class="flex items-center gap-3 mb-1">
                                    <div class="w-8 h-8 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600 group-hover:scale-110 transition-transform">
                                        <i data-lucide="snowflake" class="w-4 h-4"></i>
                                    </div>
                                    <span class="font-bold text-sm text-slate-800">Service AC</span>
                                </div>
                                <p class="text-[10px] text-slate-500 font-medium">Khusus Depok</p>
                            </label>
                            
                            <!-- Service Kaki-kaki -->
                            <label class="relative flex flex-col p-4 cursor-pointer rounded-2xl border-2 border-slate-100 bg-slate-50/50 hover:bg-white hover:border-blue-500 transition-all group has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50">
                                <input type="radio" name="service_type" value="Kaki-kaki" checked class="sr-only peer">
                                <div class="flex items-center gap-3 mb-1">
                                    <div class="w-8 h-8 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600 group-hover:scale-110 transition-transform">
                                        <i data-lucide="wrench" class="w-4 h-4"></i>
                                    </div>
                                    <span class="font-bold text-sm text-slate-800">Kaki-kaki</span>
                                </div>
                                <p class="text-[10px] text-slate-500 font-medium">Depok & BSD</p>
                            </label>
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
                            <span>Simpan & Lanjut Konfirmasi</span>
                            <i data-lucide="arrow-right" class="w-4 h-4 shrink-0"></i>
                        </button>
                    </div>
                </form>
            </div>

            <?php elseif ($step == 2 && $booking): ?>
            <!-- STEP 2: WARNING & INFO PEMBAYARAN -->
            <div id="step2Container" class="p-6 sm:p-8 text-center">
                <!-- Ikon Status -->
                <div id="statusIconBg" class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6 transition-all duration-500">
                    <i id="iconAlert" data-lucide="alert-triangle" class="w-10 h-10 text-red-600"></i>
                    <i id="iconWait" data-lucide="hourglass" class="w-10 h-10 text-blue-600 hidden"></i>
                </div>
                
                <h2 id="statusTitle" class="text-2xl sm:text-3xl font-black text-red-600 uppercase tracking-tight mb-2 transition-all duration-500">Belum Selesai!</h2>
                <p id="statusDesc" class="text-slate-600 font-medium mb-6 text-sm px-2 transition-all duration-500">Data Anda sudah tersimpan, namun **Kode Booking belum aktif**. Silakan lakukan pembayaran booking fee dan **wajib** kirim foto bukti transfer ke WhatsApp kami.</p>

                <div id="actionWrapper" class="transition-all duration-500 opacity-100">
                    <!-- Info Pembayaran -->
                <div class="bg-slate-50 rounded-2xl p-5 sm:p-6 mb-8 border border-slate-200 text-left">
                    <div class="flex items-center gap-2 mb-4 pb-4 border-b border-slate-200">
                        <i data-lucide="credit-card" class="w-5 h-5 text-slate-400"></i>
                        <h3 class="font-bold text-sm text-slate-800 uppercase tracking-widest">Nominal & Rekening</h3>
                    </div>
                    
                    <div class="mb-5 text-center">
                        <p class="text-xs font-semibold text-slate-500 mb-1">Total booking fee yang harus ditransfer:</p>
                        <p class="text-3xl font-black text-slate-900"><?php echo rupiah($dp_amount); ?></p>
                    </div>

                    <div class="flex items-center justify-between p-4 bg-white rounded-xl border border-slate-100 shadow-sm">
                        <div class="max-w-[70%]">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($bank_name); ?></p>
                            <p class="font-bold text-slate-800 text-lg sm:text-xl break-words"><?php echo htmlspecialchars($bank_account); ?></p>
                            <p class="text-xs text-slate-500 font-medium">a.n. <?php echo htmlspecialchars($bank_account_name); ?></p>
                        </div>
                        <button onclick="copyToClipboard('<?php echo htmlspecialchars($bank_account); ?>')" class="w-10 h-10 bg-blue-50 hover:bg-blue-100 rounded-lg flex items-center justify-center border border-blue-100 transition-all active:scale-90 group shrink-0">
                            <i data-lucide="copy" class="w-5 h-5 text-blue-600 group-hover:scale-110 transition-transform"></i>
                        </button>
                    </div>
                </div>

                <!-- Siapkan Link WA -->
                <?php 
                    $branch_name = $booking['branch_name'] ?? 'Cabang';
                    $wa_msg = urlencode("Halo Admin Inka Otoservice ({$branch_name})\n\nSaya ingin konfirmasi pembayaran booking fee untuk pendaftaran *Booking Online*.\n\nBerikut data saya:\n*Sistem ID: " . $booking['booking_code'] . "*\n*Nama:* " . $booking['customer_name'] . "\n*Kendaraan:* " . $booking['car_model'] . " (" . $booking['license_plate'] . ")\n*Cabang:* " . $branch_name . "\n*Jadwal:* " . date('d M Y', strtotime($booking['service_date'])) . " | " . $booking['service_time'] . " WIB\n\n[ ⚠️ JANGAN LUPA: Lampirkan foto/screenshot bukti transfer booking fee Anda sebelum menekan tombol kirim pesan ini ]\n\nMohon konfirmasi agar saya mendapatkan Kode Booking. Terima kasih!");
                    
                    // Logic Anti-Salah: Prioritaskan deteksi nama cabang
                    $branch_name_lower = strtolower($branch_name);
                    if (strpos($branch_name_lower, 'bsd') !== false) {
                        $wa_number = '6281398563653';
                    } elseif (strpos($branch_name_lower, 'depok') !== false) {
                        $wa_number = '6289678290743';
                    } else {
                        $wa_number = !empty($booking['branch_whatsapp']) ? $booking['branch_whatsapp'] : '6289678290743';
                    }
                ?>
                <!-- Tombol Buka WhatsApp Beranimasi Pulse -->
                <a id="btnOpenWA" href="https://wa.me/<?php echo $wa_number; ?>?text=<?php echo $wa_msg; ?>"
                    target="_blank" rel="noopener noreferrer"
                    class="w-full inline-flex flex-col sm:flex-row items-center justify-center gap-2 sm:gap-3 bg-gradient-to-r from-[#25D366] to-[#128C7E] hover:from-[#20bd5a] hover:to-[#075E54] text-white py-4 px-6 rounded-xl font-black text-xs sm:text-sm uppercase tracking-widest shadow-xl shadow-[#25D366]/40 transition-all active:scale-95 mb-6 animate-pulse"
                    onclick="handleWAClick(this, '<?php echo urlencode($booking_id); ?>');">
                    <div class="flex items-center gap-2">
                        <i data-lucide="camera" class="w-5 h-5 shrink-0"></i>
                        <span id="btnWALabel">KLIK & KIRIM BUKTI KE WA</span>
                    </div>
                </a>

                </div> <!-- End actionWrapper -->
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

        const branchSelect = document.querySelector('select[name="branch_id"]');

        // --- FILTER CABANG BERDASARKAN LAYANAN ---
        const serviceRadios = document.querySelectorAll('input[name="service_type"]');
        const originalBranchOptions = Array.from(branchSelect.options);

        function filterBranches() {
            const selectedService = document.querySelector('input[name="service_type"]:checked').value;
            const currentBranchId = branchSelect.value;
            
            // Simpan pilihan sebelumnya
            const previousValue = branchSelect.value;
            
            // Bersihkan dropdown
            branchSelect.innerHTML = '';
            
            originalBranchOptions.forEach(opt => {
                const branchName = opt.text.toLowerCase();
                
                if (selectedService === 'AC') {
                    // Hanya tampilkan Depok untuk AC
                    if (opt.value === "" || branchName.includes('depok')) {
                        branchSelect.appendChild(opt.cloneNode(true));
                    }
                } else {
                    // Tampilkan semua untuk Kaki-kaki
                    branchSelect.appendChild(opt.cloneNode(true));
                }
            });

            // Jika pilihan sebelumnya masih ada di daftar baru, biarkan. Jika tidak, reset.
            const stillExists = Array.from(branchSelect.options).some(opt => opt.value === previousValue);
            if (stillExists) {
                branchSelect.value = previousValue;
            } else {
                branchSelect.value = "";
                // Jika hanya ada 1 pilihan (Depok), otomatis pilih
                const realOpts = branchSelect.querySelectorAll('option[value]:not([value=""])');
                if (realOpts.length === 1) {
                    branchSelect.value = realOpts[0].value;
                }
            }
            

        }

        serviceRadios.forEach(r => r.addEventListener('change', filterBranches));
        // Jalankan saat load
        if (serviceRadios.length > 0) filterBranches();



        function handleWAClick(btn, bookingId) {
            // Hilangkan pulse
            btn.classList.remove('animate-pulse');
            
            // Kirim AJAX diam-diam
            fetch('booking-online.php?action=mark_pending&id=' + bookingId);

            // Transisi Visual UI (tunggu setengah detik agar transisi browser ke WA tidak terganggu)
            setTimeout(() => {
                const actionWrapper = document.getElementById('actionWrapper');
                const statusIconBg = document.getElementById('statusIconBg');
                const iconAlert = document.getElementById('iconAlert');
                const iconWait = document.getElementById('iconWait');
                const statusTitle = document.getElementById('statusTitle');
                const statusDesc = document.getElementById('statusDesc');

                if(!actionWrapper) return;

                // Hilangkan area aksi (rekening & tombol)
                actionWrapper.classList.remove('opacity-100');
                actionWrapper.classList.add('opacity-0', 'pointer-events-none', 'h-0', 'overflow-hidden');
                
                // Ubah ikon ke Jam Pasir
                statusIconBg.classList.remove('bg-red-100');
                statusIconBg.classList.add('bg-blue-100', 'animate-pulse');
                iconAlert.classList.add('hidden');
                iconWait.classList.remove('hidden');

                // Ubah Judul & Warna
                statusTitle.textContent = 'MENUNGGU KONFIRMASI ADMIN';
                statusTitle.classList.remove('text-red-600');
                statusTitle.classList.add('text-blue-600');

                // Ubah Deskripsi
                statusDesc.innerHTML = 'Anda sudah diarahkan ke WhatsApp. Silakan selesaikan pengiriman foto bukti transfer booking fee Anda di sana.<br><br><strong class="text-blue-700">Kode Booking akan diberikan oleh Admin melalui balasan WhatsApp setelah pengecekan.</strong>';
                
            }, 500); 
        }

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
