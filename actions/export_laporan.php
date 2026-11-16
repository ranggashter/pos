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
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

if (!isAuthenticated()) {
    header('Location: ../index.php?page=login');
    exit;
}
if ($_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Anda tidak memiliki izin untuk mengekspor laporan.');
}

try {
    $pdo = require __DIR__ . '/../config/database.php';
    $rows = $pdo->query(
        'SELECT id_pelanggan, nama_pelanggan, total_transaksi, total_belanja,
                KategoriBelanja(total_belanja) AS kategori_belanja
         FROM view_total_belanja
         ORDER BY total_belanja DESC, nama_pelanggan'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('Ekspor laporan pelanggan gagal: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ekspor laporan gagal dibuat.';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="laporan-total-belanja.csv"');
header('X-Content-Type-Options: nosniff');
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    exit('Tidak dapat membuka output ekspor.');
}

fputcsv($output, ['ID Pelanggan', 'Nama Pelanggan', 'Jumlah Transaksi', 'Total Belanja', 'Kategori Belanja']);
foreach ($rows as $row) {
    $customerName = (string) $row['nama_pelanggan'];
    if ($customerName !== '' && preg_match('/^[=+\-@\t\r]/', $customerName) === 1) {
        $customerName = "'" . $customerName;
    }
    fputcsv($output, [
        $row['id_pelanggan'],
        $customerName,
        $row['total_transaksi'],
        $row['total_belanja'],
        $row['kategori_belanja'],
    ]);
}
fclose($output);
