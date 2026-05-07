<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
auth_ready();

// Role check
if (!has_role(['owner', 'manager_ops', 'spv', 'admin', 'admin_depok', 'admin_bsd'])) {
    header("Location: " . BASE_URL . "index.php"); exit();
}

$page_title = 'Rekap Pengambilan Supplier';
$role = get_role();
$msg = '';
$msg_type = '';

$user_branch_id = $_SESSION['branch_id'] ?? null;

// Nomor WA Owner (dari app_settings)
$stmt_wa = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = 'owner_wa_number'");
$stmt_wa->execute();
$owner_wa = $stmt_wa->fetchColumn() ?: '6281234567890';

// ── PROSES POST (ADD & DELETE) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_restock') {
        $branch_id     = $_POST['branch_id'] ?? $user_branch_id;
        $supplier_name = trim($_POST['supplier_name']);
        $total_amount  = (int)str_replace('.', '', $_POST['total_amount']);
        $expense_date  = $_POST['expense_date'];
        $items_json    = $_POST['items_json'] ?? '[]';
        
        $items = json_decode($items_json, true);
        if (!empty($items) && $branch_id) {
            try {
                $pdo->beginTransaction();
                
                $desc_items = [];
                foreach ($items as $it) {
                    $desc_items[] = [
                        'catalog_id'    => $it['catalog_id'],
                        'name'          => $it['name'],
                        'qty'           => (int)$it['qty'],
                        'cost'          => (int)$it['cost'],
                        'supplier_name' => $supplier_name
                    ];
                    
                    // Update catalog (tambah stok & update harga modal)
                    if (!empty($it['catalog_id'])) {
                        $stmt_cat = $pdo->prepare("UPDATE catalog SET stock = IFNULL(stock,0) + ?, cost_price = ?, updated_at = NOW() WHERE id = ?");
                        $stmt_cat->execute([(int)$it['qty'], (int)$it['cost'], $it['catalog_id']]);
                    }
                }
                
                $json_desc = "STRUCT_JSON:" . json_encode($desc_items);
                $exp_id = uuid();
                $stmt_exp = $pdo->prepare("INSERT INTO expenses (id, branch_id, category, amount, description, expense_date, created_by) VALUES (?, ?, 'Stok', ?, ?, ?, ?)");
                $stmt_exp->execute([$exp_id, $branch_id, $total_amount, $json_desc, $expense_date, $_SESSION['user_id']]);
                
                $pdo->commit();

                // Build WA Text
                $stmt_br = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                $stmt_br->execute([$branch_id]);
                $br_name = $stmt_br->fetchColumn() ?: 'General';
                $tgl = date('d F Y', strtotime($expense_date));
                
                $wa_list = "";
                foreach ($desc_items as $d) {
                    $wa_list .= "- {$d['name']} ({$d['qty']}x) @ Rp " . number_format($d['cost'],0,',','.') . "%0A";
                }
                
                $wa_text = "*LAPORAN NOTA SUPPLIER BARU - {$br_name}*%0A%0A" .
                           "Berikut adalah rincian barang yang baru saja diambil:%0A%0A" .
                           "*Tanggal:* {$tgl}%0A" .
                           "*Kategori:* Pembelian Stok/Sparepart%0A" .
                           "*Toko Supplier:* {$supplier_name}%0A" .
                           "*Total Tagihan:* Rp " . number_format($total_amount,0,',','.') . "%0A%0A" .
                           "*Rincian Barang:*%0A{$wa_list}%0A" .
                           "_Silakan lampirkan foto nota fisik sebagai bukti._";
                
                $_SESSION['wa_link'] = "https://wa.me/{$owner_wa}?text={$wa_text}";
                
                header("Location: supplier.php?success=1");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "Gagal menyimpan data: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    } elseif ($_POST['action'] === 'edit_item') {
        $exp_id      = $_POST['expense_id'];
        $item_idx    = (int)$_POST['item_index'];
        $new_name    = trim($_POST['item_name']);
        $new_supplier = trim($_POST['supplier_name']);
        $new_qty     = (int)$_POST['qty'];
        $new_cost    = (int)str_replace('.', '', $_POST['cost']);
        
        try {
            $stmt = $pdo->prepare("SELECT description FROM expenses WHERE id = ?");
            $stmt->execute([$exp_id]);
            $desc = $stmt->fetchColumn();
            
            if ($desc && strpos($desc, 'STRUCT_JSON:') === 0) {
                $json_str = substr($desc, 12);
                $items = json_decode($json_str, true);
                
                if (isset($items[$item_idx])) {
                    // Update item data
                    $items[$item_idx]['name'] = $new_name;
                    $items[$item_idx]['supplier_name'] = $new_supplier;
                    $items[$item_idx]['qty'] = $new_qty;
                    $items[$item_idx]['cost'] = $new_cost;
                    
                    // Recalculate total expense amount
                    $new_total = 0;
                    foreach ($items as $it) {
                        $new_total += ((int)$it['qty'] * (int)$it['cost']);
                    }
                    
                    $new_desc = "STRUCT_JSON:" . json_encode($items);
                    $pdo->prepare("UPDATE expenses SET description = ?, amount = ? WHERE id = ?")->execute([$new_desc, $new_total, $exp_id]);
                    
                    header("Location: supplier.php?success_edit=1");
                    exit();
                }
            }
        } catch (Exception $e) {
            $msg = "Gagal mengedit: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// ── GET DATA FILTER & DAFTAR ──────────────────────────────────
$filter_branch = $_GET['branch_id'] ?? 'all';
$search_term = $_GET['search'] ?? '';

$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();
$catalog_items = $pdo->query("SELECT id, name, cost_price, stock, branch_id FROM catalog WHERE is_active = 1")->fetchAll();

$sql_exp = "SELECT e.*, b.name as branch_name 
            FROM expenses e 
            LEFT JOIN branches b ON e.branch_id = b.id 
            WHERE e.category = 'Stok'";
$params_exp = [];

if (!has_role(['owner', 'spv']) && $user_branch_id) {
    $sql_exp .= " AND e.branch_id = ?";
    $params_exp[] = $user_branch_id;
}

$sql_exp .= " ORDER BY e.expense_date DESC, e.created_at DESC LIMIT 500";
$stmt_exp = $pdo->prepare($sql_exp);
$stmt_exp->execute($params_exp);
$raw_expenses = $stmt_exp->fetchAll();

$flattened_data = [];
$total_investment = 0;
$total_items = 0;

foreach ($raw_expenses as $exp) {
    $b_name = $exp['branch_name'] ?: 'General';
    
    if (strpos($exp['description'], 'STRUCT_JSON:') === 0) {
        $items = json_decode(substr($exp['description'], 12), true);
        if (is_array($items)) {
            foreach ($items as $idx => $it) {
                $flattened_data[] = [
                    'id' => $exp['id'],
                    'item_index' => $idx,
                    'date' => $exp['expense_date'],
                    'branch_id' => $exp['branch_id'],
                    'branch_name' => $b_name,
                    'supplier_name' => $it['supplier_name'] ?? '-',
                    'name' => $it['name'],
                    'qty' => $it['qty'],
                    'cost' => $it['cost'],
                    'total_cost' => $it['qty'] * $it['cost']
                ];
            }
        }
    } else {
        $isNum = is_numeric($exp['description']);
        $flattened_data[] = [
            'id' => $exp['id'],
            'item_index' => 0,
            'date' => $exp['expense_date'],
            'branch_id' => $exp['branch_id'],
            'branch_name' => $b_name,
            'supplier_name' => '-',
            'name' => $isNum ? 'Item Pembelian (Legacy)' : ($exp['description'] ?: 'Tanpa Keterangan'),
            'qty' => 1,
            'cost' => $exp['amount'],
            'total_cost' => $exp['amount']
        ];
    }
}

// Apply Filters
$filtered_data = [];
foreach ($flattened_data as $item) {
    $match_search = empty($search_term) || stripos($item['name'], $search_term) !== false || stripos($item['supplier_name'], $search_term) !== false;
    $match_branch = ($filter_branch === 'all') || ($item['branch_id'] === $filter_branch);
    
    if ($match_search && $match_branch) {
        $filtered_data[] = $item;
        $total_investment += $item['total_cost'];
        $total_items += $item['qty'];
    }
}

?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<main class="flex-1 flex flex-col min-w-0 overflow-hidden relative bg-slate-50">
    
    <!-- Topbar -->
    <header class="h-16 sm:h-20 flex-shrink-0 flex items-center justify-between px-4 sm:px-6 lg:px-10 border-b border-slate-200 bg-white z-30">
        <button onclick="openSidebar()" class="p-2 lg:hidden text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
            <i data-lucide="menu"></i>
        </button>

        <div class="flex-1 lg:ml-0 px-4 flex items-center gap-3">
            <div class="p-2 bg-blue-600 text-white rounded-xl shadow-lg shadow-blue-200 hidden sm:flex">
                <i data-lucide="package" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="text-sm sm:text-lg font-black text-slate-900 tracking-tight">Rekap Pengambilan Supplier</h1>
                <p class="text-[10px] sm:text-xs text-slate-500 font-medium">Laporan detail harga modal dan inventory logs.</p>
            </div>
        </div>
    </header>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 custom-scrollbar">
        
        <?php if (isset($_GET['success'])): ?>
        <div class="mb-6 p-4 rounded-2xl bg-emerald-50 text-emerald-700 border border-emerald-100 flex items-center justify-between font-semibold text-sm">
            <div class="flex items-center gap-3">
                <i data-lucide="check-circle" class="w-5 h-5"></i> Pengambilan barang berhasil dicatat!
            </div>
            <?php if (isset($_SESSION['wa_link'])): ?>
            <a href="<?php echo $_SESSION['wa_link']; ?>" target="_blank" class="bg-emerald-600 text-white px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest hover:bg-emerald-700 transition-colors flex items-center gap-2">
                <i data-lucide="send" class="w-3 h-3"></i> Kirim Laporan WA
            </a>
            <?php unset($_SESSION['wa_link']); endif; ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
        <div class="mb-6 p-4 rounded-2xl bg-slate-100 text-slate-700 border border-slate-200 flex items-center gap-3 font-semibold text-sm">
            <i data-lucide="info" class="w-5 h-5"></i> Data berhasil dihapus.
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['success_edit'])): ?>
        <div class="mb-6 p-4 rounded-2xl bg-blue-50 text-blue-700 border border-blue-100 flex items-center gap-3 font-semibold text-sm">
            <i data-lucide="check-circle" class="w-5 h-5"></i> Data berhasil diperbarui!
        </div>
        <?php endif; ?>

        <?php if ($msg): ?>
        <div class="mb-6 p-4 rounded-2xl <?php echo $msg_type === 'error' ? 'bg-red-50 text-red-700 border-red-100' : 'bg-blue-50 text-blue-700 border-blue-100'; ?> border flex items-center gap-3 font-semibold text-sm">
            <i data-lucide="alert-circle" class="w-5 h-5"></i> <?php echo $msg; ?>
        </div>
        <?php endif; ?>

        <!-- Toolbar & Stats -->
        <div class="max-w-7xl mx-auto mb-8 flex flex-col lg:flex-row gap-6 items-stretch">
            
            <!-- Quick Stats -->
            <div class="flex-1 p-6 rounded-3xl bg-gradient-to-br from-slate-900 to-slate-800 text-white relative overflow-hidden group shadow-xl">
                <div class="absolute -right-4 -top-4 w-32 h-32 bg-white/5 rounded-full blur-3xl group-hover:bg-white/10 transition-all"></div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-3">Total Investasi Stok</p>
                <div className="flex items-baseline gap-2">
                    <span class="text-3xl font-black italic tracking-tighter"><?php echo rupiah($total_investment); ?></span>
                </div>
                <div class="mt-6 flex items-center gap-2 text-emerald-400">
                    <div class="p-1 bg-emerald-500/20 rounded-lg"><i data-lucide="dollar-sign" class="w-3.5 h-3.5"></i></div>
                    <span class="text-[10px] font-black uppercase tracking-widest"><?php echo number_format($total_items); ?> Items Terdata</span>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex-1 p-6 rounded-3xl bg-white border border-slate-100 shadow-lg flex flex-col justify-center">
                <form action="" method="GET" class="space-y-4">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-2">Filter & Cari</p>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300 w-4 h-4"></i>
                        <input type="text" name="search" placeholder="Cari item atau supplier..." value="<?php echo htmlspecialchars($search_term); ?>"
                               class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-100 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-blue-500/20">
                    </div>
                    <?php if (has_role(['owner', 'spv'])): ?>
                    <div class="flex gap-3">
                        <select name="branch_id" class="flex-1 px-4 py-3 bg-slate-50 border border-slate-100 rounded-xl text-sm font-bold outline-none focus:ring-2 focus:ring-blue-500/20">
                            <option value="all">Semua Cabang</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch === $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="bg-blue-50 text-blue-600 px-6 py-3 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-blue-100 transition-colors">Filter</button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Actions -->
            <div class="flex flex-col justify-center gap-3">
                <button onclick="document.getElementById('modalAdd').classList.remove('hidden')" 
                        class="flex items-center justify-center gap-2 bg-emerald-600 text-white px-6 py-3.5 rounded-2xl font-black text-xs uppercase tracking-widest hover:bg-emerald-700 transition-all active:scale-95 shadow-lg shadow-emerald-200">
                    <i data-lucide="package-plus" class="w-4 h-4"></i> Input Pengambilan
                </button>
                <button onclick="exportExcel()" class="flex items-center justify-center gap-2 bg-slate-900 text-white px-6 py-3.5 rounded-2xl font-black text-xs uppercase tracking-widest hover:bg-slate-800 transition-all active:scale-95 shadow-lg shadow-slate-200">
                    <i data-lucide="download" class="w-4 h-4"></i> Export Excel
                </button>
            </div>
        </div>

        <!-- Data Table -->
        <div class="max-w-7xl mx-auto bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left" id="supplierTable">
                    <thead class="bg-slate-50 border-b border-slate-100">
                        <tr>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tanggal & Cabang</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Supplier</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Nama Barang (Qty)</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Total Modal</th>
                            <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($filtered_data as $i => $item): ?>
                        <tr class="hover:bg-slate-50/80 transition-colors group">
                            <td class="px-8 py-5">
                                <p class="font-bold text-slate-900 text-sm"><?php echo date('d M Y', strtotime($item['date'])); ?></p>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-0.5"><i data-lucide="map-pin" class="w-3 h-3 inline"></i> <?php echo htmlspecialchars($item['branch_name']); ?></p>
                            </td>
                            <td class="px-8 py-5">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-black bg-blue-50 text-blue-700 border border-blue-100">
                                    <?php echo htmlspecialchars($item['supplier_name']); ?>
                                </span>
                            </td>
                            <td class="px-8 py-5">
                                <p class="font-bold text-slate-900 text-sm flex items-center gap-2">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                    <span class="bg-slate-900 text-white text-[10px] px-2 py-0.5 rounded-full"><?php echo $item['qty']; ?>x</span>
                                </p>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-0.5">@ <?php echo rupiah($item['cost']); ?></p>
                            </td>
                            <td class="px-8 py-5 text-right">
                                <p class="font-black text-slate-900 text-sm"><?php echo rupiah($item['total_cost']); ?></p>
                            </td>
                            <td class="px-8 py-5 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button onclick='openEditModal(<?php echo json_encode($item); ?>)' class="p-2 bg-blue-50 text-blue-500 hover:bg-blue-500 hover:text-white rounded-xl transition-all">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>
                                    <form action="" method="POST" onsubmit="return confirm('Hapus data barang ini dari Nota Pengambilan?');" class="inline">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="expense_id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="item_index" value="<?php echo $item['item_index']; ?>">
                                        <button type="submit" class="p-2 bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white rounded-xl transition-all">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($filtered_data)): ?>
                        <tr>
                            <td colspan="5" class="px-8 py-20 text-center">
                                <i data-lucide="alert-circle" class="w-12 h-12 text-slate-200 mx-auto mb-4"></i>
                                <p class="text-slate-400 font-bold">Data pengambilan supplier tidak ditemukan.</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

