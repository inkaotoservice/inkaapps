<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// -----------------------------------------------
// AUTH HELPERS
// -----------------------------------------------
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function auth_ready() {
    if (!is_logged_in()) {
        header("Location: " . BASE_URL . "login.php");
        exit();
    }
}

function get_role() {
    return $_SESSION['role'] ?? '';
}

function has_role(array $roles) {
    return in_array(get_role(), $roles);
}

function is_owner()   { return has_role(['owner']); }
function is_manager() { return has_role(['manager_ops']); }
function is_spv()    { return has_role(['spv']); }
function is_admin()  { return has_role(['admin','admin_depok','admin_bsd']); }

// Apakah SPV ini terikat ke cabang tertentu (bukan SPV Pusat)?
function is_spv_branch() {
    return is_spv() && !empty($_SESSION['branch_id']);
}

// Kembalikan branch_id untuk dipakai sebagai filter query.
// - Owner/Manager: null (tidak dibatasi)
// - SPV dengan cabang: branch_id cabangnya
// - Admin cabang: branch_id cabangnya
// - SPV Pusat (branch_id null): null (lihat semua)
function get_branch_filter() {
    $role = get_role();
    if (in_array($role, ['owner', 'manager_ops'])) {
        return null; // tidak dibatasi
    }
    return $_SESSION['branch_id'] ?? null;
}

// Label nama cabang SPV untuk ditampilkan di UI
function get_spv_branch_label() {
    global $pdo;
    $branch_id = $_SESSION['branch_id'] ?? null;
    if (!$branch_id) return 'Pusat / Semua Cabang';
    try {
        $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
        $stmt->execute([$branch_id]);
        $name = $stmt->fetchColumn();
        return $name ?: 'Pusat / Semua Cabang';
    } catch (Exception $e) {
        return 'Pusat / Semua Cabang';
    }
}

// Redirect based on role after login
function redirect_by_role() {
    $role = get_role();
    if (in_array($role, ['admin','admin_depok','admin_bsd'])) {
        header("Location: " . BASE_URL . "pages/pos.php"); exit();
    } else {
        header("Location: " . BASE_URL . "index.php"); exit();
    }
}

// -----------------------------------------------
// FORMAT HELPERS
// -----------------------------------------------
function rupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function short_rupiah($amount) {
    if ($amount >= 1000000000) return 'Rp ' . number_format($amount / 1000000000, 1, ',', '.') . 'M';
    if ($amount >= 1000000)    return 'Rp ' . number_format($amount / 1000000, 1, ',', '.') . 'jt';
    if ($amount >= 1000)       return 'Rp ' . number_format($amount / 1000, 1, ',', '.') . 'rb';
    return rupiah($amount);
}

function uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

function time_ago($datetime) {
    $now  = new DateTime();
    $ago  = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->d > 0) return $diff->d . ' hari lalu';
    if ($diff->h > 0) return $diff->h . ' jam lalu';
    if ($diff->i > 0) return $diff->i . ' menit lalu';
    return 'Baru saja';
}

