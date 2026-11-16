<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
ob_start();
define('APP_BOOTSTRAPPED', true);
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/auth.php';

$page = $_GET['page'] ?? 'pelanggan';
$allowedPages = [
    'pelanggan',
    'kategori',
    'produk',
    'kasir',
    'struk',
    'riwayat_transaksi',
    'detail_transaksi',
    'laporan',
    'login',
    'logout',
];
if (!is_string($page) || !in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'pelanggan';
    $routeError = 'Halaman yang diminta tidak ditemukan.';
} else {
    $routeError = null;
}

if ($page === 'logout') {
    if (!isAuthenticated()) {
        header('Location: index.php?page=login');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST'
        || !isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Permintaan logout tidak valid. Coba kembali.');
        header('Location: index.php?page=' . roleHomePage((string) $_SESSION['user_role']));
        exit;
    }

    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

$authenticated = isAuthenticated();
if ($page === 'login' && $authenticated) {
    header('Location: index.php?page=' . roleHomePage((string) $_SESSION['user_role']));
    exit;
}
if ($page !== 'login' && !$authenticated) {
    header('Location: index.php?page=login');
    exit;
}

$accessDenied = $authenticated
    && $page !== 'login'
    && !canAccessPage((string) $_SESSION['user_role'], $page);
if ($accessDenied) {
    http_response_code(403);
}

$pageTitles = [
    'pelanggan' => 'Master Pelanggan',
    'kategori' => 'Master Kategori',
    'produk' => 'Master Produk',
    'kasir' => 'Kasir (POS)',
    'struk' => 'Struk Transaksi',
    'riwayat_transaksi' => 'Riwayat Transaksi',
    'detail_transaksi' => 'Detail Transaksi',
    'laporan' => 'Laporan Penjualan',
    'login' => 'Login',
];

$pdo = null;
if ($routeError === null && !$accessDenied) {
    try {
        $pdo = require __DIR__ . '/config/database.php';
    } catch (PDOException $exception) {
        error_log('Koneksi db_tokokita gagal: ' . $exception->getMessage());
        http_response_code(500);
        $connectionError = 'Koneksi database gagal. Pastikan MySQL/MariaDB aktif dan konfigurasi di config/database.php benar.';
    }
}

$flash = takeFlash();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitles[$page]) ?> - Toko Kita</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?php if ($page === 'login'): ?>
    <?php if ($pdo === null): ?>
        <main class="login-wrapper">
            <div class="login-card">
                <div class="alert alert-error" role="alert">
                    <?= e($connectionError ?? 'Koneksi database belum tersedia.') ?>
                </div>
            </div>
        </main>
    <?php else: ?>
        <?php require __DIR__ . '/pages/login.php'; ?>
    <?php endif; ?>
<?php else: ?>
    <!-- Mobile Topbar -->
    <header class="mobile-topbar no-print">
        <div style="display:flex;align-items:center;gap:10px;">
            <div class="brand-icon" style="width:32px;height:32px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                </svg>
            </div>
            <strong>Toko Kita</strong>
        </div>
        <button id="mobile-toggle-btn" class="mobile-menu-btn" type="button" aria-label="Buka menu navigasi">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
    </header>

    <div class="app-shell">
        <?php require __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header no-print">
                <div>
                    <p class="eyebrow">Aplikasi Kasir &amp; POS</p>
                    <h1><?= e($pageTitles[$page]) ?></h1>
                </div>
                <div class="page-header-actions">
                    <span style="font-size:0.85rem;color:var(--text-muted);display:inline-flex;align-items:center;gap:6px;background:var(--surface-card);border:1px solid var(--border-subtle);padding:6px 12px;border-radius:var(--radius-pill);">
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <?= date('d M Y') ?>
                    </span>
                </div>
            </header>

            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= e($flash['type'] ?? 'info') ?> no-print" role="status">
                    <?= e($flash['message'] ?? '') ?>
                </div>
            <?php endif; ?>

            <?php if ($routeError !== null): ?>
                <div class="alert alert-error" role="alert"><?= e($routeError) ?></div>
            <?php elseif ($accessDenied): ?>
                <div class="alert alert-error" role="alert">Anda tidak memiliki izin untuk membuka halaman ini.</div>
            <?php elseif ($pdo === null): ?>
                <div class="alert alert-error" role="alert"><?= e($connectionError ?? 'Koneksi database belum tersedia.') ?></div>
            <?php else: ?>
                <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
            <?php endif; ?>
        </main>
    </div>

    <script>
    (function () {
        var toggleBtn = document.getElementById('mobile-toggle-btn');
        var sidebar = document.getElementById('app-sidebar');
        var backdrop = document.getElementById('sidebar-backdrop');
        if (toggleBtn && sidebar && backdrop) {
            function toggleMenu() {
                var isOpen = sidebar.classList.toggle('open');
                backdrop.classList.toggle('open', isOpen);
            }
            toggleBtn.addEventListener('click', toggleMenu);
            backdrop.addEventListener('click', toggleMenu);
        }
    })();
    </script>
<?php endif; ?>
</body>
</html>