<!-- MODAL ADD PENGAMBILAN -->
<div id="modalAdd" class="fixed inset-0 z-[100] flex items-center justify-center p-4 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="document.getElementById('modalAdd').classList.add('hidden')"></div>
    <div class="relative w-full max-w-2xl max-h-[90vh] bg-white rounded-[2rem] shadow-2xl flex flex-col overflow-hidden z-[101]">
        
        <div class="bg-gradient-to-r from-emerald-600 to-emerald-500 p-6 shrink-0">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-black text-white tracking-tight flex items-center gap-3">
                        <div class="p-2 bg-white/20 rounded-xl backdrop-blur-sm"><i data-lucide="package" class="w-5 h-5 text-white"></i></div>
                        Input Pengambilan Barang
                    </h3>
                    <p class="text-emerald-50 text-xs font-medium mt-1 ml-[44px]">Data akan tercatat sebagai stok masuk & penagihan.</p>
                </div>
                <button onclick="document.getElementById('modalAdd').classList.add('hidden')" class="p-3 text-emerald-100 hover:text-white hover:bg-white/20 rounded-2xl transition-all">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
        </div>

        <div class="overflow-y-auto flex-1 p-6 custom-scrollbar">
            <form id="expenseForm" action="" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="add_restock">
                <input type="hidden" name="items_json" id="itemsJsonInput" value="[]">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Kategori</label>
                        <input type="text" value="Pembelian Stok/Sparepart" disabled class="w-full bg-slate-100 border border-slate-200 rounded-2xl py-3 px-4 text-sm font-bold text-slate-500">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Tanggal</label>
                        <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full bg-white border border-slate-200 rounded-2xl py-3 px-4 text-sm font-bold text-slate-900 focus:outline-none focus:border-emerald-500">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Cabang Tujuan Stok</label>
                    <?php if (has_role(['owner', 'spv'])): ?>
                    <select name="branch_id" id="formBranchId" required class="w-full bg-slate-50 border border-slate-200 rounded-2xl py-3 px-4 text-sm font-bold text-slate-900 focus:outline-none focus:border-emerald-500">
                        <option value="">-- Pilih Cabang --</option>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="hidden" name="branch_id" id="formBranchId" value="<?php echo $user_branch_id; ?>">
                    <input type="text" value="<?php 
                        $bn = 'Cabang Anda';
                        foreach($branches as $b) { if($b['id'] == $user_branch_id) $bn = $b['name']; }
                        echo $bn; 
                    ?>" disabled class="w-full bg-slate-100 border border-slate-200 rounded-2xl py-3 px-4 text-sm font-bold text-slate-500">
                    <?php endif; ?>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Nama Toko Supplier</label>
                    <input type="text" name="supplier_name" placeholder="Contoh: Toko Sparepart Makmur" required class="w-full bg-white border border-slate-200 rounded-2xl py-3 px-4 text-sm font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all">
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Total Tagihan (Rp)</label>
                    <input type="text" name="total_amount" id="totalAmountInput" placeholder="0" required class="w-full bg-emerald-50 border border-emerald-200 rounded-2xl py-3 px-4 text-xl font-black text-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all" oninput="formatRupiahInput(this)">
                </div>

                <div class="pt-4 border-t border-slate-100">
                    <div class="flex items-center justify-between mb-4">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Daftar Barang & Harga</label>
                        <button type="button" onclick="addRow()" class="text-[10px] font-black text-emerald-600 uppercase hover:bg-emerald-50 px-3 py-1.5 rounded-lg transition-all border border-emerald-100">+ Tambah Baris</button>
                    </div>
                    
                    <div id="itemsContainer" class="space-y-3">
                        <!-- Rows will be added here by JS -->
                    </div>
                </div>

            </form>
        </div>

        <div class="p-5 bg-slate-50/80 border-t border-slate-100 shrink-0 flex gap-4">
            <button type="button" onclick="document.getElementById('modalAdd').classList.add('hidden')" class="flex-1 py-3 px-5 rounded-xl bg-white text-slate-500 font-black text-[11px] uppercase tracking-widest hover:bg-slate-100 border border-slate-200">Batal</button>
            <button type="button" onclick="submitForm()" class="flex-[2] py-3 px-5 rounded-xl bg-emerald-600 text-white font-black text-[11px] uppercase tracking-widest hover:bg-emerald-700 shadow-md shadow-emerald-500/30">Simpan & Kirim WA</button>
        </div>

    </div>
