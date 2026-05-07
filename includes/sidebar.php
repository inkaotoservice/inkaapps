<?php
$nav_groups = [
    'Utama'      => 'Utama',
    'Layanan'    => 'Layanan Servis',
    'Analisis'   => 'Analisis & Data',
    'Keuangan'   => 'Keuangan',
    'Laporan'    => 'Laporan',
    'Manajemen'  => 'Manajemen',
    'Sistem'     => 'Sistem',
];

$filtered_nav = get_filtered_nav();
$role_cfg     = get_role_config();

// Deteksi halaman aktif
$current_script = basename($_SERVER['SCRIPT_NAME']);
$current_path   = $_SERVER['SCRIPT_NAME'];
?>

<!-- Mobile Overlay -->
<div id="sidebar-overlay" onclick="closeSidebar()" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[60] lg:hidden hidden"></div>

<!-- SIDEBAR -->
<aside id="sidebar" class="fixed inset-y-0 left-0 z-[70] w-64 sidebar-glass transition-transform duration-300 -translate-x-full lg:static lg:translate-x-0 shadow-2xl lg:shadow-none border-r border-slate-200 flex flex-col">

    <!-- Logo Header -->
    <div class="h-20 px-6 flex items-center border-b border-slate-100 shrink-0">
        <a href="<?php echo BASE_URL; ?>index.php" class="flex items-center gap-3 group">
            <div class="w-9 h-9 bg-primary rounded-xl flex items-center justify-center text-white shadow-lg shadow-primary/30 group-hover:scale-110 transition-transform duration-300">
                <i data-lucide="wrench" class="w-5 h-5"></i>
            </div>
            <div>
                <span class="text-base font-black tracking-tighter text-slate-900 group-hover:text-primary transition-colors uppercase block leading-tight">Inka</span>
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Otoservice</span>
            </div>
        </a>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto custom-scrollbar px-3 pt-4 pb-24 space-y-6">

        <?php foreach ($nav_groups as $group_key => $group_label): ?>
            <?php
            $group_items = array_filter($filtered_nav, fn($item) => $item['group'] === $group_key);
            if (empty($group_items)) continue;
            ?>
            <div class="space-y-1">
                <!-- Group Label -->
                <div class="px-4 flex items-center gap-3 mb-2">
                    <h3 class="text-[9px] font-black text-slate-400 uppercase tracking-[0.3em] whitespace-nowrap"><?php echo $group_label; ?></h3>
                    <div class="h-[1px] w-full bg-slate-100"></div>
                </div>

                <?php foreach ($group_items as $item):
                    // Cek active state
                    $is_active = (strpos($current_path, str_replace(['index.php', BASE_URL], ['', ''], $item['href'])) !== false)
                                 && $item['href'] !== 'index.php';
                    if ($item['href'] === 'index.php' || $item['href'] === BASE_URL . 'index.php') {
                        $is_active = ($current_script === 'index.php');
                    }
                    $base_href = (strpos($item['href'], 'http') === 0) ? $item['href'] : BASE_URL . $item['href'];
                ?>
                <a href="<?php echo $base_href; ?>"
                   class="flex items-center gap-3 px-4 py-2.5 rounded-xl transition-all duration-200 group <?php echo $is_active ? 'nav-active' : 'text-slate-600 hover:bg-slate-50 hover:text-primary'; ?>">
                    <i data-lucide="<?php echo $item['icon']; ?>"
                       class="w-[18px] h-[18px] shrink-0 transition-all duration-200 <?php echo $is_active ? 'text-white' : 'text-slate-400 group-hover:text-primary'; ?>"></i>
                    <span class="text-sm <?php echo $is_active ? 'font-bold text-white' : 'font-semibold'; ?> tracking-wide truncate">
                        <?php echo $item['name']; ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <!-- Logout -->
        <div class="pt-2 border-t border-slate-100">
            <a href="<?php echo BASE_URL; ?>logout.php"
               class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-500 hover:bg-red-50 hover:text-red-600 transition-all group">
                <i data-lucide="log-out" class="w-[18px] h-[18px] group-hover:-translate-x-1 transition-transform shrink-0"></i>
                <span class="font-bold text-sm">Logout</span>
            </a>
        </div>
    </nav>

    <!-- User Info Footer -->
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-slate-100 bg-white/80 backdrop-blur-sm">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl <?php echo $role_cfg['bg']; ?> flex items-center justify-center shrink-0">
                <i data-lucide="<?php echo $role_cfg['icon']; ?>" class="w-4 h-4 <?php echo $role_cfg['color']; ?>"></i>
            </div>
            <div class="min-w-0">
                <p class="font-black text-slate-900 text-xs truncate"><?php echo $_SESSION['full_name'] ?? 'User'; ?></p>
                <?php if (is_spv_branch()): ?>
                <p class="text-[9px] font-black text-indigo-500 uppercase tracking-widest flex items-center gap-1 mt-0.5">
                    <i data-lucide="map-pin" class="w-2.5 h-2.5"></i>
                    <?php echo htmlspecialchars(get_spv_branch_label()); ?>
                </p>
                <?php else: ?>
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-0.5"><?php echo $role_cfg['label']; ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</aside>