// -----------------------------------------------
// NAVIGATION (Sama persis dengan DashboardLayout.tsx)
// -----------------------------------------------
function get_navigation() {
    return [
        // Layanan - Admin
        ['name'=>'POS (Kasir)',         'href'=>'pages/pos.php',                  'icon'=>'shopping-cart',    'roles'=>['admin','admin_depok','admin_bsd'],             'group'=>'Layanan'],
        ['name'=>'Pendaftaran Service', 'href'=>'pages/walk-in.php',              'icon'=>'wrench',           'roles'=>['admin','admin_depok','admin_bsd','spv'], 'group'=>'Layanan'],

        ['name'=>'Antrian Service',     'href'=>'pages/antrian.php',              'icon'=>'clipboard-list',   'roles'=>['admin','admin_depok','admin_bsd','spv'], 'group'=>'Layanan'],
        // Analisis
        ['name'=>'Operasional Live',    'href'=>'pages/analytics/operations.php', 'icon'=>'activity',         'roles'=>['owner','manager_ops','spv'],                          'group'=>'Analisis'],
        ['name'=>'Status Inventori',    'href'=>'pages/analytics/inventory.php',  'icon'=>'package',          'roles'=>['owner','manager_ops','spv'],                          'group'=>'Analisis'],
        ['name'=>'Keuangan Cabang',     'href'=>'pages/analytics/finance.php',    'icon'=>'banknote',         'roles'=>['owner','manager_ops'],                          'group'=>'Analisis'],
        ['name'=>'Marketing & Pelanggan','href'=>'pages/analytics/marketing.php', 'icon'=>'users',            'roles'=>['owner','manager_ops'],                          'group'=>'Analisis'],
        // Keuangan
        ['name'=>'Daftar Antrian Refund','href'=>'pages/antrian-refund.php',      'icon'=>'banknote',         'roles'=>['owner','manager_ops','admin','admin_depok','admin_bsd','spv'], 'group'=>'Keuangan'],
        ['name'=>'Laporan Keuangan',    'href'=>'pages/reports.php',              'icon'=>'clipboard-list',   'roles'=>['owner','manager_ops','spv'],                          'group'=>'Keuangan'],
        ['name'=>'Rekap Supplier',      'href'=>'pages/reports/supplier.php',     'icon'=>'package',          'roles'=>['owner','manager_ops','spv','admin','admin_depok','admin_bsd'], 'group'=>'Keuangan'],
        ['name'=>'Pengeluaran',         'href'=>'pages/expenses.php',             'icon'=>'trending-down',    'roles'=>['owner','manager_ops','spv','admin','admin_depok','admin_bsd'], 'group'=>'Keuangan'],
        ['name'=>'Laporan Shift',       'href'=>'pages/shift-report.php',         'icon'=>'clipboard-list',   'roles'=>['admin','admin_depok','admin_bsd','owner','manager_ops','spv'], 'group'=>'Laporan'],
        // Manajemen
        ['name'=>'Katalog',             'href'=>'pages/catalog.php',              'icon'=>'package',          'roles'=>['owner','manager_ops','spv','admin','admin_depok','admin_bsd'], 'group'=>'Manajemen'],
        ['name'=>'Maintenance Alat',    'href'=>'pages/maintenance.php',          'icon'=>'wrench',           'roles'=>['owner','manager_ops','spv'],                          'group'=>'Manajemen'],
        ['name'=>'Karyawan & Admin',    'href'=>'pages/staff.php',                'icon'=>'shield',           'roles'=>['owner','manager_ops','spv'],                                'group'=>'Manajemen'],
        ['name'=>'Organisasi',          'href'=>'pages/branches.php',             'icon'=>'building-2',       'roles'=>['owner','manager_ops'],                                'group'=>'Manajemen'],
        // Sistem
        ['name'=>'Pengaturan Sistem',   'href'=>'pages/settings.php',             'icon'=>'settings',          'roles'=>['owner','manager_ops','admin','admin_depok','admin_bsd'], 'group'=>'Sistem'],
    ];
}

function get_filtered_nav() {
    $role = get_role();
    return array_filter(get_navigation(), fn($item) => in_array($role, $item['roles']));
}

// -----------------------------------------------
// ROLE CONFIG (untuk tampilan badge/icon)
// -----------------------------------------------
function get_role_config($role = null) {
    $role = $role ?? get_role();
    $configs = [
        'owner'      => ['label'=>'Owner',       'icon'=>'crown',      'color'=>'text-purple-600', 'bg'=>'bg-purple-50'],
        'manager_ops'=> ['label'=>'Manager Ops', 'icon'=>'user-cog',   'color'=>'text-indigo-700', 'bg'=>'bg-indigo-50'],
        'admin'      => ['label'=>'Admin',        'icon'=>'shield',     'color'=>'text-blue-600',   'bg'=>'bg-blue-50'],
        'admin_depok'=> ['label'=>'Admin',        'icon'=>'shield',     'color'=>'text-blue-600',   'bg'=>'bg-blue-50'],
        'admin_bsd'  => ['label'=>'Admin',        'icon'=>'shield',     'color'=>'text-blue-600',   'bg'=>'bg-blue-50'],
        'spv'        => ['label'=>'Supervisor',   'icon'=>'user-check', 'color'=>'text-indigo-600', 'bg'=>'bg-indigo-50'],
    ];
    return $configs[$role] ?? ['label'=>'User','icon'=>'user-circle','color'=>'text-slate-600','bg'=>'bg-slate-50'];
}

// -----------------------------------------------
// SETTINGS HELPER
// -----------------------------------------------
function get_setting($key, $default = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT value FROM app_settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $res = $stmt->fetch();
        return $res ? $res['value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// -----------------------------------------------
// FLASH MESSAGES
// -----------------------------------------------
function set_flash_msg($msg, $type = 'success') {
    $_SESSION['flash_msg'] = [
        'msg' => $msg,
        'type' => $type
    ];
}

function get_flash_msg() {
    $flash = $_SESSION['flash_msg'] ?? null;
    unset($_SESSION['flash_msg']);
    return $flash;
}
?>
