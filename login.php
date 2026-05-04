<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Jika sudah login, redirect langsung
if (is_logged_in()) { redirect_by_role(); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.password, p.full_name, p.role, p.branch_id, p.total_points
            FROM users u
            JOIN profiles p ON u.id = p.id
            WHERE u.email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['full_name']  = $user['full_name'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['branch_id']  = $user['branch_id'];
            $_SESSION['total_points'] = $user['total_points'];
            redirect_by_role();
        } else {
            $error = 'Email atau password salah. Silakan coba lagi.';
        }
    } else {
        $error = 'Email dan password wajib diisi.';
    }
}
// Get App Settings for Logo
$stmt_logo = $pdo->query("SELECT value FROM app_settings WHERE `key` = 'receipt_logo_url'");
$app_logo = $stmt_logo->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — INKA OTOSERVICE</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%232563eb'/><path d='M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z' fill='white'/></svg>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        .glass { background: rgba(255,255,255,0.92); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative overflow-y-auto bg-slate-50">

    <div class="w-full max-w-md relative z-10 my-8">

        <!-- Card -->
        <div class="glass rounded-[2.5rem] shadow-2xl border border-white/20 overflow-hidden">

            <!-- Header Card -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-700 p-8 text-center relative overflow-hidden">
                <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle at 50% 100%, #fff 0%, transparent 60%)"></div>
                <div class="relative z-10">
                    <div class="w-20 h-20 bg-white/20 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-white/30 shadow-inner overflow-hidden">
                        <?php if ($app_logo): ?>
                            <img src="<?php echo htmlspecialchars($app_logo); ?>" class="max-w-full max-h-full object-contain">
                        <?php else: ?>
                            <i data-lucide="wrench" class="w-8 h-8 text-white"></i>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-2xl font-black text-white tracking-tighter uppercase">Inka Otoservice</h1>
                    <p class="text-blue-100 text-[10px] font-bold uppercase tracking-[0.25em] mt-1">Workshop Management System</p>
                </div>
            </div>

            <!-- Form -->
            <div class="p-8">
                <?php if ($error): ?>
                <div class="bg-red-50 border border-red-100 text-red-600 p-4 rounded-2xl mb-6 flex items-center gap-3 text-sm font-semibold">
                    <i data-lucide="alert-circle" class="w-5 h-5 shrink-0 text-red-500"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" novalidate>
                    <div class="space-y-5">

                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Email</label>
                            <div class="relative">
                                <i data-lucide="mail" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                <input type="email" name="email" required
                                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                    placeholder="nama@inka.com"
                                    class="w-full pl-11 pr-4 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900 placeholder-slate-400">
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Password</label>
                            <div class="relative">
                                <i data-lucide="lock" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                <input type="password" name="password" id="passwordInput" required
                                    placeholder="••••••••"
                                    class="w-full pl-11 pr-12 py-4 rounded-2xl bg-slate-50 border border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all font-semibold text-slate-900">
                                <button type="button" onclick="togglePassword()"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition-colors">
                                    <i data-lucide="eye" id="eyeIcon" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit"
                            class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white py-4 rounded-2xl font-black text-xs uppercase tracking-[0.2em] shadow-xl shadow-blue-500/30 hover:shadow-blue-500/50 transition-all active:scale-95 flex items-center justify-center gap-3 mt-2">
                            <i data-lucide="log-in" class="w-4 h-4"></i>
                            Masuk ke Sistem
                        </button>
                    </div>
                </form>


            </div>
        </div>

        <p class="text-center text-slate-400 text-[10px] font-black uppercase tracking-widest mt-6">
            © <?php echo date('Y'); ?> Inka Otoservice
        </p>
    </div>

    <script>
        lucide.createIcons();
        function togglePassword() {
            const input = document.getElementById('passwordInput');
            const icon  = document.getElementById('eyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.setAttribute('data-lucide', 'eye-off');
            } else {
                input.type = 'password';
                icon.setAttribute('data-lucide', 'eye');
            }
            lucide.createIcons();
        }
    </script>
</body>
</html>
