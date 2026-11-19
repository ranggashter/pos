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
    'dashboard_pelanggan',
    'pelanggan',
    'kategori',
    'produk',
    'kasir',
    'struk',
    'kasir_qris',
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
    'dashboard_pelanggan' => 'Dashboard Pelanggan',
    'pelanggan' => 'Master Pelanggan',
    'kategori' => 'Master Kategori',
    'produk' => 'Master Produk',
    'kasir' => 'Kasir (POS)',
    'struk' => 'Struk Transaksi',
    'kasir_qris' => 'Pembayaran Duitku',
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

if ($page === 'kasir_qris' && ($_GET['status_json'] ?? null) === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if ($accessDenied) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    if (!($pdo instanceof PDO)) {
        http_response_code(503);
        echo json_encode(['error' => 'database_unavailable']);
        exit;
    }

    $qrisOrderId = filter_var(
        $_GET['id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($qrisOrderId === false) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_order_id']);
        exit;
    }

    try {
        $statusStatement = $pdo->prepare(
            'SELECT id_qris_order, duitku_order_id,
                    status_pembayaran, tanggal, id_pelanggan, total_belanja, id_transaksi
             FROM tbl_qris_order
             WHERE id_qris_order = :id'
        );
        $statusStatement->execute(['id' => $qrisOrderId]);
        $qrisRow = $statusStatement->fetch();
    } catch (PDOException $exception) {
        error_log('Memeriksa status pembayaran otomatis gagal: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'status_unavailable']);
        exit;
    }

    if (!$qrisRow) {
        http_response_code(404);
        echo json_encode(['error' => 'order_not_found']);
        exit;
    }

    $paymentStatus = (string) $qrisRow['status_pembayaran'];

    // Jika masih pending dan Duitku terkonfigurasi, cek langsung ke API Duitku untuk sinkronisasi
    if ($paymentStatus === 'pending') {
        require_once __DIR__ . '/includes/duitku.php';
        $orderId = !empty($qrisRow['duitku_order_id'])
            ? (string) $qrisRow['duitku_order_id']
            : '';

        if ($orderId !== '' && duitkuMerchantCode() !== '' && duitkuApiKey() !== '') {
            $duitkuCheck = duitkuCheckTransactionStatus($orderId);
            if (is_array($duitkuCheck) && isset($duitkuCheck['statusCode'])) {
                $statusCode = (string) $duitkuCheck['statusCode'];
                // Duitku statusCode: '00' = Success, '01' = Pending, '02' = Canceled/Expired
                if ($statusCode === '00') {
                    try {
                        $pdo->beginTransaction();
                        $lockStmt = $pdo->prepare(
                            'SELECT id_qris_order, status_pembayaran, tanggal, id_pelanggan, total_belanja, id_transaksi,
                                    duitku_channel, duitku_va_number, duitku_payment_name,
                                    duitku_payment_code
                             FROM tbl_qris_order
                             WHERE id_qris_order = :id
                             FOR UPDATE'
                        );
                        $lockStmt->execute(['id' => $qrisOrderId]);
                        $locked = $lockStmt->fetch();

                        if ($locked && $locked['status_pembayaran'] === 'pending') {
                            $channelInfo = duitkuGetChannelInfo((string) ($locked['duitku_channel'] ?? ''));
                            $paymentMethodLabel = !empty($locked['duitku_payment_name'])
                                ? (string) $locked['duitku_payment_name']
                                : ((($channelInfo['type'] ?? '') === 'qris' || empty($locked['duitku_channel']))
                                    ? 'qris'
                                    : ($channelInfo['name'] ?? (string) $locked['duitku_channel']));

                            $insertTx = $pdo->prepare(
                                'INSERT INTO tbl_transaksi
                                    (tanggal, id_pelanggan, total_belanja, uang_diterima, saldo_digunakan,
                                     metode_pembayaran, status_pembayaran, duitku_order_id,
                                     duitku_reference, duitku_status, duitku_channel, duitku_va_number,
                                     duitku_payment_name, duitku_payment_code)
                                 VALUES
                                    (:tanggal, :id_pelanggan, :total, NULL, 0.00,
                                     :metode_pembayaran, :status_pembayaran, :order_id,
                                     :duitku_reference, :duitku_status, :duitku_channel, :duitku_va_number,
                                     :duitku_payment_name, :duitku_payment_code)'
                            );
                            $insertTx->execute([
                                'tanggal' => $locked['tanggal'],
                                'id_pelanggan' => $locked['id_pelanggan'],
                                'total' => $locked['total_belanja'],
                                'metode_pembayaran' => $paymentMethodLabel,
                                'status_pembayaran' => 'paid',
                                'order_id' => $orderId,
                                'duitku_reference' => $duitkuCheck['reference'] ?? null,
                                'duitku_status' => 'SUCCESS',
                                'duitku_channel' => $locked['duitku_channel'] ?? null,
                                'duitku_va_number' => $locked['duitku_va_number'] ?? null,
                                'duitku_payment_name' => $locked['duitku_payment_name'] ?? null,
                                'duitku_payment_code' => $locked['duitku_payment_code'] ?? null,
                            ]);
                            $newTxId = (int) $pdo->lastInsertId();

                            $detailStmt = $pdo->prepare(
                                'SELECT id_produk, jumlah, harga_satuan, diskon, subtotal
                                 FROM tbl_qris_order_detail
                                 WHERE id_qris_order = :id_qris_order'
                            );
                            $detailStmt->execute(['id_qris_order' => $qrisOrderId]);
                            $insertDetail = $pdo->prepare(
                                'INSERT INTO tbl_detail_transaksi
                                    (id_transaksi, id_produk, jumlah, harga_satuan, diskon, subtotal)
                                 VALUES (:id_transaksi, :id_produk, :jumlah, :harga_satuan, :diskon, :subtotal)'
                            );
                            foreach ($detailStmt->fetchAll() as $item) {
                                $insertDetail->execute([
                                    'id_transaksi' => $newTxId,
                                    'id_produk' => $item['id_produk'],
                                    'jumlah' => $item['jumlah'],
                                    'harga_satuan' => $item['harga_satuan'],
                                    'diskon' => $item['diskon'],
                                    'subtotal' => $item['subtotal'],
                                ]);
                            }

                            $updateOrder = $pdo->prepare(
                                'UPDATE tbl_qris_order
                                 SET status_pembayaran = :status_pembayaran,
                                     id_transaksi = :id_transaksi,
                                     duitku_reference = COALESCE(:duitku_reference, duitku_reference),
                                     duitku_status = :duitku_status
                                 WHERE id_qris_order = :id_qris_order'
                            );
                            $updateOrder->execute([
                                'status_pembayaran' => 'paid',
                                'id_transaksi' => $newTxId,
                                'duitku_reference' => $duitkuCheck['reference'] ?? null,
                                'duitku_status' => 'SUCCESS',
                                'id_qris_order' => $qrisOrderId,
                            ]);

                            $pdo->commit();
                            $paymentStatus = 'paid';
                        } else {
                            $pdo->rollBack();
                            if ($locked) {
                                $paymentStatus = (string) $locked['status_pembayaran'];
                            }
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Sinkronisasi status Duitku gagal: ' . $e->getMessage());
                    }
                } elseif ($statusCode === '02') {
                    try {
                        duitkuReleasePendingOrder($pdo, $orderId, 'expired');
                        $paymentStatus = 'expired';
                    } catch (Throwable $e) {
                        error_log('Pelepasan order kedaluwarsa Duitku gagal: ' . $e->getMessage());
                    }
                }
            }
        }
    }
    $receiptUrl = null;
    if ($paymentStatus === 'paid') {
        setFlash('success', 'Pembayaran Duitku berhasil diterima.');

        // Ambil id_transaksi terbaru (baru terisi setelah callback/sinkronisasi) untuk mengarahkan ke struk.
        try {
            $receiptStatement = $pdo->prepare(
                'SELECT id_transaksi FROM tbl_qris_order WHERE id_qris_order = :id'
            );
            $receiptStatement->execute(['id' => $qrisOrderId]);
            $paidTransactionId = $receiptStatement->fetchColumn();
            if ($paidTransactionId !== false && $paidTransactionId !== null) {
                $receiptUrl = 'index.php?page=struk&id=' . (int) $paidTransactionId;
            }
        } catch (PDOException $exception) {
            error_log('Membaca id transaksi untuk struk gagal: ' . $exception->getMessage());
        }
    }

    echo json_encode(['status' => $paymentStatus, 'receipt_url' => $receiptUrl]);
    exit;
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
