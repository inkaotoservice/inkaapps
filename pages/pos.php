<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

if (!has_role(['admin','admin_depok','admin_bsd','owner','manager_ops'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

// ── AJAX HANDLERS ────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];

    if ($action === 'get_catalog') {
        $q = $_GET['q'] ?? '';
        $sql = "SELECT id, name, category, price, cost_price, stock FROM catalog WHERE is_active = 1";
        if ($q) { $sql .= " AND (name LIKE ? OR category LIKE ?)"; }
        $sql .= " ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        if ($q) { $stmt->execute(["%$q%", "%$q%"]); } else { $stmt->execute(); }
        echo json_encode($stmt->fetchAll()); exit;
    }

    if ($action === 'search_booking') {
        $q = $_GET['q'] ?? '';
        $branch_id = $_SESSION['branch_id'] ?? null;
        $sql = "SELECT b.* FROM bookings b WHERE (b.customer_name LIKE ? OR b.license_plate LIKE ? OR b.car_model LIKE ?)";
        if ($branch_id) { $sql .= " AND b.branch_id = '$branch_id'"; }
        $sql .= " AND b.status IN ('pending','processing') LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
        echo json_encode($stmt->fetchAll()); exit;
    }

    if ($action === 'process_transaction') {
        $data = json_decode(file_get_contents('php://input'), true);
        $status = $data['status'] ?? 'Draft';
        $transaction_id = $data['transaction_id'] ?: uuid();
        $pdo->beginTransaction();
        try {
            if (!empty($data['transaction_id'])) {
                // Ambil status lama sebelum diupdate
                $stmt_old = $pdo->prepare("SELECT status FROM transactions WHERE id = ?");
                $stmt_old->execute([$transaction_id]);
                $old_tx = $stmt_old->fetch();

                // Jika status sebelumnya 'Paid', kembalikan stok lama terlebih dahulu
                if ($old_tx && $old_tx['status'] === 'Paid') {
                    $stmt_old_items = $pdo->prepare("
                        SELECT ti.catalog_id, ti.qty, c.category 
                        FROM transaction_items ti 
                        JOIN catalog c ON ti.catalog_id = c.id 
                        WHERE ti.transaction_id = ? AND c.category = 'Spare Part'
                    ");
                    $stmt_old_items->execute([$transaction_id]);
                    foreach ($stmt_old_items->fetchAll() as $old_item) {
                        $pdo->prepare("UPDATE catalog SET stock = stock + ? WHERE id = ?")
                            ->execute([$old_item['qty'], $old_item['catalog_id']]);
                    }
                }
                
                // Hapus item lama untuk diganti yang baru (update keranjang)
                $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id = ?")->execute([$transaction_id]);
            }

            $stmt = $pdo->prepare("INSERT INTO transactions (id, booking_id, branch_id, customer_name, total_amount, dp_amount, payment_method, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                booking_id = VALUES(booking_id),
                customer_name = VALUES(customer_name), 
                total_amount = VALUES(total_amount), 
                dp_amount = VALUES(dp_amount),
                payment_method = VALUES(payment_method), 
                status = VALUES(status)");
            
            $stmt->execute([
                $transaction_id,
                $data['booking_id'],
                $_SESSION['branch_id'] ?? null,
                $data['customer_name'],
                $data['total_amount'],
                $data['dp_amount'] ?? 0,
                $data['payment_method'] ?? 'Cash',
                $status
            ]);

            foreach ($data['items'] as $item) {
                if ($status === 'Paid' && $item['category'] === 'Spare Part') {
                    $stmt_stok = $pdo->prepare("SELECT stock, name FROM catalog WHERE id = ?");
                    $stmt_stok->execute([$item['id']]);
                    $stok_now = $stmt_stok->fetch();
                    if ($stok_now['stock'] < $item['qty']) {
                        throw new Exception("Stok tidak cukup untuk: " . $stok_now['name'] . " (Sisa: " . $stok_now['stock'] . ")");
                    }
                    $pdo->prepare("UPDATE catalog SET stock = stock - ? WHERE id = ?")->execute([$item['qty'], $item['id']]);
                }
                $stmt_ti = $pdo->prepare("INSERT INTO transaction_items (id, transaction_id, catalog_id, qty, price_at_sale, cost_at_sale) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_ti->execute([uuid(), $transaction_id, $item['id'], $item['qty'], $item['price'], $item['cost_price'] ?: 0]);
            }

            if ($status === 'Paid' && !empty($data['booking_id'])) {
                $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE id = ?")->execute([$data['booking_id']]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'transaction_id' => $transaction_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ── LOAD DATA AWAL (Pencarian Draft Aktif) ────────────────────────
$initial_data = ['activeCustomer' => null, 'cart' => [], 'currentDraftId' => $_GET['draft_id'] ?? '', 'currentBookingId' => $_GET['booking_id'] ?? ''];

// 1. Prioritas ID Draft Langsung
if (!empty($initial_data['currentDraftId'])) {
    $stmt = $pdo->prepare("SELECT t.*, b.car_model, b.license_plate, b.customer_phone FROM transactions t LEFT JOIN bookings b ON t.booking_id = b.id WHERE t.id = ?");
    $stmt->execute([$initial_data['currentDraftId']]);
    $draft = $stmt->fetch();
} 
// 2. Jika ada Booking ID, cari apakah sudah ada transaksi terkait (termasuk Paid agar history tetap tampil saat di-revisi)
elseif (!empty($initial_data['currentBookingId'])) {
    $stmt = $pdo->prepare("SELECT t.*, b.car_model, b.license_plate, b.customer_phone FROM transactions t LEFT JOIN bookings b ON t.booking_id = b.id WHERE t.booking_id = ? AND t.status IN ('Draft', 'In Progress', 'Paid') ORDER BY t.created_at DESC LIMIT 1");
    $stmt->execute([$initial_data['currentBookingId']]);
    $draft = $stmt->fetch();
    
    if (!$draft) {
        $stmt_bk = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt_bk->execute([$initial_data['currentBookingId']]);
        $booking = $stmt_bk->fetch();
        if ($booking) {
            $initial_data['activeCustomer'] = [
                'id' => $booking['id'], 
                'customer_name' => $booking['customer_name'], 
                'car_model' => $booking['car_model'], 
                'license_plate' => $booking['license_plate'],
                'customer_phone' => $booking['customer_phone'],
                'dp_amount' => $booking['dp_amount']
            ];
        }
    }
}
// 3. Jika hanya ada Nama Customer (Direct), cari Draft aktif dengan nama tsb
elseif (!empty($_GET['customer'])) {
    $raw_customer = $_GET['customer']; $name = $raw_customer; $plate = $_GET['plate'] ?? '';
    if (strpos($raw_customer, ' - ') !== false) { list($name, $plate) = explode(' - ', $raw_customer, 2); }
    $name = trim($name); $plate = trim($plate);
    $stmt = $pdo->prepare("SELECT t.*, b.car_model, b.license_plate, b.customer_phone FROM transactions t LEFT JOIN bookings b ON t.booking_id = b.id WHERE t.customer_name = ? AND t.status IN ('Draft', 'In Progress') AND t.branch_id = ? ORDER BY t.created_at DESC LIMIT 1");
    $stmt->execute([$name, $_SESSION['branch_id'] ?? null]);
    $draft = $stmt->fetch();
    if (!$draft) { $initial_data['activeCustomer'] = ['id' => null, 'customer_name' => $name, 'car_model' => '', 'license_plate' => $plate]; }
}

if (isset($draft) && $draft) {
    $initial_data['currentDraftId'] = $draft['id'];
    $initial_data['activeCustomer'] = [
        'id' => $draft['booking_id'], 
        'customer_name' => $draft['customer_name'], 
        'car_model' => $draft['car_model'] ?? '', 
        'license_plate' => $draft['license_plate'] ?? '', 
        'customer_phone' => $draft['customer_phone'] ?? '',
        'dp_amount' => $draft['dp_amount'] ?? 0
    ];
    $initial_data['currentBookingId'] = $draft['booking_id'];
    $stmt_items = $pdo->prepare("SELECT ti.*, c.name, c.category, c.stock FROM transaction_items ti JOIN catalog c ON ti.catalog_id = c.id WHERE ti.transaction_id = ?");
    $stmt_items->execute([$draft['id']]);
    foreach($stmt_items->fetchAll() as $di) {
        $initial_data['cart'][] = ['id' => $di['catalog_id'], 'name' => $di['name'], 'category' => $di['category'], 'price' => (int)$di['price_at_sale'], 'cost_price' => (int)$di['cost_at_sale'], 'qty' => (int)$di['qty'], 'stock' => $di['stock']];
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative bg-slate-50 lg:flex-row">
    <div class="flex-1 flex flex-col min-w-0 border-r border-slate-200 bg-white">
        <header class="h-14 flex-shrink-0 flex items-center px-4 lg:px-5 border-b border-slate-100 bg-white/90 backdrop-blur-md sticky top-0 z-20 gap-3">
            <button onclick="openSidebar()" class="p-1.5 lg:hidden text-slate-500 hover:bg-slate-100 rounded-lg transition-colors"><i data-lucide="menu" class="w-4 h-4"></i></button>
            <div class="relative flex-1 max-w-sm group">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 w-4 h-4 group-focus-within:text-blue-600 transition-colors"></i>
                <input type="text" id="catalogSearch" oninput="debounceSearch()" placeholder="Cari layanan / sparepart..." class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-semibold focus:border-blue-400 focus:bg-white focus:ring-2 focus:ring-blue-500/10 outline-none transition-all">
            </div>
            <div class="hidden sm:flex gap-0.5 p-0.5 bg-slate-100 rounded-lg">
                <button onclick="filterCategory('all')" id="btn-all" class="px-3 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-white shadow-sm text-blue-600 transition-all">Semua</button>
                <button onclick="filterCategory('Service')" id="btn-service" class="px-3 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-wider text-slate-400 hover:bg-white/60 transition-all">Service</button>
                <button onclick="filterCategory('Spare Part')" id="btn-spare" class="px-3 py-1.5 rounded-md text-[10px] font-bold uppercase tracking-wider text-slate-400 hover:bg-white/60 transition-all">Sparepart</button>
            </div>
        </header>
        <div class="flex-1 overflow-y-auto p-3 lg:p-4 custom-scrollbar"><div id="catalogGrid" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2.5"></div></div>
    </div>

    <div class="w-full lg:w-[450px] xl:w-[500px] flex flex-col bg-slate-50 border-l border-slate-200">
        <div class="px-4 py-3 bg-white border-b border-slate-200">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1.5">
                    <i data-lucide="user" class="w-3.5 h-3.5 text-blue-600"></i> Pelanggan
                </h3>
                <button onclick="showBookingSearch()" class="flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white rounded-lg font-bold text-[9px] uppercase tracking-wider hover:bg-blue-700 active:scale-95 transition-all shadow-sm shadow-blue-500/20">
                    <i data-lucide="search" class="w-3 h-3"></i> Cari Antrian
                </button>
            </div>
            <div id="customerDisplay" class="bg-slate-50 rounded-xl p-3 border border-slate-100 relative min-h-[56px] flex items-center justify-center">
                <div id="noCustomer" class="text-center"><p class="text-[10px] font-medium text-slate-400 italic">Pilih antrian atau cari pelanggan</p></div>
                <div id="hasCustomer" class="hidden w-full"><div class="flex justify-between items-center"><div class="flex-1 min-w-0"><p id="dispName" class="font-bold text-slate-900 text-xs truncate"></p><div class="flex items-center gap-2 mt-1"><p id="dispCar" class="text-[10px] text-slate-500 truncate"></p><p id="dispPlate" class="text-[9px] font-bold text-blue-600 tracking-wider bg-blue-50 px-1.5 py-0.5 rounded border border-blue-100"></p></div></div><button onclick="clearCustomer()" class="p-1.5 text-slate-300 hover:text-red-500 transition-colors"><i data-lucide="x" class="w-3.5 h-3.5"></i></button></div></div>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-4 py-3 custom-scrollbar">
            <div class="flex items-center justify-between mb-3"><h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1.5"><i data-lucide="shopping-cart" class="w-3.5 h-3.5 text-blue-600"></i> Rincian Nota</h3><span id="cartCount" class="bg-blue-600 text-white text-[9px] font-bold px-2 py-0.5 rounded-md">0 Item</span></div>
            <div id="cartItems" class="space-y-2"><div id="emptyCart" class="text-center py-10 flex flex-col items-center opacity-25"><i data-lucide="shopping-bag" class="w-10 h-10 mb-2"></i><p class="text-[10px] font-bold uppercase tracking-widest">Belum ada item</p></div></div>
        </div>

        <div class="px-4 py-3 bg-white border-t border-slate-200">
            <div class="space-y-2 mb-4">
                <div class="flex justify-between items-center text-xs"><span class="font-medium text-slate-400">Subtotal</span><span id="summarySubtotal" class="font-bold text-slate-700">Rp 0</span></div>
                <div class="pt-2 border-t border-slate-100 flex justify-between items-center"><span class="text-sm font-bold text-slate-900">Total</span><span id="summaryTotal" class="text-lg font-black text-blue-600">Rp 0</span></div>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-4">
                <button onclick="saveDraft()" class="flex items-center justify-center gap-1.5 py-3 bg-emerald-600 text-white rounded-xl font-bold text-[10px] uppercase hover:bg-emerald-700 active:scale-[0.97] transition-all shadow-sm"><i data-lucide="save" class="w-3.5 h-3.5"></i> Draft</button>
                <button onclick="processCheckout()" class="flex items-center justify-center gap-1.5 py-3 bg-blue-600 text-white rounded-xl font-bold text-[10px] uppercase hover:bg-blue-700 active:scale-[0.97] transition-all shadow-sm shadow-blue-500/20"><i data-lucide="credit-card" class="w-3.5 h-3.5"></i> Bayar & Cetak</button>
            </div>
        </div>
    </div>
</main>

<div id="modalSearch" class="fixed inset-0 z-[100] hidden"><div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalSearch')"></div><div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-xl p-4"><div class="bg-white rounded-[2.5rem] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200"><div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50"><h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">Cari Antrian / Pelanggan</h3><button onclick="closeModal('modalSearch')" class="p-2 hover:bg-slate-200 rounded-full transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button></div><div class="p-6"><div class="relative mb-6"><i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 w-5 h-5"></i><input type="text" id="bookingSearchInput" oninput="searchBooking()" placeholder="Ketik Plat Nomor atau Nama..." class="w-full pl-12 pr-4 py-4 bg-slate-100 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-blue-600/20 outline-none"></div><div id="bookingResults" class="space-y-3 max-h-[400px] overflow-y-auto custom-scrollbar pr-2"></div></div></div></div></div>

<!-- Modal Confirm Checkout (Simple) -->
<div id="modalCheckoutConfirm" class="fixed inset-0 z-[105] hidden flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalCheckoutConfirm')"></div>
    <div class="relative w-full max-w-sm bg-white rounded-2xl shadow-xl p-6 animate-in zoom-in-95 duration-200">
        <h3 class="text-lg font-black text-slate-900 mb-1">Konfirmasi Pembayaran</h3>
        <p class="text-sm text-slate-500 font-medium mb-2">Total tagihan yang harus dibayar:</p>
        <div class="text-3xl font-black text-blue-600 mb-6" id="confirmTotalRp">Rp 0</div>

        <div class="mb-6">
            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">Pilih Metode Pembayaran</label>
            <div class="grid grid-cols-3 gap-2">
                <label class="cursor-pointer group">
                    <input type="radio" name="modal_pay_method" value="Cash" checked class="hidden peer">
                    <div class="flex flex-col items-center gap-2 p-3 rounded-xl border-2 border-slate-100 bg-slate-50 text-slate-400 peer-checked:border-blue-600 peer-checked:text-blue-600 peer-checked:bg-blue-50 transition-all hover:border-slate-200">
                        <i data-lucide="banknote" class="w-5 h-5"></i>
                        <span class="text-[9px] font-bold uppercase">Cash</span>
                    </div>
                </label>
                <label class="cursor-pointer group">
                    <input type="radio" name="modal_pay_method" value="Transfer" class="hidden peer">
                    <div class="flex flex-col items-center gap-2 p-3 rounded-xl border-2 border-slate-100 bg-slate-50 text-slate-400 peer-checked:border-blue-600 peer-checked:text-blue-600 peer-checked:bg-blue-50 transition-all hover:border-slate-200">
                        <i data-lucide="landmark" class="w-5 h-5"></i>
                        <span class="text-[9px] font-bold uppercase">Transfer</span>
                    </div>
                </label>
                <label class="cursor-pointer group">
                    <input type="radio" name="modal_pay_method" value="QRIS" class="hidden peer">
                    <div class="flex flex-col items-center gap-2 p-3 rounded-xl border-2 border-slate-100 bg-slate-50 text-slate-400 peer-checked:border-blue-600 peer-checked:text-blue-600 peer-checked:bg-blue-50 transition-all hover:border-slate-200">
                        <i data-lucide="qr-code" class="w-5 h-5"></i>
                        <span class="text-[9px] font-bold uppercase">QRIS</span>
                    </div>
                </label>
            </div>
        </div>
        
        <div class="flex gap-3">
            <button onclick="closeModal('modalCheckoutConfirm')" class="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl font-bold transition-colors">Batal</button>
            <button onclick="executeCheckout()" class="flex-1 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold shadow-lg shadow-blue-500/30 transition-colors">Bayar</button>
        </div>
    </div>
</div>

<!-- Modal Success Checkout -->
<div id="modalSuccess" class="fixed inset-0 z-[110] hidden flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md"></div>
    <div class="relative w-full max-w-md bg-white rounded-[2.5rem] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-300">
        <div class="p-8 text-center">
            <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-6">
                <i data-lucide="check-circle" class="w-10 h-10"></i>
            </div>
            <h3 class="text-2xl font-black text-slate-900 mb-2">Transaksi Berhasil!</h3>
            <p class="text-slate-500 font-medium mb-8">Silakan pilih metode pengiriman nota untuk <span id="successCustomer" class="font-bold text-slate-900"></span></p>
            
            <div class="grid grid-cols-2 gap-3 mb-4">
                <button id="btnPrintNota" class="flex flex-col items-center justify-center gap-1.5 py-4 bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white border border-blue-100 rounded-2xl font-bold transition-all text-[11px] uppercase tracking-wider">
                    <i data-lucide="printer" class="w-6 h-6 mb-1"></i>
                    Cetak Nota
                </button>
                <button id="btnWA" class="flex flex-col items-center justify-center gap-1.5 py-4 bg-emerald-50 text-emerald-600 hover:bg-emerald-500 hover:text-white border border-emerald-100 rounded-2xl font-bold transition-all text-[11px] uppercase tracking-wider">
                    <i data-lucide="message-circle" class="w-6 h-6 mb-1"></i>
                    Kirim WA
                </button>
            </div>
            
            <div class="border-t border-slate-100 pt-5">
                <button onclick="finishTransaction()" class="flex items-center justify-center gap-2 w-full py-4 bg-slate-900 hover:bg-slate-800 text-white rounded-2xl font-bold transition-all shadow-xl shadow-slate-900/20 uppercase tracking-widest text-xs">
                    Selesai & Transaksi Baru
                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div id="toast" class="fixed top-8 left-1/2 -translate-x-1/2 z-[200] transition-all duration-500 transform translate-y-[-150%] opacity-0 pointer-events-none"><div id="toastContent" class="bg-white rounded-2xl shadow-2xl border-2 p-4 flex items-center gap-4 min-w-[320px]"><div id="toastIcon" class="w-10 h-10 rounded-xl flex items-center justify-center"></div><div><h4 id="toastTitle" class="font-black text-slate-900 text-sm"></h4><p id="toastMsg" class="text-xs text-slate-500 font-bold mt-0.5"></p></div></div></div>

<script>
let catalog = []; let cart = <?php echo json_encode($initial_data['cart']); ?>;
let activeCustomer = <?php echo json_encode($initial_data['activeCustomer']); ?>;
let currentDraftId = '<?php echo $initial_data['currentDraftId']; ?>';
let currentBookingId = '<?php echo $initial_data['currentBookingId']; ?>';
let selectedCategory = 'all';

document.addEventListener('DOMContentLoaded', () => { fetchCatalog(); updateUI(); });

function fetchCatalog() {
    const q = document.getElementById('catalogSearch').value;
    fetch(`pos.php?ajax=get_catalog&q=${encodeURIComponent(q)}`).then(r => r.json()).then(data => { catalog = data; renderCatalog(); });
}

function renderCatalog() {
    const grid = document.getElementById('catalogGrid');
    const filtered = catalog.filter(item => selectedCategory === 'all' || item.category === selectedCategory);
    if (filtered.length === 0) { grid.innerHTML = `<div class="col-span-full py-16 text-center opacity-20"><i data-lucide="inbox" class="w-10 h-10 mx-auto mb-3"></i><p class="font-bold uppercase tracking-widest text-[10px]">Tidak ada item</p></div>`; lucide.createIcons(); return; }
    grid.innerHTML = filtered.map(item => {
        const isService = item.category === 'Service';
        const borderColor = isService ? 'border-l-blue-500' : 'border-l-amber-500';
        const badgeBg = isService ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600';
        const iconName = isService ? 'wrench' : 'package';
        return `
        <div onclick="addToCart('${item.id}')" class="bg-white rounded-xl border border-slate-200 border-l-[3px] ${borderColor} hover:shadow-lg hover:border-slate-300 transition-all cursor-pointer group active:scale-[0.97] flex flex-col">
            <div class="px-3 pt-3 pb-2 flex-1">
                <div class="flex items-start justify-between gap-1 mb-1.5">
                    <span class="inline-flex items-center gap-1 text-[8px] font-bold uppercase tracking-wider ${badgeBg} px-1.5 py-0.5 rounded">
                        <i data-lucide="${iconName}" class="w-2.5 h-2.5"></i>${isService ? 'Service' : 'Part'}
                    </span>
                    ${!isService ? `<span class="text-[8px] font-medium text-slate-400">Stok: ${item.stock}</span>` : ''}
                </div>
                <h4 class="font-bold text-slate-800 text-[11px] leading-snug group-hover:text-blue-600 transition-colors line-clamp-2">${item.name}</h4>
            </div>
            <div class="px-3 pb-2.5 pt-1 border-t border-slate-50">
                <p class="text-xs font-bold text-slate-900">Rp ${parseInt(item.price).toLocaleString()}</p>
            </div>
        </div>`;
    }).join('');
    lucide.createIcons();
}

let searchTimer; function debounceSearch() { clearTimeout(searchTimer); searchTimer = setTimeout(fetchCatalog, 300); }
function filterCategory(cat) { selectedCategory = cat; document.querySelectorAll('[id^="btn-"]').forEach(btn => { btn.classList.remove('bg-white', 'shadow-sm', 'text-blue-600'); btn.classList.add('text-slate-400'); }); document.getElementById('btn-' + (cat === 'all' ? 'all' : (cat === 'Service' ? 'service' : 'spare'))).classList.remove('text-slate-400'); document.getElementById('btn-' + (cat === 'all' ? 'all' : (cat === 'Service' ? 'service' : 'spare'))).classList.add('bg-white', 'shadow-sm', 'text-blue-600'); renderCatalog(); }

function addToCart(id) {
    const item = catalog.find(i => i.id === id); if (!item) return;
    const existing = cart.find(i => i.id === id); if (existing) { existing.qty++; } else { cart.push({ ...item, qty: 1 }); }
    updateUI(); showToast('success', 'Berhasil', `${item.name} ditambahkan`);
}
function updateQty(id, delta) { const item = cart.find(i => i.id === id); if (!item) return; item.qty += delta; if (item.qty <= 0) cart = cart.filter(i => i.id !== id); updateUI(); }
function removeFromCart(id) { cart = cart.filter(i => i.id !== id); updateUI(); }

function updateUI() {
    const cartEl = document.getElementById('cartItems');
    if (cart.length === 0) {
        cartEl.innerHTML = `<div class="text-center py-8 flex flex-col items-center opacity-20"><i data-lucide="shopping-bag" class="w-8 h-8 mb-2"></i><p class="text-[10px] font-bold uppercase tracking-widest">Belum ada item</p></div>`;
    } else {
        cartEl.innerHTML = cart.map(item => `
            <div class="bg-white px-3 py-2.5 rounded-xl border border-slate-100 flex items-center gap-3 group hover:border-slate-200 transition-colors">
                <div class="flex-1 min-w-0">
                    <h5 class="text-[11px] font-bold text-slate-800 truncate">${item.name}</h5>
                    <p class="text-[10px] text-slate-400 mt-0.5">@ Rp ${parseInt(item.price).toLocaleString()}</p>
                </div>
                <div class="flex items-center gap-1 bg-slate-50 rounded-lg p-0.5 border border-slate-100">
                    <button onclick="updateQty('${item.id}', -1)" class="w-6 h-6 flex items-center justify-center rounded-md hover:bg-white text-slate-400 hover:text-red-500 transition-all"><i data-lucide="minus" class="w-3 h-3"></i></button>
                    <span class="text-[11px] font-bold text-slate-900 w-5 text-center">${item.qty}</span>
                    <button onclick="updateQty('${item.id}', 1)" class="w-6 h-6 flex items-center justify-center rounded-md hover:bg-white text-slate-400 hover:text-blue-600 transition-all"><i data-lucide="plus" class="w-3 h-3"></i></button>
                </div>
                <div class="text-right min-w-[70px]"><p class="text-[11px] font-bold text-slate-900">Rp ${(item.price * item.qty).toLocaleString()}</p></div>
                <button onclick="removeFromCart('${item.id}')" class="p-1 text-slate-200 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
            </div>
        `).join('');
    }
    const subtotal = cart.reduce((acc, item) => acc + (item.price * item.qty), 0);
    const dp = (activeCustomer && activeCustomer.dp_amount) ? parseInt(activeCustomer.dp_amount) : 0;
    const total = subtotal - dp;
    
    document.getElementById('summarySubtotal').innerText = 'Rp ' + subtotal.toLocaleString();
    
    // Manage DP display row
    let dpRow = document.getElementById('summaryDPRow');
    if (!dpRow) {
        const totalRow = document.getElementById('summaryTotal').parentElement;
        dpRow = document.createElement('div');
        dpRow.id = 'summaryDPRow';
        dpRow.className = 'flex justify-between items-center text-emerald-600 font-bold text-xs mb-2';
        dpRow.innerHTML = `<span>DP Terpotong</span><span id="summaryDPValue">Rp 0</span>`;
        totalRow.parentElement.insertBefore(dpRow, totalRow);
    }
    
    if (dp > 0) {
        dpRow.classList.remove('hidden');
        document.getElementById('summaryDPValue').innerText = '- Rp ' + dp.toLocaleString();
    } else {
        dpRow.classList.add('hidden');
    }
    
    document.getElementById('summaryTotal').innerText = 'Rp ' + total.toLocaleString();
    document.getElementById('cartCount').innerText = `${cart.length} Item`;
    
    if (activeCustomer) { 
        document.getElementById('noCustomer').classList.add('hidden'); 
        document.getElementById('hasCustomer').classList.remove('hidden'); 
        document.getElementById('dispName').innerText = activeCustomer.customer_name; 
        document.getElementById('dispCar').innerText = activeCustomer.car_model || 'Direct'; 
        document.getElementById('dispPlate').innerText = activeCustomer.license_plate || '-'; 
    }
    else { 
        document.getElementById('noCustomer').classList.remove('hidden'); 
        document.getElementById('hasCustomer').classList.add('hidden'); 
    }
    lucide.createIcons();
}

function showBookingSearch() { document.getElementById('modalSearch').classList.remove('hidden'); document.getElementById('bookingSearchInput').focus(); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function searchBooking() {
    const q = document.getElementById('bookingSearchInput').value; if (q.length < 2) return;
    fetch(`pos.php?ajax=search_booking&q=${encodeURIComponent(q)}`).then(r => r.json()).then(data => {
        const res = document.getElementById('bookingResults'); if (data.length === 0) { res.innerHTML = '<p class="text-center text-xs font-bold text-slate-400 py-10">Tidak ditemukan</p>'; return; }
        res.innerHTML = data.map(b => `<div onclick="selectCustomer(${JSON.stringify(b).replace(/"/g, '&quot;')})" class="p-4 bg-slate-50 rounded-2xl border border-slate-100 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition-all flex justify-between items-center group"><div><p class="font-black text-slate-900 text-sm">${b.customer_name}</p><p class="text-[11px] font-bold text-slate-500">${b.car_model} • ${b.license_plate}</p></div><div class="text-right"><i data-lucide="chevron-right" class="w-4 h-4 text-slate-300"></i></div></div>`).join(''); lucide.createIcons();
    });
}
function selectCustomer(b) { activeCustomer = b; currentBookingId = b.id || ''; updateUI(); closeModal('modalSearch'); showToast('success', 'Customer Dipilih', b.customer_name); }
function clearCustomer() { activeCustomer = null; currentBookingId = ''; updateUI(); }

function saveDraft() {
    if (cart.length === 0) { showToast('error', 'Gagal', 'Tambahkan minimal 1 item sebelum menyimpan draft'); return; }
    if (!activeCustomer) { showToast('error', 'Gagal', 'Pilih pelanggan terlebih dahulu'); return; }
    processTransaction('Draft');
}
function processCheckout() { 
    if (cart.length === 0) { showToast('error', 'Gagal', 'Keranjang kosong'); return; } 
    const subtotal = cart.reduce((acc, item) => acc + (item.price * item.qty), 0);
    const dp = (activeCustomer && activeCustomer.dp_amount) ? parseInt(activeCustomer.dp_amount) : 0;
    const total = subtotal - dp;
    document.getElementById('confirmTotalRp').innerText = 'Rp ' + total.toLocaleString();
    
    // Reset selection to Cash when modal opens
    document.querySelector('input[name="modal_pay_method"][value="Cash"]').checked = true;
    
    document.getElementById('modalCheckoutConfirm').classList.remove('hidden');
    lucide.createIcons();
}

function executeCheckout() {
    closeModal('modalCheckoutConfirm');
    processTransaction('Paid');
}

function processTransaction(status) {
    const subtotal = cart.reduce((acc, item) => acc + (item.price * item.qty), 0);
    const dp = (activeCustomer && activeCustomer.dp_amount) ? parseInt(activeCustomer.dp_amount) : 0;
    const data = {
        transaction_id: currentDraftId,
        customer_name: activeCustomer ? activeCustomer.customer_name : 'Guest',
        booking_id: currentBookingId || (activeCustomer ? activeCustomer.id : null) || null,
        payment_method: status === 'Paid' 
            ? document.querySelector('input[name="modal_pay_method"]:checked').value 
            : 'Cash',
        total_amount: subtotal, 
        dp_amount: dp,
        status: status, 
        items: cart
    };
    fetch('pos.php?ajax=process_transaction', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
    .then(r => r.json()).then(res => {
        if (res.success) {
            if (status === 'Paid') {
                showSuccessModal(res.transaction_id, data);
            }
            else { 
                currentDraftId = res.transaction_id; 
                showToast('success', 'Simpan', 'Draft disimpan'); 
                const url = new URL(window.location); 
                url.searchParams.set('draft_id', res.transaction_id); 
                window.history.replaceState({}, '', url); 
            }
        } else { showToast('error', 'Gagal', res.error); }
    }).catch(err => showToast('error', 'Error', 'Gagal menghubungi server'));
}

function showSuccessModal(txId, txData) {
    document.getElementById('successCustomer').innerText = txData.customer_name;
    document.getElementById('modalSuccess').classList.remove('hidden');
    
    // Update buttons
    document.getElementById('btnPrintNota').onclick = () => {
        window.open(`invoice.php?id=${txId}`, '_blank');
    };
    
    document.getElementById('btnWA').onclick = () => {
        let phone = activeCustomer && activeCustomer.customer_phone ? activeCustomer.customer_phone : '';
        phone = phone.replace(/[^0-9]/g, '');
        if (phone.startsWith('0')) { phone = '62' + phone.slice(1); }
        else if (phone.startsWith('8')) { phone = '62' + phone; }

        const dateStr = new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
        const invNo = 'INV-' + txId.replace(/-/g, '').substring(0,8).toUpperCase();
        const itemsList = txData.items.map(i => '- *' + i.name + '*\n    ' + i.qty + ' x ' + i.price.toLocaleString('id-ID') + ' = Rp ' + (i.price * i.qty).toLocaleString('id-ID')).join('\n\n');
        
        let dpText = '';
        if (txData.dp_amount > 0) {
            dpText = 'Potongan DP   : Rp ' + txData.dp_amount.toLocaleString('id-ID') + '\n';
        }
        
        let carText = '';
        if (activeCustomer && activeCustomer.car_model) {
            carText = '*KENDARAAN:* ' + activeCustomer.car_model + ' (' + (activeCustomer.license_plate || '-') + ')\n';
        }

        const text = '*INKA OTOSERVICE*\n' +
                     '=============================\n' +
                     '*NO. NOTA :* ' + invNo + '\n' +
                     '*TANGGAL  :* ' + dateStr + '\n' +
                     '*PELANGGAN:* ' + txData.customer_name + '\n' +
                     carText +
                     '-----------------------------\n' +
                     '*RINCIAN TRANSAKSI:*\n\n' +
                     itemsList + '\n' +
                     '-----------------------------\n' +
                     'Subtotal      : Rp ' + txData.total_amount.toLocaleString('id-ID') + '\n' +
                     dpText +
                     '=============================\n' +
                     '*TOTAL BAYAR   : Rp ' + (txData.total_amount - txData.dp_amount).toLocaleString('id-ID') + '*\n' +
                     '*(LUNAS via ' + txData.payment_method + ')*\n' +
                     '=============================\n\n' +
                     'Terima kasih telah mempercayakan perawatan kendaraan Anda kepada kami!\n\n' +
                     '*Download nota disini:*\n' +
                     window.location.origin + window.location.pathname.replace('pos.php', 'invoice.php') + '?id=' + txId;
        
        window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(text), '_blank');
    };
}

function finishTransaction() {
    document.getElementById('modalSuccess').classList.add('hidden');
    cart = []; activeCustomer = null; currentBookingId = ''; currentDraftId = ''; 
    updateUI(); fetchCatalog(); 
    window.history.replaceState({}, "", "pos.php");
}

function showToast(type, t, msg) {
    const toast = document.getElementById('toast'); document.getElementById('toastTitle').innerText = t; document.getElementById('toastMsg').innerText = msg;
    document.getElementById('toastContent').className = `bg-white rounded-2xl shadow-2xl border-2 p-4 flex items-center gap-4 min-w-[320px] ${type === 'success' ? 'border-emerald-100' : 'border-red-100'}`;
    const icon = document.getElementById('toastIcon'); icon.className = `w-10 h-10 rounded-xl flex items-center justify-center ${type === 'success' ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600'}`;
    icon.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-6 h-6"></i>`;
    lucide.createIcons(); toast.style.transform = 'translateY(0)'; toast.style.opacity = '1';
    setTimeout(() => { toast.style.transform = 'translateY(-150%)'; toast.style.opacity = '0'; }, 3000);
}
</script>
</body>
</html>