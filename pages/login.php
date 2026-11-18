<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$loginError = null;
$blockedUntil = (int) ($_SESSION['login_blocked_until'] ?? 0);
$pdoReady = isset($pdo) && $pdo instanceof PDO;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$pdoReady) {
        $loginError = 'Login tidak dapat diproses. Koneksi database tidak tersedia.';

    } elseif (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $loginError = 'Sesi login tidak valid. Muat ulang halaman lalu coba kembali.';

    } elseif ($blockedUntil > time()) {
        $loginError = 'Terlalu banyak percobaan. Coba lagi dalam '
            . ($blockedUntil - time()) . ' detik.';

    } else {

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

        try {
            $redirect = null;

            // Cari di tbl_pengguna (admin/kasir)
            $stmt = $pdo->prepare(
                'SELECT id_pengguna AS id, nama_pengguna AS nama, username,
                        password_hash, peran AS role, status_aktif AS aktif
                 FROM tbl_pengguna WHERE username = :u LIMIT 1'
            );
            $stmt->execute(['u' => $username]);
            $user = $stmt->fetch();

            // Kalau tidak ada, cari di tbl_pelanggan
            if ($user === false) {
                $stmt = $pdo->prepare(
                    'SELECT id_pelanggan AS id, nama_pelanggan AS nama, username,
                            password_hash, "pelanggan" AS role, 1 AS aktif
                     FROM tbl_pelanggan WHERE username = :u LIMIT 1'
                );
                $stmt->execute(['u' => $username]);
                $user = $stmt->fetch();
            }

            // Verifikasi login
            if ($user === false) {
                password_verify($password, $dummyHash); // timing attack mitigation
            } elseif ((int) $user['aktif'] !== 1) {
                password_verify($password, $dummyHash);
            } elseif (!password_verify($password, (string) $user['password_hash'])) {
                // password salah
            } else {
                // Login sukses
                session_regenerate_id(true);

                $_SESSION['user_id']   = (int) $user['id'];
                $_SESSION['username']  = (string) $user['username'];
                $_SESSION['user_name'] = (string) $user['nama'];
                $_SESSION['user_role'] = (string) $user['role'];

                if ($user['role'] === 'pelanggan') {
                    $_SESSION['pelanggan_id'] = (int) $user['id'];
                    $redirect = 'index.php?page=dashboard_pelanggan';
                } else {
                    $redirect = 'index.php?page=' . roleHomePage((string) $user['role']);
                }

                unset(
                    $_SESSION['login_attempts'],
                    $_SESSION['login_blocked_until'],
                    $_SESSION['csrf_token']
                );

                // Rehash jika perlu
                if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                    $table = $user['role'] === 'pelanggan' ? 'tbl_pelanggan' : 'tbl_pengguna';
                    $idCol = $user['role'] === 'pelanggan' ? 'id_pelanggan' : 'id_pengguna';
                    $pdo->prepare("UPDATE {$table} SET password_hash = :h WHERE {$idCol} = :id")
                        ->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $user['id']]);
                }
            }

            // Redirect kalau sukses
            if ($redirect !== null) {
                header('Location: ' . $redirect);
                exit;
            }

            // Login gagal — hitung percobaan
            $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                $_SESSION['login_attempts'] = 0;
                $_SESSION['login_blocked_until'] = time() + 60;
                $loginError = 'Terlalu banyak percobaan login. Coba lagi dalam 60 detik.';
            } else {
                $_SESSION['login_attempts'] = $attempts;
                $loginError = 'Username atau password salah, atau akun tidak aktif.';
            }

        } catch (Throwable $e) {
            error_log('Login error: ' . $e->getMessage());
            $loginError = 'Terjadi kesalahan. Silakan coba lagi.';
        }
    }
}
?>

<main class="login-wrapper">
    <div class="login-card">
        <div class="login-brand-header">
            <div class="login-logo-circle" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
            </div>
            <h1>Toko Kita</h1>
            <p>Aplikasi Kasir &amp; Manajemen Toko</p>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= e($flash['type'] ?? 'info') ?>" role="status">
                <?= e($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>
        <?php if ($loginError !== null): ?>
            <div class="alert alert-error" role="alert"><?= e($loginError) ?></div>
        <?php endif; ?>

        <form method="post" action="index.php?page=login" class="customer-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div>
                <label for="username">Username</label>
                <input id="username" name="username" type="text" minlength="3" maxlength="50"
                       pattern="[A-Za-z0-9._-]+" autocomplete="username"
                       placeholder="Masukkan username..." required autofocus>
            </div>
            <div>
                <label for="password">Password</label>
                <div class="password-input-wrap">
                    <input id="password" name="password" type="password"
                           autocomplete="current-password"
                           placeholder="Masukkan password..." required>
                    <button type="button" id="toggle-password-btn"
                            class="password-toggle-btn"
                            title="Tampilkan atau sembunyikan password"
                            aria-label="Tampilkan password">
                        <svg id="eye-icon" width="20" height="20" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                             aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
            </div>

            <button class="button button-full" type="submit" style="margin-top: 8px;">
                Masuk ke Aplikasi
            </button>
        </form>
    </div>
</main>

<script>
(function () {
    var passInput = document.getElementById('password');
    var toggleBtn = document.getElementById('toggle-password-btn');
    var eyeIcon = document.getElementById('eye-icon');

    if (passInput && toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var isPassword = passInput.type === 'password';
            passInput.type = isPassword ? 'text' : 'password';
            toggleBtn.setAttribute('aria-label', isPassword ? 'Sembunyikan password' : 'Tampilkan password');
            eyeIcon.innerHTML = isPassword
                ? '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.585 10.585a2 2 0 102.83 2.83"/>'
                : '<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
        });
    }
})();
</script>