</div>

<!-- MODAL EDIT PENGAMBILAN -->
<div id="modalEdit" class="fixed inset-0 z-[100] flex items-center justify-center p-4 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="document.getElementById('modalEdit').classList.add('hidden')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-[2rem] shadow-2xl flex flex-col overflow-hidden z-[101]">
        
        <div class="bg-gradient-to-r from-blue-600 to-blue-500 p-6 shrink-0">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-black text-white tracking-tight flex items-center gap-3">
                        <div class="p-2 bg-white/20 rounded-xl backdrop-blur-sm"><i data-lucide="edit-3" class="w-5 h-5 text-white"></i></div>
                        Edit Data Pengambilan
                    </h3>
                    <p class="text-blue-50 text-xs font-medium mt-1 ml-[44px]">Perubahan hanya pada catatan modal, tidak merubah stok.</p>
                </div>
                <button onclick="document.getElementById('modalEdit').classList.add('hidden')" class="p-3 text-blue-100 hover:text-white hover:bg-white/20 rounded-2xl transition-all">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
        </div>

        <div class="p-6">
            <form id="editForm" action="" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="expense_id" id="editExpId">
                <input type="hidden" name="item_index" id="editItemIdx">
                
                <div class="space-y-1">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Supplier</label>
                    <input type="text" name="supplier_name" id="editSupplier" required class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-4 text-sm font-bold text-slate-900 focus:outline-none focus:border-blue-500">
                </div>

                <div class="space-y-1">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Nama Barang</label>
                    <input type="text" name="item_name" id="editItemName" required class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-4 text-sm font-bold text-slate-900 focus:outline-none focus:border-blue-500">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Qty</label>
                        <input type="number" name="qty" id="editQty" required min="1" class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-4 text-sm font-bold text-slate-900 focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Harga Modal Satuan (Rp)</label>
                        <input type="text" name="cost" id="editCost" required class="w-full bg-slate-50 border border-slate-200 rounded-xl py-2.5 px-4 text-sm font-bold text-slate-900 focus:outline-none focus:border-blue-500" oninput="formatRupiahInput(this)">
                    </div>
                </div>

                <div class="pt-4 flex gap-3">
                    <button type="button" onclick="document.getElementById('modalEdit').classList.add('hidden')" class="flex-1 py-3 px-5 rounded-xl bg-slate-100 text-slate-500 font-black text-[11px] uppercase tracking-widest hover:bg-slate-200 transition-all">Batal</button>
                    <button type="submit" class="flex-[2] py-3 px-5 rounded-xl bg-blue-600 text-white font-black text-[11px] uppercase tracking-widest hover:bg-blue-700 shadow-lg shadow-blue-200 transition-all">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const catalogData = <?php echo json_encode($catalog_items); ?>;
    let items = [{ id: Date.now(), catalog_id: '', name: '', qty: 1, cost: 0 }];

    function renderRows() {
        const container = document.getElementById('itemsContainer');
        container.innerHTML = '';
        const currentBranch = document.getElementById('formBranchId').value;

        items.forEach((item, index) => {
            // Filter catalog by selected branch or general
            const filteredCatalog = catalogData.filter(c => 
                (c.branch_id === currentBranch || !c.branch_id) && 
                c.name.toLowerCase().includes(item.name.toLowerCase())
            );

            const row = document.createElement('div');
            row.className = 'p-4 bg-slate-50 rounded-2xl border border-slate-100 space-y-3 relative group';
            row.innerHTML = `
                <div class="grid grid-cols-12 gap-3">
                    <div class="col-span-8 relative">
                        <input type="text" placeholder="Cari & Pilih Barang dari Katalog..." 
                               class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-emerald-500/20"
                               value="${item.name}" oninput="updateItem(${index}, 'name', this.value); updateItem(${index}, 'catalog_id', '');">
                        
                        ${item.name && !item.catalog_id ? `
                        <div class="absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-xl shadow-xl z-50 max-h-40 overflow-y-auto custom-scrollbar">
                            ${filteredCatalog.length > 0 ? filteredCatalog.map(c => `
                                <div class="px-4 py-2 text-xs font-bold hover:bg-emerald-50 cursor-pointer border-b border-slate-50 flex justify-between"
                                     onclick="selectCatalog(${index}, '${c.id}', '${c.name.replace(/'/g, "\\'")}', ${c.cost_price || 0})">
                                    <span>${c.name}</span>
                                    <span class="text-[9px] text-slate-400">Rp ${(c.cost_price||0).toLocaleString('id-ID')}</span>
                                </div>
                            `).join('') : '<div class="px-4 py-2 text-xs text-rose-500 italic">Tidak ditemukan di katalog.</div>'}
                        </div>
                        ` : ''}

                        ${item.catalog_id ? `
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 text-emerald-500">
                            <i data-lucide="check-circle-2" class="w-4 h-4"></i>
                        </div>
                        ` : ''}
                    </div>
                    <div class="col-span-4">
                        <input type="number" placeholder="Qty" min="1" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold"
                               value="${item.qty}" oninput="updateItem(${index}, 'qty', this.value)">
                    </div>
                    <div class="col-span-12">
                        <label class="text-[9px] font-black text-slate-400 uppercase ml-1">Harga Modal Satuan</label>
                        <input type="text" placeholder="Harga Modal" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold"
                               value="${formatRp(item.cost)}" oninput="updateItemCost(${index}, this.value)">
                    </div>
                </div>
                ${items.length > 1 ? `
                <button type="button" onclick="removeRow(${index})" class="absolute -top-2 -right-2 w-6 h-6 bg-rose-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all shadow-lg text-xs font-bold">X</button>
                ` : ''}
            `;
            container.appendChild(row);
        });
        lucide.createIcons();
    }

    function addRow() {
        items.push({ id: Date.now(), catalog_id: '', name: '', qty: 1, cost: 0 });
        renderRows();
    }

    function removeRow(index) {
        items.splice(index, 1);
        renderRows();
    }

    function updateItem(index, field, value) {
        items[index][field] = value;
        renderRows();
    }

    function selectCatalog(index, id, name, cost) {
        items[index].catalog_id = id;
        items[index].name = name;
        items[index].cost = cost;
        renderRows();
    }

    function updateItemCost(index, value) {
        const num = value.replace(/\D/g, "");
        items[index].cost = num ? parseInt(num) : 0;
        renderRows();
    }

    function formatRp(num) {
        if (!num && num !== 0) return "";
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }

    function formatRupiahInput(el) {
        let val = el.value.replace(/\D/g, "");
        el.value = formatRp(val);
    }

    document.getElementById('formBranchId').addEventListener('change', () => {
        renderRows(); // re-render to update catalog suggestions based on branch
    });

    function submitForm() {
        const invalid = items.some(i => !i.catalog_id);
        if (invalid) {
            alert('Semua baris barang harus dipilih dari Katalog!');
            return;
        }
        document.getElementById('itemsJsonInput').value = JSON.stringify(items);
        document.getElementById('expenseForm').submit();
    }

    function exportExcel() {
        const rows = [];
        rows.push(["Tanggal", "Cabang", "Nama Supplier", "Nama Barang", "Qty", "Harga Modal", "Total Harga"]);
        
        const tableRows = document.querySelectorAll('#supplierTable tbody tr');
        tableRows.forEach(tr => {
            if (tr.children.length === 5) {
                const dateBranch = tr.children[0].innerText.replace(/\n/g, ' - ');
                const supplier = tr.children[1].innerText;
                const nameQty = tr.children[2].innerText.replace(/\n/g, ' ');
                const cost = tr.children[3].innerText;
                rows.push([`"${dateBranch}"`, `"${supplier}"`, `"${nameQty}"`, `"${cost}"`]);
            }
        });
        
        let csvContent = "data:text/csv;charset=utf-8," + rows.map(e => e.join(",")).join("\n");
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "Riwayat_Pengambilan_Barang.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function openEditModal(item) {
        document.getElementById('editExpId').value = item.id;
        document.getElementById('editItemIdx').value = item.item_index;
        document.getElementById('editSupplier').value = item.supplier_name;
        document.getElementById('editItemName').value = item.name;
        document.getElementById('editQty').value = item.qty;
        document.getElementById('editCost').value = formatRp(item.cost);
        
        document.getElementById('modalEdit').classList.remove('hidden');
        lucide.createIcons();
    }

    // Initial render
    renderRows();
</script>

<?php include '../../includes/footer.php'; ?>
