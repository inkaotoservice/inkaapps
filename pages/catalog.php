<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
auth_ready();

if (!has_role(['owner','manager_ops','spv','admin','admin_depok','admin_bsd'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Katalog Bengkel';
$user_branch_id = $_SESSION['branch_id'] ?? null;
$role = get_role();
$canManage = in_array($role, ['owner', 'manager_ops', 'spv', 'admin', 'admin_bsd', 'admin_depok']);

// ── PROSES AJAX ACTIONS ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_item') {
        $id          = $_POST['id'] ?? '';
        $name        = trim($_POST['name']);
        $category    = $_POST['category'];
        $price       = (int)str_replace('.', '', $_POST['price']);
        $cost_price  = (int)str_replace('.', '', $_POST['cost_price']);
        $description = trim($_POST['description']);
        $stock       = ($category === 'Spare Part' && $_POST['stock'] !== '') ? (int)$_POST['stock'] : null;
        
        try {
            $existing_branch = null;
            if ($id) {
                $stmt_check = $pdo->prepare("SELECT branch_id FROM catalog WHERE id = ?");
                $stmt_check->execute([$id]);
                $existing_branch = $stmt_check->fetchColumn();
            }

            // Aturan Branch:
            // 1. Jika Owner/Manager/SPV input branch_id di form, gunakan itu.
            // 2. Jika Edit: Jika bukan Owner/Manager/SPV, tetap gunakan branch_id yang sudah ada (menjaga item Global tetap Global).
            // 3. Jika Baru: Gunakan user_branch_id (jika ada).
            
            if (in_array($role, ['owner', 'manager_ops', 'spv'])) {
                $branch_id = isset($_POST['branch_id']) ? ($_POST['branch_id'] === '' ? null : $_POST['branch_id']) : $existing_branch;
            } else {
                // Admin Cabang
                if ($id) {
                    $branch_id = $existing_branch; // Tetap gunakan yang lama saat edit
                } else {
                    $branch_id = $user_branch_id ?: null; // Untuk item baru, otomatis ke cabangnya
                }
            }
            
            if ($id) {
                $stmt = $pdo->prepare("UPDATE catalog SET name=?, category=?, price=?, cost_price=?, description=?, stock=?, branch_id=? WHERE id=?");
                $stmt->execute([$name, $category, $price, $cost_price, $description, $stock, $branch_id, $id]);
                set_flash_msg("Item berhasil diperbarui!");
            } else {
                $stmt = $pdo->prepare("INSERT INTO catalog (id, name, category, price, cost_price, description, stock, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([uuid(), $name, $category, $price, $cost_price, $description, $stock, $branch_id]);
                set_flash_msg("Item baru berhasil ditambahkan!");
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_item') {
        try {
            $stmt = $pdo->prepare("UPDATE catalog SET is_active=0 WHERE id=?");
            $stmt->execute([$_POST['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'update_stock') {
        try {
            $stmt = $pdo->prepare("UPDATE catalog SET stock=? WHERE id=?");
            $stmt->execute([(int)$_POST['stock'], $_POST['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'bulk_update') {
        $type = $_POST['type']; 
        $mode = $_POST['mode']; 
        $value = (float)$_POST['value'];
        
        try {
            $pdo->beginTransaction();
            $sql = "SELECT id, price FROM catalog WHERE is_active = 1";
            $params = [];
            if (!in_array($role, ['owner', 'manager_ops', 'spv']) && $user_branch_id) {
                $sql .= " AND (branch_id = ? OR branch_id IS NULL)";
                $params[] = $user_branch_id;
            }
            $items = $pdo->prepare($sql);
            $items->execute($params);
            
            $stmtUpdate = $pdo->prepare("UPDATE catalog SET price = ? WHERE id = ?");
            foreach ($items->fetchAll() as $item) {
                $current_price = (float)$item['price'];
                $new_price = $current_price;
                if ($type === 'percent') {
                    $factor = $mode === 'increase' ? (1 + $value / 100) : (1 - $value / 100);
                    $new_price = $current_price * $factor;
                    $new_price = round($new_price / 1000) * 1000;
                } else {
                    $new_price = $mode === 'increase' ? $current_price + $value : $current_price - $value;
                }
                $new_price = max(0, $new_price);
                $stmtUpdate->execute([$new_price, $item['id']]);
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// ── GET DATA ────────────────────────────────────────
$sql_cat = "SELECT c.*, b.name as branch_name FROM catalog c LEFT JOIN branches b ON c.branch_id = b.id WHERE c.is_active = 1";
$params_cat = [];
if (!in_array($role, ['owner', 'manager_ops']) && $user_branch_id) {
    $sql_cat .= " AND (c.branch_id = ? OR c.branch_id IS NULL)";
    $params_cat[] = $user_branch_id;
}
$sql_cat .= " ORDER BY c.created_at DESC";
$stmt = $pdo->prepare($sql_cat);
$stmt->execute($params_cat);
$catalog_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$items_json = json_encode($catalog_items);

// List Cabang for Owner Form
$branches = [];
if (in_array($role, ['owner', 'manager_ops', 'spv'])) {
    $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative bg-slate-50">
    <!-- Topbar -->
    <header class="h-16 sm:h-20 flex-shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu"></i>
        </button>

        <div class="flex-1 lg:ml-0 px-4 flex items-center gap-3">
            <div>
                <h1 class="text-sm sm:text-lg font-black text-slate-900 tracking-tight">Katalog Bengkel</h1>
            </div>
        </div>
        
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2 px-3 py-2 rounded-2xl hover:bg-slate-50 transition-all">
                <div class="w-9 h-9 rounded-xl <?php echo get_role_config()['bg']; ?> flex items-center justify-center">
                    <i data-lucide="<?php echo get_role_config()['icon']; ?>" class="w-4 h-4 <?php echo get_role_config()['color']; ?>"></i>
                </div>
            </div>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar relative" id="app">
        <div class="max-w-7xl mx-auto space-y-8 pb-10">

            <!-- Toast / Feedback -->
            <div id="feedback" class="fixed top-24 right-10 z-[200] max-w-sm w-full transition-all duration-300 transform translate-y-[-100%] opacity-0 pointer-events-none">
                <div class="bg-white rounded-2xl shadow-2xl border-l-4 border-emerald-500 p-4 flex gap-3 items-start">
                    <div id="feedbackIcon" class="p-2 bg-emerald-50 text-emerald-500 rounded-full shrink-0">
                        <i data-lucide="check-circle" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h4 id="feedbackTitle" class="font-bold text-slate-900 text-sm">Berhasil!</h4>
                        <p id="feedbackMessage" class="text-xs text-slate-500 mt-1">Operasi selesai.</p>
                    </div>
                </div>
            </div>

            <!-- Header Section -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div>
                    <h2 class="text-2xl sm:text-3xl font-bold text-slate-900">Katalog Bengkel</h2>
                </div>
                <div class="flex flex-wrap gap-2 sm:gap-3">
                    <?php if ($canManage): ?>
                        <button onclick="toggleBulkUpdate()" id="btnBulkUpdate" class="h-12 px-6 bg-amber-500 hover:bg-amber-600 text-white font-bold text-sm rounded-xl transition-all flex items-center gap-2 shadow-lg shadow-amber-500/20 active:scale-95">
                            <i data-lucide="coins" class="w-5 h-5"></i>
                            Update Harga Massal
                        </button>
                        <button onclick="openForm()" class="h-12 px-6 bg-slate-900 hover:bg-slate-800 text-white font-bold text-sm rounded-xl transition-all shadow-lg flex items-center gap-2">
                            <i data-lucide="plus" class="w-5 h-5"></i> Tambah Item
                        </button>
                    <?php endif; ?>
                    <button onclick="window.location.reload()" class="h-12 w-12 bg-white border border-slate-200 text-slate-700 rounded-xl flex items-center justify-center hover:bg-slate-50 transition-colors">
                        <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>

            <!-- Bulk Update Tool -->
            <div id="bulkUpdateCard" class="hidden bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-2xl shadow-amber-500/5 transition-all">
                <div class="p-8 border-b border-slate-100 bg-amber-50/30">
                    <div class="flex items-center gap-4 text-amber-600">
                        <div class="w-12 h-12 bg-amber-100 rounded-2xl flex items-center justify-center">
                            <i data-lucide="trending-up" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-extrabold text-slate-900">Update Harga Massal</h3>
                            <p class="text-xs font-medium text-slate-500 mt-0.5">Ubah harga seluruh item katalog sekaligus secara efisien.</p>
                        </div>
                    </div>
                </div>
                <div class="p-8">
                    <div class="grid grid-cols-1 lg:grid-cols-4 gap-8 items-end">
                        <div class="space-y-3">
                            <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Tipe Perubahan</label>
                            <div class="flex bg-slate-100 p-1.5 rounded-2xl border border-slate-200/50">
                                <button onclick="setBulkType('percent')" id="btnBulkTypePercent" class="flex-1 py-3 rounded-xl text-[11px] font-bold transition-all bg-white text-amber-600 shadow-sm border border-amber-100">PERSEN (%)</button>
                                <button onclick="setBulkType('nominal')" id="btnBulkTypeNominal" class="flex-1 py-3 rounded-xl text-[11px] font-bold transition-all text-slate-500 hover:text-slate-800">NOMINAL (Rp)</button>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Aksi Harga</label>
                            <div class="flex bg-slate-100 p-1.5 rounded-2xl border border-slate-200/50">
                                <button onclick="setBulkMode('increase')" id="btnBulkModeInc" class="flex-1 py-3 rounded-xl text-[11px] font-bold transition-all bg-white text-blue-600 shadow-sm border border-blue-100">NAIK (+)</button>
                                <button onclick="setBulkMode('decrease')" id="btnBulkModeDec" class="flex-1 py-3 rounded-xl text-[11px] font-bold transition-all text-slate-500 hover:text-slate-800">TURUN (-)</button>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Nilai Perubahan</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-sm" id="bulkIndicator">%</span>
                                <input type="number" id="bulkValue" class="w-full pl-12 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-amber-500/10 focus:border-amber-500 outline-none font-bold text-lg text-slate-900 transition-all" placeholder="0">
                            </div>
                        </div>

                        <button onclick="executeBulkUpdate()" id="btnExecuteBulk" class="h-14 bg-slate-900 hover:bg-slate-800 text-white font-bold uppercase tracking-widest rounded-2xl shadow-xl shadow-slate-900/20 flex items-center justify-center gap-2 transition-all active:scale-95">
                            <i data-lucide="zap" class="w-5 h-5 text-amber-400"></i> Eksekusi Massal
                        </button>
                    </div>
                    <div id="bulkInfo" class="mt-6 flex items-start gap-3 p-4 bg-amber-50 rounded-2xl border border-amber-100">
                        <i data-lucide="info" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-xs font-bold text-amber-700 uppercase tracking-tight">Informasi Pembulatan</p>
                            <p class="text-[11px] text-amber-600 mt-1 font-medium">Sistem akan otomatis melakukan pembulatan harga ke ribuan terdekat (Contoh: 15.200 -> 15.000) untuk mempermudah transaksi kasir.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters & Search -->
            <div class="flex flex-col lg:flex-row gap-4 items-center justify-between border-b border-slate-200 pb-6">
                <div class="flex bg-slate-100 p-1.5 rounded-2xl w-full lg:w-fit shadow-inner overflow-x-auto">
                    <button onclick="setTab('All')" id="tabAll" class="px-6 py-2.5 rounded-xl text-sm font-bold transition-all bg-white text-blue-600 shadow-md">Semua</button>
                    <button onclick="setTab('Service')" id="tabService" class="px-6 py-2.5 rounded-xl text-sm font-bold transition-all text-slate-500 hover:text-slate-800">Layanan</button>
                    <button onclick="setTab('Spare Part')" id="tabSpare" class="px-6 py-2.5 rounded-xl text-sm font-bold transition-all text-slate-500 hover:text-slate-800">Suku Cadang</button>
                </div>

                <div class="relative w-full lg:w-96 group">
                    <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-600" size="20"></i>
                    <input type="text" id="searchInput" oninput="renderGrid()" placeholder="Cari layanan atau suku cadang..." class="w-full pl-12 pr-4 py-3.5 bg-white border border-slate-200 rounded-2xl focus:outline-none focus:ring-4 focus:ring-blue-600/5 transition-all shadow-sm">
                </div>
            </div>

            <!-- Grid Items -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8" id="gridContainer">
                <!-- Rendered by JS -->
            </div>
            
            <div id="emptyState" class="hidden py-20 text-center">
                <div class="w-20 h-20 bg-white border border-slate-200 rounded-3xl flex items-center justify-center mx-auto mb-6 text-slate-400">
                    <i data-lucide="package-search" class="w-10 h-10"></i>
                </div>
                <h3 class="text-xl font-black text-slate-900">Katalog Kosong</h3>
                <p class="text-slate-500 mt-2 text-sm">Tidak ada data yang cocok dengan pencarian.</p>
            </div>

        </div>
    </div>
</main>

<!-- ADD / EDIT MODAL -->
<div id="formModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="w-full max-w-2xl bg-white rounded-[2rem] shadow-2xl flex flex-col max-h-[90vh] transform scale-95 opacity-0 transition-all duration-300" id="formModalContent">
        <div class="p-8 border-b border-slate-100 flex items-center justify-between bg-slate-50/50 shrink-0 rounded-t-[2rem]">
            <div>
                <h3 id="modalTitle" class="text-2xl font-bold text-slate-900 uppercase tracking-tight">Tambah Item Baru</h3>
                <p class="text-slate-500 text-sm mt-1">Isi formulir untuk memperbarui data katalog bengkel.</p>
            </div>
            <button type="button" onclick="closeForm()" class="p-3 hover:bg-red-50 text-slate-400 hover:text-red-500 rounded-2xl transition-all">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div class="p-8 overflow-y-auto custom-scrollbar">
            <form id="catalogForm" onsubmit="saveItem(event)" class="space-y-8">
                <input type="hidden" id="itemId">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Cabang (Owner Only) -->
                    <?php if (in_array($role, ['owner', 'manager_ops', 'spv'])): ?>
                    <div class="space-y-3 md:col-span-2">
                        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Penempatan Cabang</label>
                        <select id="itemBranch" class="w-full px-5 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-600/10 focus:border-blue-600 focus:outline-none font-bold text-slate-900 transition-all">
                            <option value="">-- Tersedia Global (Semua Cabang) --</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>">Cabang <?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="space-y-3">
                        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Nama Item / Layanan</label>
                        <div class="relative">
                            <i data-lucide="type" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 w-5 h-5"></i>
                            <input type="text" id="itemName" required class="w-full pl-12 pr-5 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-600/10 focus:border-blue-600 focus:outline-none focus:bg-white transition-all font-bold text-slate-900" placeholder="Masukkan nama item...">
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Kategori Katalog</label>
                        <div class="flex gap-3 p-1.5 bg-slate-100 rounded-2xl border border-slate-200/50">
                            <button type="button" onclick="setFormCat('Service')" id="btnCatService" class="flex-1 py-3 rounded-xl text-xs font-bold transition-all bg-white text-blue-600 shadow-md border border-blue-100 flex items-center justify-center gap-2">
                                <i data-lucide="wrench" class="w-4 h-4"></i> SERVICE
                            </button>
                            <button type="button" onclick="setFormCat('Spare Part')" id="btnCatSpare" class="flex-1 py-3 rounded-xl text-xs font-bold transition-all text-slate-500 hover:text-slate-800 flex items-center justify-center gap-2">
                                <i data-lucide="package" class="w-4 h-4"></i> SPAREPART
                            </button>
                        </div>
                        <input type="hidden" id="itemCategory" value="Service">
                    </div>

                    <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-6 pt-4 border-t border-slate-100">
                        <div class="space-y-3">
                            <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Harga Modal (Rp)</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 font-bold text-sm">Rp</span>
                                <input type="text" id="itemCost" onkeyup="formatCurrency(this); updatePriceFromMargin();" class="w-full pl-12 pr-4 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-600/10 focus:outline-none focus:bg-white transition-all font-bold text-slate-900" placeholder="0">
                            </div>
                        </div>

                        <div class="space-y-3">
                            <label class="text-xs font-bold uppercase tracking-widest text-emerald-500">Margin Laba (%)</label>
                            <div class="relative">
                                <i data-lucide="percent" class="absolute left-4 top-1/2 -translate-y-1/2 text-emerald-400 w-5 h-5"></i>
                                <input type="number" id="itemMargin" onkeyup="updatePriceFromMargin()" class="w-full pl-12 pr-4 py-4 bg-emerald-50 border border-emerald-100 rounded-2xl focus:ring-4 focus:ring-emerald-500/20 focus:outline-none focus:bg-white transition-all font-bold text-emerald-700" placeholder="0">
                            </div>
                        </div>

                        <div class="space-y-3">
                            <label class="text-xs font-bold uppercase tracking-widest text-blue-600">Harga Jual (Rp)</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-blue-400 font-bold text-sm">Rp</span>
                                <input type="text" id="itemPrice" onkeyup="formatCurrency(this); updateMarginFromPrice();" required class="w-full pl-12 pr-4 py-4 bg-blue-50 border border-blue-200 rounded-2xl focus:ring-4 focus:ring-blue-600/20 focus:outline-none focus:bg-white transition-all font-extrabold text-blue-700 text-lg" placeholder="0">
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3" id="stockContainer" style="display: none;">
                        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Stok Inventori</label>
                        <div class="relative">
                            <i data-lucide="boxes" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 w-5 h-5"></i>
                            <input type="number" id="itemStock" class="w-full pl-12 pr-5 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-600/10 focus:outline-none font-bold text-slate-900" placeholder="0">
                        </div>
                    </div>

                    <div class="md:col-span-2 space-y-3">
                        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Keterangan Tambahan</label>
                        <div class="relative">
                            <i data-lucide="file-text" class="absolute left-4 top-5 text-slate-300 w-5 h-5"></i>
                            <textarea id="itemDesc" class="w-full pl-12 pr-5 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-4 focus:ring-blue-600/10 focus:outline-none focus:bg-white transition-all font-medium text-slate-900 min-h-[120px]" placeholder="Masukkan detail tambahan jika ada..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row gap-4 pt-6">
                    <button type="button" onclick="closeForm()" class="flex-1 h-14 bg-red-600 hover:bg-red-700 text-white font-bold uppercase tracking-widest rounded-2xl shadow-xl shadow-red-600/20 transition-all active:scale-95 flex items-center justify-center gap-2">
                        <i data-lucide="x-circle" class="w-5 h-5"></i> Batal
                    </button>
                    <button type="submit" id="btnSubmitForm" class="flex-[2] h-14 bg-slate-900 hover:bg-slate-800 text-white font-bold uppercase tracking-widest rounded-2xl shadow-xl shadow-slate-900/20 flex items-center justify-center gap-3 transition-all active:scale-95">
                        <i data-lucide="save" class="w-5 h-5 text-blue-400"></i> Simpan Katalog
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$items_json_attr = htmlspecialchars($items_json, ENT_QUOTES, 'UTF-8');
$canManageVal = $canManage ? '1' : '0';

$extra_js = <<<JS
<span id="items_json_data" style="display:none;">$items_json_attr</span>
<input type="hidden" id="canManageFlag" value="$canManageVal">

<script>
let items = [];
try {
    const dataEl = document.getElementById('items_json_data');
    if (dataEl) items = JSON.parse(dataEl.textContent);
} catch(e) {
    console.error("JSON Parse Error:", e);
}

let activeTab = 'All';
let searchQuery = '';

// Bulk Update State
let showBulk = false;
let bulkType = 'percent';
let bulkMode = 'increase';

// Inline Stock State
let editingStockId = null;

const canManage = document.getElementById('canManageFlag').value === '1';

function formatRp(n) {
    return parseInt(n || 0).toLocaleString('id-ID');
}

function parseRp(str) {
    return parseInt(str.replace(/\./g, '') || 0);
}

function formatCurrency(input) {
    let val = input.value.replace(/\D/g, '');
    if (val) {
        input.value = parseInt(val).toLocaleString('id-ID');
    } else {
        input.value = '';
    }
}

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

function toggleBulkUpdate() {
    showBulk = !showBulk;
    const card = document.getElementById('bulkUpdateCard');
    const btn = document.getElementById('btnBulkUpdate');
    if (showBulk) {
        card.classList.remove('hidden');
        btn.classList.replace('bg-amber-500', 'bg-amber-700');
        btn.classList.add('ring-4', 'ring-amber-500/20');
    } else {
        card.classList.add('hidden');
        btn.classList.replace('bg-amber-700', 'bg-amber-500');
        btn.classList.remove('ring-4', 'ring-amber-500/20');
    }
}

function setBulkType(t) {
    bulkType = t;
    const btnP = document.getElementById('btnBulkTypePercent');
    const btnN = document.getElementById('btnBulkTypeNominal');
    const ind = document.getElementById('bulkIndicator');
    const info = document.getElementById('bulkInfo');
    
    if (t === 'percent') {
        btnP.className = 'flex-1 py-3 rounded-xl text-[11px] font-bold transition-all bg-white text-amber-600 shadow-sm border border-amber-100';
        btnN.className = 'flex-1 py-3 rounded-xl text-[11px] font-bold transition-all text-slate-500 hover:text-slate-800';
        ind.textContent = '%';
        info.style.display = bulkMode === 'increase' ? 'flex' : 'none';
    } else {
        btnP.className = 'flex-1 py-3 rounded-xl text-[11px] font-bold transition-all text-slate-500 hover:text-slate-800';
        btnN.className = 'flex-1 py-3 rounded-xl text-[11px] font-bold transition-all bg-white text-amber-600 shadow-sm border border-amber-100';
        ind.textContent = 'Rp';
        info.style.display = 'none';
    }
}

function setBulkMode(m) {
    bulkMode = m;
    const btnI = document.getElementById('btnBulkModeInc');
    const btnD = document.getElementById('btnBulkModeDec');
    const info = document.getElementById('bulkInfo');
    
    if (m === 'increase') {
        btnI.className = 'flex-1 py-3 rounded-xl text-[11px] font-bold transition-all bg-white text-blue-600 shadow-sm border border-blue-100';
        btnD.className = 'flex-1 py-3 rounded-xl text-[11px] font-bold transition-all text-slate-500 hover:text-slate-800';
    } else {
        btnI.className = 'flex-1 py-3 rounded-xl text-[11px] font-bold transition-all text-slate-500 hover:text-slate-800';
        btnD.className = 'flex-1 py-3 rounded-xl text-[11px] font-bold transition-all bg-white text-red-600 shadow-sm border border-red-100';
    }
    if(bulkType === 'percent') info.style.display = m === 'increase' ? 'flex' : 'none';
}

function executeBulkUpdate() {
    const val = document.getElementById('bulkValue').value;
    if(!val || val <= 0) return alert('Masukkan nilai valid!');
    if(!confirm(`Yakin ingin mengubah harga SEMUA item?`)) return;
    
    const formData = new FormData();
    formData.append('action', 'bulk_update');
    formData.append('type', bulkType);
    formData.append('mode', bulkMode);
    formData.append('value', val);
    
    document.getElementById('btnExecuteBulk').innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i> Memproses...';
    lucide.createIcons();
    
    fetch('catalog.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            showToast('success', 'Berhasil!', 'Semua harga diperbarui.');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast('error', 'Gagal', data.error);
        }
    });
}

function setTab(tab) {
    activeTab = tab;
    // Map internal names to element IDs
    const idMap = {
        'All': 'tabAll',
        'Service': 'tabService',
        'Spare Part': 'tabSpare'
    };
    
    Object.keys(idMap).forEach(key => {
        const btn = document.getElementById(idMap[key]);
        if(!btn) return;
        if(key === tab) {
            btn.className = 'px-6 py-2.5 rounded-xl text-sm font-bold transition-all bg-white text-blue-600 shadow-md border border-blue-100';
        } else {
            btn.className = 'px-6 py-2.5 rounded-xl text-sm font-bold transition-all text-slate-500 hover:text-slate-800';
        }
    });
    renderGrid();
}

function renderGrid() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const container = document.getElementById('gridContainer');
    const empty = document.getElementById('emptyState');
    
    let html = '';
    let count = 0;
    
    items.forEach(item => {
        if (activeTab !== 'All' && item.category !== activeTab) return;
        if (q && !item.name.toLowerCase().includes(q) && !(item.description || '').toLowerCase().includes(q)) return;
        count++;
        
        const isPart = item.category === 'Spare Part';
        const colorClass = isPart ? 'emerald' : 'blue';
        const icon = isPart ? 'package' : 'wrench';
        const badge = isPart ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-700';
        const stockHtml = isPart ? `
            <div class="px-5 pb-5 pt-0 bg-white rounded-b-3xl">
                \${editingStockId === item.id ? `
                    <div class="flex items-center gap-2">
                        <div class="flex-1 relative">
                            <i data-lucide="boxes" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 w-4 h-4"></i>
                            <input type="number" id="inlineStock_\${item.id}" class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-emerald-200 rounded-xl focus:ring-2 focus:ring-emerald-500/20 focus:outline-none font-bold text-sm" value="\${item.stock || 0}">
                        </div>
                        <button onclick="saveInlineStock('\${item.id}')" class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center hover:bg-emerald-700 transition-all active:scale-90 shadow-sm shadow-emerald-600/20">
                            <i data-lucide="check" class="w-4 h-4"></i>
                        </button>
                        <button onclick="cancelInlineStock()" class="w-10 h-10 rounded-xl bg-white text-slate-400 border border-slate-200 flex items-center justify-center hover:text-red-500 hover:border-red-200 transition-all">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                ` : canManage ? `
                    <button onclick="editInlineStock('\${item.id}')" class="w-full flex items-center justify-between px-4 py-2.5 rounded-xl bg-emerald-50 text-emerald-700 font-bold text-[10px] uppercase tracking-wider border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-200 transition-all active:scale-[0.98]">
                        <span class="flex items-center gap-2"><i data-lucide="boxes" class="w-3.5 h-3.5"></i> Stok Saat Ini</span>
                        <span class="text-xs font-black">\${item.stock || 0}</span>
                    </button>
                ` : `
                    <div class="w-full flex items-center justify-between px-4 py-2.5 rounded-xl bg-slate-50 text-slate-600 font-bold text-[10px] uppercase tracking-wider border border-slate-200">
                        <span class="flex items-center gap-2"><i data-lucide="boxes" class="w-3.5 h-3.5"></i> Stok Saat Ini</span>
                        <span class="text-xs font-black">\${item.stock || 0}</span>
                    </div>
                `}
            </div>
        ` : '';

        const actionBtns = canManage ? `
            <div class="flex gap-1 md:opacity-0 md:group-hover:opacity-100 transition-opacity">
                <button onclick="editItem('\${item.id}')" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-all"><i data-lucide="edit-2" class="w-4 h-4"></i></button>
                <button onclick="deleteItem('\${item.id}')" class="p-1.5 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
            </div>
        ` : '';

        html += `
        <div class="group p-0 flex flex-col h-full border border-slate-100 shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden bg-white rounded-3xl">
            <div class="p-5 flex-1">
                <div class="flex justify-between items-start mb-4">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2.5 rounded-xl shadow-sm bg-\${colorClass}-600 text-white shadow-\${colorClass}-200">
                            <i data-lucide="\${icon}" class="w-4 h-4"></i>
                        </div>
                        <span class="px-2.5 py-1 text-[9px] font-bold uppercase rounded-md \${badge}">\${item.category}</span>
                    </div>
                    \${actionBtns}
                </div>
                <h3 class="text-base font-bold text-slate-900 group-hover:text-blue-600 transition-colors line-clamp-2 leading-snug">\${item.name}</h3>
                <p class="text-[11px] text-slate-500 mt-2 line-clamp-2 min-h-[34px] font-medium leading-relaxed">\${item.description || 'Tidak ada deskripsi.'}</p>
                <div class="mt-5 flex items-end justify-between">
                    <div>
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1">Harga Retail</p>
                        <span class="text-lg font-black text-slate-900 tracking-tight block">Rp \${formatRp(item.price)}</span>
                    </div>
                </div>
            </div>
            \${stockHtml}
        </div>`;
    });

    container.innerHTML = html;
    if(count === 0) {
        empty.classList.remove('hidden');
    } else {
        empty.classList.add('hidden');
    }
    lucide.createIcons();
}

function editInlineStock(id) {
    editingStockId = id;
    renderGrid();
}
function cancelInlineStock() {
    editingStockId = null;
    renderGrid();
}
function saveInlineStock(id) {
    const val = document.getElementById('inlineStock_'+id).value;
    const fd = new FormData();
    fd.append('action', 'update_stock');
    fd.append('id', id);
    fd.append('stock', val);
    fetch('catalog.php', {method: 'POST', body: fd})
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            const it = items.find(i => i.id === id);
            if(it) it.stock = parseInt(val);
            editingStockId = null;
            renderGrid();
            showToast('success', 'Stok Diperbarui', 'Stok berhasil disimpan.');
        }
    });
}

function openForm() {
    document.getElementById('formModal').classList.remove('hidden');
    document.getElementById('formModal').classList.add('flex');
    setTimeout(() => {
        const c = document.getElementById('formModalContent');
        c.classList.remove('scale-95', 'opacity-0');
        c.classList.add('scale-100', 'opacity-100');
    }, 10);
    document.getElementById('catalogForm').reset();
    document.getElementById('itemId').value = '';
    setFormCat('Service');
    document.getElementById('modalTitle').textContent = 'Tambah Item Baru';
}

function closeForm() {
    const c = document.getElementById('formModalContent');
    c.classList.remove('scale-100', 'opacity-100');
    c.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        document.getElementById('formModal').classList.remove('flex');
        document.getElementById('formModal').classList.add('hidden');
    }, 300);
}

function setFormCat(cat) {
    document.getElementById('itemCategory').value = cat;
    const s = document.getElementById('btnCatService');
    const p = document.getElementById('btnCatSpare');
    if(cat === 'Service') {
        s.className = 'flex-1 py-3 rounded-xl text-xs font-bold transition-all bg-white text-blue-600 shadow-md border border-blue-100 flex items-center justify-center gap-2';
        p.className = 'flex-1 py-3 rounded-xl text-xs font-bold transition-all text-slate-500 hover:text-slate-800 flex items-center justify-center gap-2';
        document.getElementById('stockContainer').style.display = 'none';
    } else {
        s.className = 'flex-1 py-3 rounded-xl text-xs font-bold transition-all text-slate-500 hover:text-slate-800 flex items-center justify-center gap-2';
        p.className = 'flex-1 py-3 rounded-xl text-xs font-bold transition-all bg-white text-emerald-600 shadow-md border border-emerald-100 flex items-center justify-center gap-2';
        document.getElementById('stockContainer').style.display = 'block';
    }
}

function updatePriceFromMargin() {
    const cost = parseRp(document.getElementById('itemCost').value);
    const margin = parseInt(document.getElementById('itemMargin').value) || 0;
    if(cost > 0) {
        const p = cost + (cost * margin / 100);
        document.getElementById('itemPrice').value = formatRp(Math.round(p));
    }
}
function updateMarginFromPrice() {
    const cost = parseRp(document.getElementById('itemCost').value);
    const price = parseRp(document.getElementById('itemPrice').value);
    if(cost > 0 && price > 0) {
        const m = Math.round(((price - cost) / cost) * 100);
        document.getElementById('itemMargin').value = m;
    }
}

function editItem(id) {
    const item = items.find(i => i.id === id);
    if(!item) return;
    openForm();
    document.getElementById('modalTitle').textContent = 'Edit Item Katalog';
    document.getElementById('itemId').value = item.id;
    document.getElementById('itemName').value = item.name;
    document.getElementById('itemCost').value = formatRp(item.cost_price);
    document.getElementById('itemPrice').value = formatRp(item.price);
    document.getElementById('itemDesc').value = item.description || '';
    if(document.getElementById('itemBranch')) {
        document.getElementById('itemBranch').value = item.branch_id || '';
    }
    setFormCat(item.category);
    if(item.category === 'Spare Part') {
        document.getElementById('itemStock').value = item.stock || 0;
    }
    updateMarginFromPrice();
}

function deleteItem(id) {
    if(!confirm('Yakin ingin menghapus item ini?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_item');
    fd.append('id', id);
    fetch('catalog.php', {method: 'POST', body: fd})
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            items = items.filter(i => i.id !== id);
            renderGrid();
            showToast('success', 'Berhasil', 'Item dihapus dari katalog.');
        }
    });
}

function saveItem(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitForm');
    btn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i> Menyimpan...';
    lucide.createIcons();
    
    const fd = new FormData();
    fd.append('action', 'save_item');
    fd.append('id', document.getElementById('itemId').value);
    fd.append('name', document.getElementById('itemName').value);
    fd.append('category', document.getElementById('itemCategory').value);
    fd.append('cost_price', document.getElementById('itemCost').value);
    fd.append('price', document.getElementById('itemPrice').value);
    fd.append('description', document.getElementById('itemDesc').value);
    fd.append('stock', document.getElementById('itemStock').value);
    if(document.getElementById('itemBranch')) {
        fd.append('branch_id', document.getElementById('itemBranch').value);
    }
    
    fetch('catalog.php', {method: 'POST', body: fd})
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            window.location.reload();
        } else {
            showToast('error', 'Gagal', res.error);
            btn.innerHTML = '<i data-lucide="check-circle-2" class="w-5 h-5"></i> Simpan ke Katalog';
            lucide.createIcons();
        }
    })
    .catch(err => {
        console.error(err);
        showToast('error', 'Sistem Error', 'Gagal menghubungi server.');
        btn.innerHTML = '<i data-lucide="check-circle-2" class="w-5 h-5"></i> Simpan ke Katalog';
        lucide.createIcons();
    });
}

renderGrid();
</script>
JS;
?>
<?php include '../includes/footer.php'; ?>
