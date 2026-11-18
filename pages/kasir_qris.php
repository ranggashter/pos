<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$transactionId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$transaction = false;
$paymentPageError = null;

if ($transactionId === false) {
    $paymentPageError = 'ID transaksi QRIS tidak valid.';
} else {
    try {
        $statement = $pdo->prepare(
            'SELECT id_qris_order, total_belanja, status_pembayaran,
                   midtrans_redirect_url, midtrans_qr_url,
                   midtrans_order_id, id_transaksi
            FROM tbl_qris_order
            WHERE id_qris_order = :id'
        );
        $statement->execute(['id' => $transactionId]);
        $transaction = $statement->fetch();
        if ($transaction === false) {
            $paymentPageError = 'Transaksi QRIS tidak ditemukan.';
        }
    } catch (PDOException $exception) {
        error_log('Membaca pembayaran QRIS gagal: ' . $exception->getMessage());
        $paymentPageError = 'Status pembayaran QRIS gagal dibaca.';
    }
}
?>

<section class="panel">
    <div class="section-heading">
        <div>
            <h2>Pembayaran QRIS</h2>
            <?php if ($transaction !== false): ?>
                <p class="muted">Order #<?= e($transaction['id_qris_order']) ?> · <?= e($transaction['midtrans_order_id']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($paymentPageError !== null): ?>
        <div class="alert alert-error" role="alert"><?= e($paymentPageError) ?></div>
    <?php elseif ($transaction['status_pembayaran'] === 'pending'): ?>
        <p>Total QRIS: <strong><?= e(formatRupiah($transaction['total_belanja'])) ?></strong></p>
        <?php if (is_string($transaction['midtrans_qr_url']) && $transaction['midtrans_qr_url'] !== ''): ?>
            <p>Scan QRIS berikut menggunakan aplikasi pembayaran pelanggan.</p>
            <div class="qris-code-frame">
                <img
                    class="qris-code"
                    src="<?= e($transaction['midtrans_qr_url']) ?>"
                    alt="QRIS pembayaran order <?= e($transaction['midtrans_order_id']) ?>"
                    width="320"
                    height="320"
                >
            </div>
            <p class="muted">
                Jika QR tidak tampil, <a href="<?= e($transaction['midtrans_qr_url']) ?>" target="_blank" rel="noopener noreferrer">buka gambar QRIS</a>.
            </p>
        <?php elseif (is_string($transaction['midtrans_redirect_url']) && $transaction['midtrans_redirect_url'] !== ''): ?>
            <p>Order ini dibuat dengan checkout lama. Buka halaman Midtrans untuk menyelesaikan pembayaran.</p>
            <a class="button" href="<?= e($transaction['midtrans_redirect_url']) ?>" target="_blank" rel="noopener noreferrer">
                Buka QRIS Midtrans
            </a>
        <?php else: ?>
            <div class="alert alert-info" role="status">Menunggu QR pembayaran dari Midtrans.</div>
        <?php endif; ?>
        <a class="button button-secondary" href="index.php?page=kasir_qris&amp;id=<?= e($transaction['id_qris_order']) ?>">Periksa status pembayaran</a>
    <?php elseif ($transaction['status_pembayaran'] === 'paid'): ?>
        <div class="alert alert-success" role="status">Pembayaran QRIS berhasil diterima.</div>
        <a class="button" href="index.php?page=struk&amp;id=<?= e($transaction['id_transaksi']) ?>">Lihat struk</a>
    <?php elseif ($transaction['status_pembayaran'] === 'paid_unfulfilled'): ?>
        <div class="alert alert-error" role="alert">Pembayaran diterima setelah pesanan kedaluwarsa. Jangan serahkan barang sebelum admin memeriksa transaksi ini.</div>
    <?php elseif ($transaction['status_pembayaran'] === 'reversed'): ?>
        <div class="alert alert-error" role="alert">Status pembayaran berubah menjadi dibatalkan atau dikembalikan. Periksa transaksi ini sebelum menyerahkan barang.</div>
    <?php else: ?>
        <div class="alert alert-error" role="alert">Pembayaran <?= e($transaction['status_pembayaran']) ?>. Stok sudah dikembalikan ke persediaan.</div>
        <a class="button button-secondary" href="index.php?page=kasir">Kembali ke kasir</a>
    <?php endif; ?>
</section>
