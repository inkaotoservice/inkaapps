<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();
if (!has_role(['admin', 'admin_depok', 'admin_bsd', 'spv', 'owner', 'manager_ops'])) {
    die("Akses ditolak.");
}

$branch_id  = $_SESSION['branch_id'] ?? null;
$role       = $_SESSION['role'];
$user_id    = $_SESSION['user_id'];
$can_process = has_role(['admin', 'admin_depok', 'admin_bsd', 'spv']);

// ── AJAX: PROCESS REFUND ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_refund') {
    $booking_id = $_POST['booking_id'];
    try {
        $pdo->beginTransaction();

        $stmt_b = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND refund_status = 'pending'");
        $stmt_b->execute([$booking_id]);
        $booking = $stmt_b->fetch();

        if (!$booking) throw new Exception("Data tidak ditemukan atau sudah diproses.");

        if ($booking['is_dp_paid'] && $booking['dp_amount'] > 0) {
            $exp_id = uuid();
            $desc   = "Refund DP Booking: " . $booking['customer_name'] . " (" . $booking['booking_code'] . ") - Alasan: " . $booking['cancellation_reason'];
            $stmt_exp = $pdo->prepare("INSERT INTO expenses (id, branch_id, expense_date, category, amount, description, created_by) VALUES (?, ?, CURDATE(), 'Refund', ?, ?, ?)");
            $stmt_exp->execute([$exp_id, $booking['branch_id'], $booking['dp_amount'], $desc, $user_id]);
        }

        $stmt_upd = $pdo->prepare("UPDATE bookings SET refund_status = 'completed', refund_processed_by = ?, refund_processed_at = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt_upd->execute([$user_id, $booking_id]);
        $pdo->commit();

        $phone = $booking['customer_phone'];
        if (strpos($phone, '0') === 0) $phone = '62' . substr($phone, 1);

        $message = "Halo Bapak/Ibu " . $booking['customer_name'] . ",\n\nKami dari Inka Otoservice menginformasikan bahwa proses pengembalian dana (Refund DP) sebesar Rp" . number_format($booking['dp_amount'], 0, ',', '.') . " untuk booking nomor " . $booking['booking_code'] . " telah kami proses.\n\nBerikut kami lampirkan bukti transfernya. Mohon dicek kembali mutasi rekening Anda.\n\nTerima kasih,\nInka Otoservice";

        echo json_encode(['success' => true, 'phone' => $phone, 'wa_msg' => $message]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── FILTER PARAMS ────────────────────────────────────────────────
$tab    = $_GET['tab']    ?? 'pending';
$search = trim($_GET['q'] ?? '');
$date   = $_GET['date']   ?? '';

// ── QUERY BUILDER ────────────────────────────────────────────────
function get_refunds($pdo, $branch_id, $role, $tab, $search, $date) {
    $status = ($tab === 'completed') ? 'completed' : 'pending';

    $sql    = "SELECT b.*, p.full_name AS processed_by_name
               FROM bookings b
               LEFT JOIN profiles p ON p.id = b.refund_processed_by
               WHERE b.refund_status = ?
               AND b.status = 'cancelled'";
    $params = [$status];

    if (!in_array($role, ['owner', 'manager_ops'])) {
        $sql    .= " AND b.branch_id = ?";
        $params[] = $branch_id;
    }
    if ($search !== '') {
        $sql    .= " AND (b.customer_name LIKE ? OR b.booking_code LIKE ? OR b.license_plate LIKE ?)";
        $like    = "%{$search}%";
        $params  = array_merge($params, [$like, $like, $like]);
    }
    if ($date !== '') {
        $sql    .= " AND DATE(b.updated_at) = ?";
        $params[] = $date;
    }

    $order   = ($tab === 'completed') ? "b.refund_processed_at DESC" : "b.updated_at ASC";
    $sql    .= " ORDER BY {$order}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$refunds       = get_refunds($pdo, $branch_id, $role, $tab, $search, $date);
$count_pending  = count(get_refunds($pdo, $branch_id, $role, 'pending',   '', ''));
$count_done     = count(get_refunds($pdo, $branch_id, $role, 'completed', '', ''));
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 bg-slate-50 relative">
    <!-- Topbar -->
    <header class="h-12 sm:h-14 flex-shrink-0 flex items-center justify-between px-4 sm:px-5 lg:px-6 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-1.5 lg:hidden text-slate-500 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu" class="w-4 h-4"></i>
        </button>
        <div class="flex-1 px-3 flex items-center gap-3">
            <h1 class="text-xs sm:text-sm font-bold text-slate-900 tracking-tight uppercase">Antrian Refund DP</h1>
            <?php if ($count_pending > 0): ?>
            <span class="bg-red-100 text-red-600 font-bold text-[10px] px-2 py-0.5 rounded-full animate-pulse"><?php echo $count_pending; ?> Menunggu</span>
            <?php endif; ?>
        </div>
    </header>

    <!-- Filter Bar -->
    <div class="flex-shrink-0 bg-white border-b border-slate-200 px-4 sm:px-6 py-3">
        <form method="GET" action="" class="flex flex-wrap items-center gap-3">
            <!-- Tabs -->
            <div class="flex bg-slate-100 rounded-lg p-0.5 gap-0.5">
                <a href="?tab=pending&q=<?php echo urlencode($search); ?>&date=<?php echo urlencode($date); ?>"
                   class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[11px] font-bold transition-all <?php echo $tab !== 'completed' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'; ?>">
                    <i data-lucide="clock" class="w-3 h-3"></i> Menunggu
                    <?php if ($count_pending > 0): ?>
                    <span class="bg-red-500 text-white text-[9px] font-black px-1.5 py-0.5 rounded-full"><?php echo $count_pending; ?></span>
                    <?php endif; ?>
                </a>
                <a href="?tab=completed&q=<?php echo urlencode($search); ?>&date=<?php echo urlencode($date); ?>"
                   class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[11px] font-bold transition-all <?php echo $tab === 'completed' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'; ?>">
                    <i data-lucide="check-circle" class="w-3 h-3"></i> Sudah Diproses
                    <span class="bg-emerald-100 text-emerald-700 text-[9px] font-black px-1.5 py-0.5 rounded-full"><?php echo $count_done; ?></span>
                </a>
            </div>

            <!-- Date Filter -->
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="relative cursor-pointer" onclick="this.querySelector('input').showPicker()">
                <input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>"
                       class="border border-slate-200 rounded-lg pl-3 pr-8 py-1.5 text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white cursor-pointer w-full">
                <i data-lucide="calendar" class="absolute right-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-slate-400 pointer-events-none"></i>
            </div>

            <!-- Search -->
            <div class="relative flex-1 min-w-[160px] max-w-[260px]">
                <i data-lucide="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-slate-400"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Nama, kode, plat..."
                       class="w-full pl-7 pr-3 py-1.5 border border-slate-200 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
            </div>

            <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-bold rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-1.5">
                <i data-lucide="filter" class="w-3 h-3"></i> Filter
            </button>
            <?php if ($search || $date): ?>
            <a href="?tab=<?php echo $tab; ?>" class="px-3 py-1.5 bg-slate-100 text-slate-600 text-xs font-bold rounded-lg hover:bg-slate-200 transition-colors flex items-center gap-1.5">
                <i data-lucide="x" class="w-3 h-3"></i> Reset
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed top-20 right-6 z-50 transform transition-all duration-300 translate-y-[-150%] opacity-0 bg-emerald-500 text-white px-4 py-3 rounded-xl shadow-lg font-bold text-xs flex items-center gap-2">
        <i data-lucide="check-circle" class="w-4 h-4"></i>
        <span id="toastMsg">Berhasil!</span>
    </div>

    <!-- Content -->
    <div class="flex-1 overflow-auto p-4 sm:p-5 lg:p-6">

        <?php if ($tab === 'completed'): ?>
        <!-- Summary Banner for completed tab -->
        <div class="mb-4 p-3 bg-emerald-50 border border-emerald-100 rounded-xl flex items-center gap-3 text-sm">
            <i data-lucide="archive" class="w-5 h-5 text-emerald-500 flex-shrink-0"></i>
            <p class="text-emerald-800 text-xs font-medium">Ini adalah <strong>riwayat refund</strong> yang sudah selesai diproses. Data tidak dapat diubah lagi.</p>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php if (count($refunds) === 0): ?>
                <div class="col-span-full py-16 flex flex-col items-center justify-center bg-white rounded-2xl border border-slate-200/60 border-dashed">
                    <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mb-4">
                        <i data-lucide="<?php echo $tab === 'completed' ? 'archive' : 'check-circle-2'; ?>" class="w-8 h-8 text-<?php echo $tab === 'completed' ? 'slate-400' : 'emerald-500'; ?>"></i>
                    </div>
                    <h3 class="text-slate-800 font-bold mb-1"><?php echo $tab === 'completed' ? 'Belum ada riwayat' : 'Semua Bersih!'; ?></h3>
                    <p class="text-xs text-slate-500"><?php echo $tab === 'completed' ? 'Belum ada refund yang diproses.' : 'Tidak ada antrian refund DP saat ini.'; ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($refunds as $ref): ?>
                <?php $is_done = $ref['refund_status'] === 'completed'; ?>
                    <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 relative overflow-hidden transition-all duration-300" id="card_<?php echo $ref['id']; ?>">
                        <!-- Left accent bar -->
                        <div class="absolute top-0 left-0 w-1 h-full <?php echo $is_done ? 'bg-emerald-400' : 'bg-red-500'; ?>"></div>

                        <!-- Header -->
                        <div class="flex justify-between items-start mb-3 pl-2">
                            <div>
                                <h3 class="font-bold text-slate-900 text-sm leading-tight"><?php echo htmlspecialchars($ref['customer_name']); ?></h3>
                                <p class="text-xs font-semibold text-slate-400 mt-0.5"><?php echo htmlspecialchars($ref['license_plate']); ?> &bull; <?php echo htmlspecialchars($ref['booking_code']); ?></p>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <span class="block font-black <?php echo $is_done ? 'text-emerald-600' : 'text-red-600'; ?>">Rp <?php echo number_format($ref['dp_amount'], 0, ',', '.'); ?></span>
                                <span class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Nominal DP</span>
                            </div>
                        </div>

                        <!-- Status badge -->
                        <div class="pl-2 mb-3">
                            <?php if ($is_done): ?>
                            <span class="inline-flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold px-2 py-0.5 rounded-full">
                                <i data-lucide="check-circle" class="w-2.5 h-2.5"></i> Sudah Direfund
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-bold px-2 py-0.5 rounded-full">
                                <i data-lucide="clock" class="w-2.5 h-2.5"></i> Menunggu Proses
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- Reason -->
                        <div class="pl-2 mb-3">
                            <label class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-1 block">Alasan Pembatalan</label>
                            <div class="p-2.5 bg-red-50/60 border border-red-100 rounded-lg min-h-[36px]">
                                <p class="text-[11px] text-red-800 font-medium italic">
                                    "<?php echo htmlspecialchars($ref['cancellation_reason'] ?: 'Tidak ada alasan diberikan'); ?>"
                                </p>
                            </div>
                        </div>

                        <!-- Processed info (only for completed) -->
                        <?php if ($is_done && $ref['refund_processed_at']): ?>
                        <div class="pl-2 mb-3">
                            <label class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-1 block">Diproses Oleh</label>
                            <div class="flex items-center gap-2 p-2 bg-emerald-50 border border-emerald-100 rounded-lg">
                                <div class="w-6 h-6 bg-emerald-500 rounded-full flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="user-check" class="w-3 h-3 text-white"></i>
                                </div>
                                <div>
                                    <p class="text-[11px] font-bold text-emerald-900"><?php echo htmlspecialchars($ref['processed_by_name'] ?? 'Tidak diketahui'); ?></p>
                                    <p class="text-[10px] text-emerald-600"><?php echo date('d M Y, H:i', strtotime($ref['refund_processed_at'])); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div class="pl-2 flex flex-col gap-2 pt-3 border-t border-slate-100 mt-auto">
                            <?php if (!$is_done && $can_process): ?>
                            <button
                                data-id="<?php echo $ref['id']; ?>"
                                onclick="processRefund(this.dataset.id, this)"
                                class="w-full py-2 bg-emerald-500 text-white rounded-lg text-[11px] font-bold uppercase tracking-wider flex justify-center items-center gap-1.5 hover:bg-emerald-600 transition-colors shadow-sm">
                                <i data-lucide="check-square" class="w-3.5 h-3.5"></i> Proses & Selesai
                            </button>
                            <?php elseif (!$is_done && !$can_process): ?>
                            <div class="w-full py-2 bg-slate-100 text-slate-400 rounded-lg text-[10px] font-bold uppercase tracking-wider flex justify-center items-center gap-1.5 border border-slate-200">
                                <i data-lucide="lock" class="w-3 h-3"></i> Hanya Bisa Dilihat
                            </div>
                            <?php endif; ?>

                            <!-- Hubungi WA — always visible -->
                            <button
                                data-phone="<?php echo htmlspecialchars($ref['customer_phone']); ?>"
                                data-name="<?php echo htmlspecialchars($ref['customer_name']); ?>"
                                data-code="<?php echo htmlspecialchars($ref['booking_code']); ?>"
                                data-done="<?php echo $is_done ? '1' : '0'; ?>"
                                onclick="contactCustomer(this.dataset.phone, this.dataset.name, this.dataset.code, this.dataset.done)"
                                class="w-full py-2 bg-white text-<?php echo $is_done ? 'slate-600' : 'emerald-600'; ?> border border-<?php echo $is_done ? 'slate-200' : 'emerald-200'; ?> rounded-lg text-[11px] font-bold uppercase tracking-wider flex justify-center items-center gap-1.5 hover:bg-slate-50 transition-colors">
                                <i data-lucide="message-circle" class="w-3.5 h-3.5"></i>
                                <?php echo $is_done ? 'Follow-up WA' : 'Hubungi WA'; ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Confirm Modal -->
    <div id="confirmModal" class="fixed inset-0 z-[250] bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-[320px] p-6 shadow-2xl transform transition-all scale-95 opacity-0" id="confirmModalContent">
            <div class="flex flex-col items-center text-center">
                <div class="w-14 h-14 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center mb-4">
                    <i data-lucide="shield-check" class="w-7 h-7"></i>
                </div>
                <h3 id="confirmTitle" class="font-bold text-slate-900 text-base mb-2">Konfirmasi Refund</h3>
                <p id="confirmMessage" class="text-xs text-slate-500 leading-relaxed mb-6">Pastikan Anda sudah mentransfer uang refund. Tindakan ini akan mencatat pengeluaran di sistem dan tidak bisa dibatalkan.</p>
            </div>
            <div class="flex gap-3">
                <button onclick="closeConfirm(false)" class="flex-1 py-2.5 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-200 transition-all">Batal</button>
                <button id="confirmBtn" onclick="closeConfirm(true)" class="flex-1 py-2.5 bg-emerald-500 text-white rounded-xl text-xs font-bold hover:bg-emerald-600 transition-all shadow-sm shadow-emerald-200">Ya, Proses</button>
            </div>
        </div>
    </div>
</main>

<?php
$extra_js = <<<JS
<script>
function showToast(msg) {
    const t = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    t.classList.remove('translate-y-[-150%]', 'opacity-0');
    setTimeout(() => t.classList.add('translate-y-[-150%]', 'opacity-0'), 3000);
}

let confirmPromise = null;
function showConfirm() {
    const modal   = document.getElementById('confirmModal');
    const content = document.getElementById('confirmModalContent');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => {
        content.classList.remove('scale-95', 'opacity-0');
        content.classList.add('scale-100', 'opacity-100');
    }, 10);
    return new Promise(resolve => { confirmPromise = resolve; });
}
function closeConfirm(result) {
    const modal   = document.getElementById('confirmModal');
    const content = document.getElementById('confirmModalContent');
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.remove('flex');
        modal.classList.add('hidden');
        if (confirmPromise) confirmPromise(result);
        confirmPromise = null;
    }, 200);
}

async function processRefund(id, btn) {
    const ok = await showConfirm();
    if (!ok) return;

    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Memproses...';
    lucide.createIcons();

    const fd = new FormData();
    fd.append('action', 'process_refund');
    fd.append('booking_id', id);

    fetch('antrian-refund.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            showToast('Refund berhasil diproses!');
            const card = document.getElementById('card_' + id);
            card.style.transition = 'all 0.3s';
            card.style.opacity = '0';
            card.style.transform = 'scale(0.95)';
            setTimeout(() => card.remove(), 300);
            const waUrl = 'https://wa.me/' + res.phone + '?text=' + encodeURIComponent(res.wa_msg);
            window.open(waUrl, '_blank');
        } else {
            alert('Gagal: ' + res.error);
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="check-square" class="w-3.5 h-3.5"></i> Proses & Selesai';
            lucide.createIcons();
        }
    })
    .catch(() => {
        alert('Terjadi kesalahan sistem.');
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="check-square" class="w-3.5 h-3.5"></i> Proses & Selesai';
        lucide.createIcons();
    });
}

function contactCustomer(phone, name, code, isDone) {
    let waPhone = phone;
    if (waPhone.startsWith('0')) waPhone = '62' + waPhone.substring(1);

    let msg;
    if (isDone === '1') {
        msg = "Halo Bapak/Ibu " + name + ",\\n\\nIni adalah follow-up dari Inka Otoservice terkait Refund DP booking " + code + " yang telah kami proses sebelumnya. Apakah dana sudah diterima dengan baik?\\n\\nTerima kasih.";
    } else {
        msg = "Halo Bapak/Ibu " + name + ",\\n\\nKami dari Inka Otoservice terkait permohonan Refund DP untuk booking " + code + ". Mohon informasikan nomor rekening Anda untuk proses pengembalian dana.\\n\\nTerima kasih.";
    }

    const waUrl = 'https://wa.me/' + waPhone + '?text=' + encodeURIComponent(msg);
    window.open(waUrl, '_blank');
}
</script>
JS;
?>
<?php include '../includes/footer.php'; ?>
