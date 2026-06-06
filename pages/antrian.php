<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

if (!has_role(['admin','admin_depok','admin_bsd','spv','owner','manager_ops'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Papan Antrian Service';
$branch_id = get_branch_filter(); // SPV cabang → branch_id, owner/manager → null
$role = get_role();

// ── HANDLER AJAX GET DATA (HARUS DI PALING ATAS, SEBELUM HTML) ───
function get_active_bookings($pdo, $branch_id, $role, $filter_date = null, $search_query = null) {
    $params = [];
    $sql = "SELECT b.*, br.name as branch_name, t.mechanic_name 
            FROM bookings b 
            LEFT JOIN branches br ON b.branch_id = br.id 
            LEFT JOIN transactions t ON b.id = t.booking_id
            WHERE b.status IN ('pending', 'processing', 'completed')";

    if (!empty($search_query)) {
        $sql .= " AND (REPLACE(b.license_plate, ' ', '') LIKE REPLACE(?, ' ', '') OR b.customer_name LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    } else {
        if (!$filter_date) $filter_date = date('Y-m-d');
        $sql .= " AND b.service_date = ?";
        $params[] = $filter_date;
    }

    // Filter: owner & manager_ops lihat semua (branch_id null), sisanya dibatasi
    if ($branch_id) {
        $sql .= " AND b.branch_id = ?";
        $params[] = $branch_id;
    }
    $sql .= " ORDER BY b.service_date ASC, b.service_time ASC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['ajax_get'])) {
    header('Content-Type: application/json');
    try {
        $filter_date = $_GET['date'] ?? date('Y-m-d');
        $search_query = $_GET['search'] ?? null;
        $current_bookings = get_active_bookings($pdo, $branch_id, $role, $filter_date, $search_query);
        
        $log_msg = "[" . date('Y-m-d H:i:s') . "] AJAX Request - Date: $filter_date, Branch: $branch_id, Role: $role, Found: " . count($current_bookings) . "\n";
        file_put_contents('../ajax_debug.log', $log_msg, FILE_APPEND);
        
        echo json_encode($current_bookings);
    } catch (Exception $e) {
        file_put_contents('../ajax_debug.log', "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── PROSES AJAX CONFIRM DP (hanya tandai DP lunas, status tetap pending) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_dp') {
    $booking_id = $_POST['booking_id'];
    header('Content-Type: application/json');
    try {
        $stmt_dp_cfg = $pdo->query("SELECT `value` FROM app_settings WHERE `key` = 'booking_dp'");
        $dp_amount = $stmt_dp_cfg->fetchColumn() ?: 50000;

        $stmt = $pdo->prepare("UPDATE bookings SET is_dp_paid = 1, dp_amount = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$dp_amount, $booking_id]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── PROSES AJAX UPDATE STATUS ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $booking_id = $_POST['booking_id'];
    $new_status = $_POST['new_status'];

    try {
        $pdo->beginTransaction();

        if ($new_status === 'processing') {
            // Buat Draft Transaction jika belum ada
            $stmt_b = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt_b->execute([$booking_id]);
            $booking = $stmt_b->fetch();

            $stmt_check = $pdo->prepare("SELECT id FROM transactions WHERE booking_id = ?");
            $stmt_check->execute([$booking_id]);
            if (!$stmt_check->fetch()) {
                $tx_id = uuid();
                $stmt_tx = $pdo->prepare("INSERT INTO transactions (id, customer_name, branch_id, booking_id, total_amount, status) VALUES (?, ?, ?, ?, 0, 'Draft')");
                $stmt_tx->execute([$tx_id, $booking['customer_name'], $booking['branch_id'], $booking_id]);
            }
        }

        // Handle Refund/Cancel
        if ($new_status === 'cancelled_refund') {
            $stmt_b = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt_b->execute([$booking_id]);
            $booking = $stmt_b->fetch();
            
            $reason = $_POST['reason'] ?? 'Customer request';
            
            if ($booking['is_dp_paid'] && $booking['dp_amount'] > 0) {
                // Set to pending refund, do NOT insert expense yet
                $stmt_upd = $pdo->prepare("UPDATE bookings SET refund_status = 'pending', cancellation_reason = ? WHERE id = ?");
                $stmt_upd->execute([$reason, $booking_id]);
            }
            $new_status = 'cancelled';
        } elseif ($new_status === 'cancelled') {
            $reason = $_POST['reason'] ?? 'Customer request';
            $stmt_upd = $pdo->prepare("UPDATE bookings SET cancellation_reason = ? WHERE id = ?");
            $stmt_upd->execute([$reason, $booking_id]);
        }

        $stmt = $pdo->prepare("UPDATE bookings SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_status, $booking_id]);
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule') {
    $booking_id = $_POST['booking_id'];
    $new_date = $_POST['new_date'];

    try {
        $stmt = $pdo->prepare("UPDATE bookings SET service_date = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_date, $booking_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AMBIL DATA AWAL UNTUK HALAMAN ──────────────────────────────
$filter_date = $_GET['date'] ?? date('Y-m-d');
$all_bookings = get_active_bookings($pdo, $branch_id, $role, $filter_date);
echo "<!-- DEBUG: BranchID: $branch_id, Role: $role, Count: ".count($all_bookings)." -->";
$bookings_json = json_encode($all_bookings);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative bg-slate-50">
    <!-- Topbar -->
    <header class="h-12 sm:h-14 flex-shrink-0 flex items-center justify-between px-4 sm:px-5 lg:px-6 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-1.5 lg:hidden text-slate-500 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu" class="w-4 h-4"></i>
        </button>

        <div class="flex-1 px-3 flex items-center gap-3">
            <h1 class="text-xs sm:text-sm font-bold text-slate-900 tracking-tight uppercase">Antrian Service</h1>
            <div id="refreshIndicator" class="hidden sm:flex items-center gap-1.5 px-2 py-0.5 bg-slate-100 rounded-full text-[9px] font-bold text-slate-400 uppercase tracking-wider">
                <div class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></div>
                Live
            </div>
        </div>
        
        <div class="flex items-center">
            <div class="flex items-center gap-2">
                <input type="date" id="boardDate" value="<?php echo htmlspecialchars($filter_date); ?>" onchange="window.location.href='antrian.php?date='+this.value" onclick="this.showPicker()" class="px-2 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] font-semibold focus:ring-2 focus:ring-blue-500/10 focus:border-blue-400 outline-none text-slate-700 transition-all cursor-pointer">
                
                <div class="hidden md:block">
                    <div class="relative group">
                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 w-3.5 h-3.5 group-focus-within:text-blue-600"></i>
                        <input type="text" id="boardSearch" oninput="handleSearchInput()" placeholder="Cari Plat / Nama..." class="pl-9 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] font-semibold focus:ring-2 focus:ring-blue-500/10 focus:border-blue-400 w-44 transition-all outline-none">
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-x-auto overflow-y-hidden p-3 sm:p-4 lg:p-5 flex flex-col min-h-0" id="appBoard">
        
        <!-- Toast -->
        <div id="feedback" class="fixed top-16 right-6 z-[200] max-w-xs w-full transition-all duration-300 transform translate-y-[-100%] opacity-0 pointer-events-none">
            <div class="bg-white rounded-xl shadow-xl border-l-4 border-emerald-500 px-3 py-2.5 flex gap-2.5 items-center">
                <div id="feedbackIcon" class="p-1.5 bg-emerald-50 text-emerald-500 rounded-lg shrink-0">
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                </div>
                <div>
                    <h4 id="feedbackTitle" class="font-bold text-slate-900 text-xs">Berhasil!</h4>
                    <p id="feedbackMessage" class="text-[10px] text-slate-500">Status diperbarui.</p>
                </div>
            </div>
        </div>

        <!-- Reschedule Modal -->
        <div id="rescheduleModal" class="fixed inset-0 z-[200] bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center">
            <div class="bg-white rounded-2xl w-full max-w-sm p-5 shadow-2xl transform transition-all scale-95 opacity-0" id="rescheduleModalContent">
                <h3 class="font-bold text-slate-900 mb-4">Reschedule Jadwal</h3>
                <input type="hidden" id="rescheduleBookingId">
                <div class="mb-5">
                    <label class="block text-[11px] font-bold text-slate-500 mb-1.5 uppercase tracking-wider">Pilih Tanggal Baru</label>
                    <input type="date" id="rescheduleDate" onclick="this.showPicker()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-semibold focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 outline-none transition-all cursor-pointer">
                </div>
                <div class="flex gap-2">
                    <button onclick="closeRescheduleModal()" class="flex-1 py-2.5 bg-slate-100 text-slate-600 rounded-lg text-xs font-bold hover:bg-slate-200 transition-colors">Batal</button>
                    <button onclick="submitReschedule()" class="flex-1 py-2.5 bg-blue-600 text-white rounded-lg text-xs font-bold hover:bg-blue-700 transition-colors shadow-sm">Simpan</button>
                </div>
            </div>
        </div>

        <!-- Confirm Modal -->
        <div id="confirmModal" class="fixed inset-0 z-[250] bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
            <div class="bg-white rounded-2xl w-full max-w-[320px] p-6 shadow-2xl transform transition-all scale-95 opacity-0" id="confirmModalContent">
                <div class="flex flex-col items-center text-center">
                    <div id="confirmIconContainer" class="w-12 h-12 bg-red-50 text-red-500 rounded-full flex items-center justify-center mb-4">
                        <i data-lucide="alert-triangle" class="w-6 h-6"></i>
                    </div>
                    <h3 id="confirmTitle" class="font-bold text-slate-900 text-base mb-2">Konfirmasi</h3>
                    <p id="confirmMessage" class="text-xs text-slate-500 leading-relaxed mb-4">Apakah Anda yakin ingin melanjutkan tindakan ini?</p>
                </div>
                <div id="confirmReasonContainer" class="hidden mb-6 w-full text-left">
                    <label class="block text-[11px] font-bold text-slate-500 mb-1.5 uppercase tracking-wider">Alasan Pembatalan / Refund <span class="text-red-500">*</span></label>
                    <textarea id="confirmReason" placeholder="Ketik alasan di sini..." class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs focus:ring-2 focus:ring-red-500/20 focus:border-red-400 outline-none transition-all resize-none h-20"></textarea>
                </div>
                <div class="flex gap-3 mt-2">
                    <button onclick="closeConfirm(false)" class="flex-1 py-2.5 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-200 transition-all">Batal</button>
                    <button id="confirmBtn" onclick="closeConfirm(true)" class="flex-1 py-2.5 bg-red-500 text-white rounded-xl text-xs font-bold hover:bg-red-600 transition-all shadow-sm shadow-red-200">Ya, Hapus</button>
                </div>
            </div>
        </div>

        <!-- KANBAN BOARD -->
        <div class="flex gap-4 items-start min-w-[900px] flex-1 min-h-0">
            
            <!-- KOLOM 1: PENDING -->
            <div class="flex-1 flex flex-col min-w-[280px] h-full bg-slate-100/50 rounded-xl border border-slate-100/80">
                <div class="flex items-center justify-between mb-2 px-3 pt-3 shrink-0">
                    <div class="flex items-center gap-2">
                        <div class="p-1.5 bg-amber-50 text-amber-500 rounded-lg">
                            <i data-lucide="clock" class="w-3.5 h-3.5"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 uppercase tracking-wider text-[11px]">Menunggu</h3>
                        </div>
                    </div>
                    <span id="count_pending" class="bg-amber-50 border border-amber-200 text-amber-700 font-bold text-[10px] px-2 py-0.5 rounded-md">0</span>
                </div>
                <div id="col_pending" class="flex-1 overflow-y-auto custom-scrollbar px-2 pb-4 space-y-2.5">
                </div>
            </div>

            <!-- KOLOM 2: PROCESSING -->
            <div class="flex-1 flex flex-col min-w-[280px] h-full bg-slate-100/50 rounded-xl border border-slate-100/80">
                <div class="flex items-center justify-between mb-2 px-3 pt-3 shrink-0">
                    <div class="flex items-center gap-2">
                        <div class="p-1.5 bg-blue-50 text-blue-500 rounded-lg">
                            <i data-lucide="wrench" class="w-3.5 h-3.5"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 uppercase tracking-wider text-[11px]">Dikerjakan</h3>
                        </div>
                    </div>
                    <span id="count_processing" class="bg-blue-600 text-white font-bold text-[10px] px-2 py-0.5 rounded-md shadow-sm">0</span>
                </div>
                <div id="col_processing" class="flex-1 overflow-y-auto custom-scrollbar px-2 pb-4 space-y-2.5">
                </div>
            </div>

            <!-- KOLOM 3: COMPLETED -->
            <div class="flex-1 flex flex-col min-w-[280px] h-full bg-slate-100/50 rounded-xl border border-slate-100/80">
                <div class="flex items-center justify-between mb-2 px-3 pt-3 shrink-0">
                    <div class="flex items-center gap-2">
                        <div class="p-1.5 bg-emerald-50 text-emerald-500 rounded-lg">
                            <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 uppercase tracking-wider text-[11px]">Selesai</h3>
                        </div>
                    </div>
                    <span id="count_completed" class="bg-emerald-500 text-white font-bold text-[10px] px-2 py-0.5 rounded-md shadow-sm">0</span>
                </div>
                <div id="col_completed" class="flex-1 overflow-y-auto custom-scrollbar px-2 pb-4 space-y-2.5">
                </div>
            </div>

        </div>
    </div>
</main>

<?php
$extra_js = <<<JS
<script>
let bookings = {$bookings_json};
console.log("Initial Bookings Loaded:", bookings);

function showToast(type, title, msg) {
    const el = document.getElementById('feedback');
    const icon = document.getElementById('feedbackIcon');
    document.getElementById('feedbackTitle').textContent = title;
    document.getElementById('feedbackMessage').textContent = msg;
    
    el.classList.remove('translate-y-[-100%]', 'opacity-0');
    
    if(type === 'success') {
        icon.className = 'p-2 bg-emerald-50 text-emerald-500 rounded-full shrink-0';
        icon.innerHTML = '<i data-lucide="check-circle" class="w-5 h-5"></i>';
    } else {
        icon.className = 'p-2 bg-red-50 text-red-500 rounded-full shrink-0';
        icon.innerHTML = '<i data-lucide="alert-circle" class="w-5 h-5"></i>';
    }
    lucide.createIcons();
    
    setTimeout(() => {
        el.classList.add('translate-y-[-100%]', 'opacity-0');
    }, 4000);
}

function updateStatus(id, newStatus, reason = '') {
    const fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('booking_id', id);
    fd.append('new_status', newStatus);
    if (reason) fd.append('reason', reason);

    fetch('antrian.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            const booking = bookings.find(b => b.id === id);
            if(booking) {
                if (newStatus === 'cancelled_refund') {
                    booking.status = 'cancelled';
                    booking.refund_status = 'pending';
                } else {
                    booking.status = newStatus;
                }
                renderBoard();
                showToast('success', 'Berhasil', 'Status antrian diperbarui.');
            }
        } else {
            showToast('error', 'Gagal', res.error);
        }
    })
    .catch(err => {
        showToast('error', 'Sistem Error', 'Gagal memperbarui status.');
    });
}

function openRescheduleModal(id, currentDate) {
    document.getElementById('rescheduleBookingId').value = id;
    document.getElementById('rescheduleDate').value = currentDate;
    
    const modal = document.getElementById('rescheduleModal');
    const content = document.getElementById('rescheduleModalContent');
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    setTimeout(() => {
        content.classList.remove('scale-95', 'opacity-0');
        content.classList.add('scale-100', 'opacity-100');
    }, 10);
}

function closeRescheduleModal() {
    const modal = document.getElementById('rescheduleModal');
    const content = document.getElementById('rescheduleModalContent');
    
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    
    setTimeout(() => {
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }, 200);
}

function submitReschedule() {
    const id = document.getElementById('rescheduleBookingId').value;
    const newDate = document.getElementById('rescheduleDate').value;
    
    if (!newDate) {
        showToast('error', 'Gagal', 'Pilih tanggal baru terlebih dahulu.');
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'reschedule');
    fd.append('booking_id', id);
    fd.append('new_date', newDate);

    fetch('antrian.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            closeRescheduleModal();
            showToast('success', 'Berhasil', 'Jadwal berhasil diubah.');
            reloadBoardData();
        } else {
            showToast('error', 'Gagal', res.error || 'Gagal mengubah jadwal.');
        }
    })
    .catch(err => {
        showToast('error', 'Sistem Error', 'Terjadi kesalahan sistem.');
    });
}

let confirmPromise = null;
function showConfirm(title, message, btnText = 'Ya, Lanjutkan', btnColor = 'bg-red-500', needsReason = false) {
    const modal = document.getElementById('confirmModal');
    const content = document.getElementById('confirmModalContent');
    const confirmBtn = document.getElementById('confirmBtn');
    const reasonContainer = document.getElementById('confirmReasonContainer');
    const reasonInput = document.getElementById('confirmReason');
    
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    confirmBtn.textContent = btnText;
    
    if (needsReason) {
        reasonContainer.classList.remove('hidden');
        reasonInput.value = '';
    } else {
        reasonContainer.classList.add('hidden');
    }
    
    // Reset classes and add new ones
    confirmBtn.className = `flex-1 py-2.5 \${btnColor} text-white rounded-xl text-xs font-bold hover:opacity-90 transition-all shadow-sm`;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    setTimeout(() => {
        content.classList.remove('scale-95', 'opacity-0');
        content.classList.add('scale-100', 'opacity-100');
        if(needsReason) reasonInput.focus();
    }, 10);
    
    lucide.createIcons();
    
    return new Promise(resolve => {
        confirmPromise = resolve;
    });
}

function closeConfirm(result) {
    const reasonInput = document.getElementById('confirmReason');
    const reasonContainer = document.getElementById('confirmReasonContainer');
    
    if (result && !reasonContainer.classList.contains('hidden') && !reasonInput.value.trim()) {
        showToast('error', 'Gagal', 'Alasan pembatalan wajib diisi!');
        reasonInput.focus();
        return;
    }

    const modal = document.getElementById('confirmModal');
    const content = document.getElementById('confirmModalContent');
    
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    
    const finalReason = reasonInput.value.trim();
    
    setTimeout(() => {
        modal.classList.remove('flex');
        modal.classList.add('hidden');
        if (confirmPromise) confirmPromise(result ? { confirmed: true, reason: finalReason } : null);
        confirmPromise = null;
    }, 200);
}

async function confirmDP(id) {
    const res = await showConfirm(
        'Konfirmasi DP',
        'Konfirmasi bahwa DP telah diterima? Status akan diubah menjadi DP Lunas. Kerjakan masih bisa dimulai nanti.',
        'Ya, Konfirmasi DP',
        'bg-emerald-600',
        false
    );
    if (res && res.confirmed) {
        const fd = new FormData();
        fd.append('action', 'confirm_dp');
        fd.append('booking_id', id);

        fetch('antrian.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                const booking = bookings.find(b => b.id === id);
                if (booking) {
                    booking.is_dp_paid = 1;
                    renderBoard();
                }
                showToast('success', 'DP Dikonfirmasi', 'Status DP telah diubah menjadi Lunas. Klik Mulai Kerjakan saat siap.');
            } else {
                showToast('error', 'Gagal', result.error || 'Gagal mengkonfirmasi DP.');
            }
        })
        .catch(() => showToast('error', 'Sistem Error', 'Gagal menghubungi server.'));
    }
}

async function handleCancel(id, isRefund = false) {
    const title = isRefund ? 'Refund DP' : 'Batalkan Antrian';
    const msg = isRefund ? 'Harap masukkan alasan refund. Pengembalian dana akan diteruskan ke Finance.' : 'Masukkan alasan mengapa antrian ini dibatalkan:';
    
    const res = await showConfirm(title, msg, isRefund ? 'Ajukan Refund DP' : 'Ya, Batalkan', 'bg-red-500', true);
    if (res && res.confirmed) {
        updateStatus(id, isRefund ? 'cancelled_refund' : 'cancelled', res.reason);
    }
}

function renderBoard() {
    const searchInput = document.getElementById('boardSearch');
    const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
    
    console.log("Rendering board with query:", q, "Total bookings:", bookings.length);

    const columns = {
        'pending': { el: document.getElementById('col_pending'), count: 0, html: '' },
        'processing': { el: document.getElementById('col_processing'), count: 0, html: '' },
        'completed': { el: document.getElementById('col_completed'), count: 0, html: '' }
    };

    bookings.forEach(b => {
        try {
            const status = (b.status || '').trim().toLowerCase();
            if (!columns[status]) {
                console.log("Booking skipped by invalid status:", b.license_plate, "Status:", status);
                return;
            }

            columns[status].count++;
            
            const isPending = status === 'pending';
            const isProcessing = status === 'processing';
            const isCompleted = status === 'completed';

        let actions = '';
        if (isPending) {
            const isOnline = b.is_online == 1;
            const isPaid = b.is_dp_paid == 1;
            
            if (isOnline) {
                if (!isPaid) {
                    actions = `
                        <button onclick="confirmDP('\${b.id}')" class="w-full mt-2.5 py-2.5 bg-emerald-600 text-white rounded-lg text-[9px] font-black uppercase tracking-widest hover:bg-emerald-700 transition-all flex items-center justify-center gap-1.5 shadow-lg shadow-emerald-500/20">
                            <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Konfirmasi DP
                        </button>
                        <button onclick="handleCancel('\${b.id}', false)" class="w-full mt-1.5 py-1.5 text-red-500 hover:bg-red-50 rounded-lg text-[8px] font-bold uppercase tracking-wider transition-all">
                            Batalkan
                        </button>
                    `;
                } else {
                    actions = `
                        <button onclick="updateStatus('\${b.id}', 'processing')" class="w-full mt-2.5 py-2 bg-blue-600 text-white rounded-lg text-[9px] font-bold uppercase tracking-wider hover:bg-blue-700 transition-all flex items-center justify-center gap-1.5 shadow-sm">
                            Mulai Kerjakan <i data-lucide="play" class="w-3 h-3 fill-current"></i>
                        </button>
                        <div class="flex gap-1.5 mt-1.5">
                            <button onclick="openRescheduleModal('\${b.id}', '\${b.service_date}')" class="flex-1 py-1.5 bg-indigo-50 text-indigo-600 rounded-lg text-[8px] font-bold uppercase tracking-wider hover:bg-indigo-100 transition-all border border-indigo-100 flex items-center justify-center gap-1">
                                <i data-lucide="calendar-clock" class="w-3 h-3"></i> Reschedule
                            </button>
                            <button onclick="handleCancel('\${b.id}', true)" class="flex-1 py-1.5 text-red-500 bg-red-50 rounded-lg text-[8px] font-bold uppercase tracking-wider hover:bg-red-100 transition-all border border-red-100 flex items-center justify-center gap-1">
                                <i data-lucide="banknote" class="w-3 h-3"></i> Refund
                            </button>
                        </div>
                    `;
                }
            } else {
                actions = `
                    <button onclick="updateStatus('\${b.id}', 'processing')" class="w-full mt-2.5 py-2 bg-blue-600 text-white rounded-lg text-[9px] font-bold uppercase tracking-wider hover:bg-blue-700 transition-all flex items-center justify-center gap-1.5 shadow-sm">
                        Mulai Kerjakan <i data-lucide="play" class="w-3 h-3 fill-current"></i>
                    </button>
                    <button onclick="handleCancel('\${b.id}', false)" class="w-full mt-1.5 py-1.5 text-slate-500 hover:bg-slate-100 rounded-lg text-[8px] font-bold uppercase tracking-wider transition-all">
                        Batalkan
                    </button>
                `;
            }
        } else if (isProcessing) {
            actions = `
                <div class="flex flex-col gap-1.5 mt-2.5">
                    <a href="pos.php?booking_id=\${b.id}" class="w-full py-2 bg-slate-900 text-white rounded-lg text-[9px] font-bold uppercase tracking-wider hover:bg-slate-800 transition-all flex items-center justify-center gap-1.5 shadow-sm">
                        Proses POS <i data-lucide="shopping-cart" class="w-3 h-3"></i>
                    </a>
                    <button onclick="updateStatus('\${b.id}', 'completed')" class="w-full py-2 bg-emerald-500 text-white rounded-lg text-[9px] font-bold uppercase tracking-wider hover:bg-emerald-600 transition-all flex items-center justify-center gap-1.5 shadow-sm">
                        Selesai <i data-lucide="check-circle" class="w-3 h-3"></i>
                    </button>
                    <button onclick="updateStatus('\${b.id}', 'pending')" class="w-full py-1.5 bg-slate-50 text-slate-400 rounded-lg text-[8px] font-bold uppercase tracking-wider hover:bg-amber-50 hover:text-amber-600 transition-all flex items-center justify-center gap-1 border border-slate-100 mt-0.5">
                        <i data-lucide="rotate-ccw" class="w-2.5 h-2.5"></i> Kembalikan ke Antrian
                    </button>
                </div>
            `;
        } else if (isCompleted) {
            actions = `
                <div class="flex items-center gap-1.5 mt-2.5">
                    <a href="invoice.php?booking_id=\${b.id}" target="_blank" title="Cetak Nota" class="flex-1 py-1.5 bg-indigo-600 text-white rounded-md flex items-center justify-center hover:bg-indigo-700 transition-all shadow-sm">
                        <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                    </a>
                    <a href="pos.php?booking_id=\${b.id}" title="Edit POS" class="flex-1 py-1.5 bg-slate-900 text-white rounded-md flex items-center justify-center hover:bg-slate-800 transition-all shadow-sm">
                        <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                    </a>
                    <button onclick="updateStatus('\${b.id}', 'processing')" title="Revisi Status" class="px-2.5 py-1.5 bg-amber-500 text-white rounded-md flex items-center justify-center hover:bg-amber-600 transition-all shadow-sm">
                        <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
            `;
        }

        const borderColor = isPending ? 'border-l-amber-400' : (isProcessing ? 'border-l-blue-500' : 'border-l-emerald-500');

        if (isCompleted) {
            // Compact Mode
            columns[b.status].html += `
                <div class="bg-white px-2.5 py-2 rounded-xl border border-slate-200 border-l-[3px] \${borderColor} hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <div class="flex justify-between items-start mb-1.5 gap-2">
                        <div class="flex flex-col min-w-0">
                            <h4 class="text-xs font-bold text-slate-900 tracking-wide truncate">\${b.license_plate}</h4>
                            \${b.booking_code ? `<span class="text-[8px] font-medium text-slate-400 font-mono tracking-wide truncate mt-0.5">#\${b.booking_code}</span>` : ''}
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0 flex-wrap justify-end max-w-[50%]">
                            <span class="text-[7px] font-black text-white uppercase tracking-widest \${b.is_online == 1 ? 'bg-indigo-500' : 'bg-slate-400'} px-1.5 py-0.5 rounded-full">\${b.booking_type}</span>
                            <span class="text-[9px] font-semibold text-slate-500">\${b.service_time.substring(0,5)}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 mb-1.5 flex-wrap">
                        <p class="text-[10px] font-medium text-slate-600 truncate">
                            \${b.customer_name}
                            \${b.customer_phone ? `<span class="text-[9px] text-slate-400 font-normal ml-1">\${b.customer_phone}</span>` : ''}
                        </p>
                        \${b.is_online == 1 && b.is_dp_paid == 1 ? `<span class="text-[7px] font-black text-emerald-600 uppercase tracking-widest bg-emerald-50 px-1 py-0.5 rounded border border-emerald-100">DP LUNAS</span>` : ''}
                    </div>
                    \${b.mechanic_name ? `
                        <p class="text-[9px] font-semibold text-slate-500 mb-1.5 truncate"><i data-lucide="wrench" class="w-2.5 h-2.5 inline text-slate-400 mr-1"></i>\${b.mechanic_name}</p>
                    ` : ''}
                    \${actions}
                </div>
            `;
        } else {
            // Normal Mode
            columns[b.status].html += `
                <div class="bg-white px-3.5 py-3 rounded-xl border border-slate-200 border-l-[3px] \${borderColor} hover:shadow-md hover:border-slate-300 transition-all duration-200">
                    <div class="flex justify-between items-start mb-2 gap-2">
                        <div class="flex flex-col min-w-0">
                            <h4 class="text-sm font-bold text-slate-900 tracking-wide truncate">\${b.license_plate}</h4>
                            \${b.booking_code ? `<span class="text-[9px] font-medium text-slate-400 font-mono tracking-wide truncate mt-0.5">#\${b.booking_code}</span>` : ''}
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0 flex-wrap justify-end max-w-[65%]">
                            <span class="text-[8px] font-black text-white uppercase tracking-widest \${b.is_online == 1 ? 'bg-indigo-500' : 'bg-slate-400'} px-2 py-0.5 rounded-full">\${b.booking_type}</span>
                            \${b.is_online == 1 && b.is_dp_paid == 1 ? `<span class="text-[8px] font-black text-emerald-600 uppercase tracking-widest bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-100">DP LUNAS</span>` : ''}
                            <span class="text-[10px] font-semibold text-slate-500 bg-slate-50 px-1.5 py-0.5 rounded border border-slate-100">\${b.service_time.substring(0,5)}</span>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="text-[11px] font-semibold text-slate-600 truncate">
                            \${b.customer_name}
                            \${b.customer_phone ? `<span class="text-[10px] font-normal text-slate-400 ml-1">\${b.customer_phone}</span>` : ''}
                        </p>
                        <span class="text-[10px] text-slate-400">•</span>
                        <p class="text-[10px] text-slate-400 truncate italic">\${b.car_model}</p>
                    </div>

                    \${b.mechanic_name ? `
                        <div class="mt-1 flex items-center gap-1.5 text-[9px] font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded-lg w-fit">
                            <i data-lucide="wrench" class="w-3 h-3 text-slate-400"></i>
                            <span>Montir: \${b.mechanic_name}</span>
                        </div>
                    ` : ''}

                    \${b.is_online == 1 ? `
                        <div class="mt-2 flex items-center gap-1.5 text-[9px] font-bold text-indigo-600 bg-indigo-50/50 px-2 py-1 rounded-lg w-fit">
                            <i data-lucide="calendar" class="w-3 h-3"></i>
                            <span>Jadwal: \${formatDate(b.service_date)}</span>
                        </div>
                    ` : ''}

                    \${b.notes ? `
                        <div class="mt-2 px-2.5 py-1.5 bg-slate-50 rounded-lg border border-slate-100">
                            <p class="text-[9px] text-slate-500 leading-relaxed truncate">\${b.notes}</p>
                        </div>
                    ` : ''}

                    \${actions}
                </div>
            `;
        }  // closes else block
        } catch (err) {
            console.error("Error rendering booking:", b.license_plate, err);
        }
    });

    Object.keys(columns).forEach(key => {
        const col = columns[key];
        document.getElementById('count_' + key).textContent = col.count;
        if (col.count === 0) {
            col.el.innerHTML = `
                <div class="h-24 border-2 border-dashed border-slate-200 rounded-xl flex flex-col items-center justify-center text-slate-300 gap-1.5">
                    <i data-lucide="inbox" class="w-6 h-6 opacity-20"></i>
                    <span class="text-[9px] font-bold uppercase tracking-wider opacity-40">Kosong</span>
                </div>
            `;
        } else {
            col.el.innerHTML = col.html;
        }
    });
    lucide.createIcons();
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    const options = { day: '2-digit', month: 'short', year: 'numeric' };
    return date.toLocaleDateString('id-ID', options);
}

let searchDebounce;
function handleSearchInput() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadBoardData();
    }, 500);
}

function reloadBoardData() {
    const dateVal = document.getElementById('boardDate').value;
    const searchVal = document.getElementById('boardSearch') ? document.getElementById('boardSearch').value.trim() : '';
    console.log("Fetching data for date:", dateVal, "search:", searchVal);
    fetch(`antrian.php?ajax_get=1&date=\${dateVal}&search=\${encodeURIComponent(searchVal)}`)
    .then(r => r.json())
    .then(data => {
        console.log("Data received:", data);
        bookings = data;
        renderBoard();
    });
}

// Auto Refresh every 30 seconds
setInterval(() => {
    const dateVal = document.getElementById('boardDate').value;
    const searchVal = document.getElementById('boardSearch') ? document.getElementById('boardSearch').value.trim() : '';
    fetch(`antrian.php?ajax_get=1&date=\${dateVal}&search=\${encodeURIComponent(searchVal)}`)
    .then(r => r.json())
    .then(data => {
        bookings = data;
        console.log("Auto-Refresh Bookings:", bookings);
        renderBoard();
    });
}, 30000);

renderBoard();
</script>

JS;

include '../includes/footer.php';
