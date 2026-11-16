<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$loginError = null;
$blockedUntil = (int) ($_SESSION['login_blocked_until'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $loginError = 'Sesi login tidak valid. Muat ulang halaman lalu coba kembali.';
    } elseif ($blockedUntil > time()) {
        $loginError = 'Terlalu banyak percobaan. Coba lagi dalam '
            . ($blockedUntil - time()) . ' detik.';
    } else {
        // Input password dari tampilan formulir berupa teks biasa (plain text)
        $username = isset($_POST['username']) && is_string($_POST['username'])
            ? trim($_POST['username'])
            : '';
        $password = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password']
            : '';
        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

        try {
            $user = false;
            if (preg_match('/^[A-Za-z0-9._-]{3,50}$/D', $username) === 1) {
                $statement = $pdo->prepare(
                    'SELECT id_pengguna, nama_pengguna, username, password_hash, peran, status_aktif
                     FROM tbl_pengguna
                     WHERE username = :username
                     LIMIT 1'
                );
                $statement->execute(['username' => $username]);
                $user = $statement->fetch();
            }

            $storedHash = $user === false ? $dummyHash : (string) $user['password_hash'];
            $passwordMatches = false;

            // 1. Verifikasi utama: plain text dicocokkan dengan hash di database (Bcrypt / Argon2)
            if (password_verify($password, $storedHash)) {
                $passwordMatches = true;

                // Re-hash otomatis jika cost atau algoritma perlu diperbarui
                if ($user !== false && password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                    $rehashStatement = $pdo->prepare(
                        'UPDATE tbl_pengguna SET password_hash = :new_hash WHERE id_pengguna = :id'
                    );
                    $rehashStatement->execute([
                        'new_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => (int) $user['id_pengguna'],
                    ]);
                }
            } elseif ($user !== false && hash_equals($storedHash, $password)) {
                // 2. Fallback: Jika data lama atau input manual phpMyAdmin berupa plain text,
                //    cocokkan lalu OTOMATIS enkripsi menjadi hash di database!
                $passwordMatches = true;
                $rehashStatement = $pdo->prepare(
                    'UPDATE tbl_pengguna SET password_hash = :new_hash WHERE id_pengguna = :id'
                );
                $rehashStatement->execute([
                    'new_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => (int) $user['id_pengguna'],
                ]);
            } elseif ($user !== false && hash_equals($storedHash, md5($password))) {
                // 3. Fallback: Jika hash lama berupa MD5, cocokkan lalu otomatis upgrade ke password_hash
                $passwordMatches = true;
                $rehashStatement = $pdo->prepare(
                    'UPDATE tbl_pengguna SET password_hash = :new_hash WHERE id_pengguna = :id'
                );
                $rehashStatement->execute([
                    'new_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => (int) $user['id_pengguna'],
                ]);
            }

            if ($user !== false && (int) $user['status_aktif'] === 1
                && $passwordMatches && in_array($user['peran'], ['admin', 'kasir'], true)) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id_pengguna'];
                $_SESSION['username'] = (string) $user['username'];
                $_SESSION['user_name'] = (string) $user['nama_pengguna'];
                $_SESSION['user_role'] = (string) $user['peran'];
                unset($_SESSION['login_attempts'], $_SESSION['login_blocked_until'], $_SESSION['csrf_token']);
                header('Location: index.php?page=' . roleHomePage((string) $user['peran']));
                exit;
            }

            $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                $_SESSION['login_attempts'] = 0;
                $_SESSION['login_blocked_until'] = time() + 60;
                $loginError = 'Terlalu banyak percobaan login. Coba lagi dalam 60 detik.';
            } else {
                $_SESSION['login_attempts'] = $attempts;
                $loginError = 'Username atau password salah, atau akun tidak aktif.';
            }
        } catch (PDOException $exception) {
            error_log('Login pengguna gagal diproses: ' . $exception->getMessage());
            $loginError = 'Login tidak dapat diproses. Pastikan database dan tabel pengguna sudah tersedia.';
        }
    }
}
?>

<main class="login-wrapper">
    <div class="login-card">
        <div class="login-brand-header">
            <div class="login-logo-circle">
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
                       pattern="[A-Za-z0-9._-]+" autocomplete="username" placeholder="Masukkan username..." required autofocus>
            </div>
            <div>
                <label for="password">Password (Plain Text)</label>
                <div class="password-input-wrap">
                    <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan password..." required>
                    <button type="button" id="toggle-password-btn" class="password-toggle-btn" title="Tampilkan / Sembunyikan Password" aria-label="Lihat password">
                        <svg id="eye-icon" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
            </div>

            <button class="button button-full" type="submit" style="margin-top: 8px;">
                Masuk ke Aplikasi
            </button>
        </form>

        <!-- Informasi Autentikasi & Akun Cepat -->
        <div style="margin-top:20px;padding:14px;border-radius:var(--radius-md);background:var(--primary-50);border:1px solid var(--primary-200);font-size:0.8rem;color:var(--primary-900);">
            <div style="font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>Sistem Autentikasi Hash &amp; Plain Text</span>
            </div>
            <p style="margin:0 0 8px;font-size:0.78rem;line-height:1.4;">
                Password di database tersimpan dalam bentuk <strong>Hash (Bcrypt)</strong>. Di formulir tampilan ini, Anda memasukkan password dalam bentuk <strong>Plain Text (teks biasa)</strong>.
            </p>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button type="button" class="pos-chip" style="font-size:0.75rem;padding:4px 9px;" onclick="fillLogin('admin', 'admin')">
                    Admin: <strong>admin</strong> / <strong>admin</strong>
                </button>
                <button type="button" class="pos-chip" style="font-size:0.75rem;padding:4px 9px;" onclick="fillLogin('kasir', 'kasir')">
                    Kasir: <strong>kasir</strong> / <strong>kasir</strong>
                </button>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    var passInput = document.getElementById('password');
    var toggleBtn = document.getElementById('toggle-password-btn');
    var eyeIcon = document.getElementById('eye-icon');

    if (passInput && toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            if (passInput.type === 'password') {
                passInput.type = 'text';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />';
            } else {
                passInput.type = 'password';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />';
            }
        });
    }

    window.fillLogin = function (u, p) {
        var userInp = document.getElementById('username');
        if (userInp) userInp.value = u;
        if (passInput) passInput.value = p;
    };
})();
</script>
