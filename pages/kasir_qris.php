<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/duitku.php';

$transactionId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$transaction = false;
$paymentPageError = null;

if ($transactionId === false) {
    $paymentPageError = 'ID pembayaran tidak valid.';
} else {
    try {
        $statement = $pdo->prepare(
            'SELECT id_qris_order, total_belanja, status_pembayaran,
                   duitku_order_id, duitku_reference, duitku_payment_url, duitku_qr_string, duitku_qr_url,
                   duitku_channel, duitku_va_number, duitku_payment_name, duitku_payment_code,
                   id_transaksi
            FROM tbl_qris_order
            WHERE id_qris_order = :id'
        );
        $statement->execute(['id' => $transactionId]);
        $transaction = $statement->fetch();
        if ($transaction === false) {
            $paymentPageError = 'Transaksi pembayaran tidak ditemukan.';
        }
    } catch (PDOException $exception) {
        error_log('Membaca pembayaran Duitku gagal: ' . $exception->getMessage());
        $paymentPageError = 'Status pembayaran gagal dibaca.';
    }
}
if ($transaction !== false && $transaction['status_pembayaran'] === 'paid') {
    setFlash('success', 'Pembayaran Duitku berhasil diterima.');
    // Pembayaran lunas dan transaksi sudah tercatat: langsung tampilkan struk.
    $paidTransactionId = filter_var(
        $transaction['id_transaksi'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($paidTransactionId !== false) {
        header('Location: index.php?page=struk&id=' . $paidTransactionId);
        exit;
    }
}

$orderCode = '';
$paymentName = 'Duitku';
$channelInfo = null;
$qrImageUrl = null;
$paymentUrl = null;
if ($transaction !== false) {
    $orderCode = !empty($transaction['duitku_order_id'])
        ? $transaction['duitku_order_id']
        : ('Order #' . $transaction['id_qris_order']);
    $qrImageUrl = !empty($transaction['duitku_qr_url'])
        ? $transaction['duitku_qr_url']
        : null;
    $paymentUrl = !empty($transaction['duitku_payment_url'])
        ? $transaction['duitku_payment_url']
        : null;
    $channelInfo = duitkuGetChannelInfo((string) ($transaction['duitku_channel'] ?? ''));
    $paymentName = !empty($transaction['duitku_payment_name'])
        ? (string) $transaction['duitku_payment_name']
        : ($channelInfo['name'] ?? 'Duitku');
    if ($channelInfo === null
        && (!empty($transaction['duitku_qr_string']) || !empty($transaction['duitku_qr_url']))) {
        $channelInfo = ['type' => 'qris'];
        $paymentName = 'QRIS Duitku';
    }
}
?>

<section
    class="panel"
    <?php if ($transaction !== false && in_array($transaction['status_pembayaran'], ['pending', 'paid'], true)): ?>
        data-qris-page
        data-qris-status="<?= e($transaction['status_pembayaran']) ?>"
        data-status-url="index.php?page=kasir_qris&amp;id=<?= e($transaction['id_qris_order']) ?>&amp;status_json=1"
        data-return-url="index.php?page=struk&amp;id=<?= e($transaction['id_transaksi']) ?>"
    <?php endif; ?>
>
    <div class="section-heading">
        <div>
            <h2>Pembayaran <?= e($paymentName) ?></h2>
            <?php if ($transaction !== false): ?>
                <p class="muted">Order #<?= e($transaction['id_qris_order']) ?> · <?= e($orderCode) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($paymentPageError !== null): ?>
        <div class="alert alert-error" role="alert"><?= e($paymentPageError) ?></div>
    <?php elseif ($transaction['status_pembayaran'] === 'pending'): ?>
        <p>Total pembayaran: <strong><?= e(formatRupiah($transaction['total_belanja'])) ?></strong></p>
        <?php if (($channelInfo['type'] ?? '') === 'qris' && is_string($qrImageUrl) && $qrImageUrl !== ''): ?>
            <p>Scan QRIS berikut menggunakan aplikasi pembayaran pelanggan. Halaman akan otomatis memperbarui setelah pembayaran dikonfirmasi.</p>
            <div class="qris-code-frame">
                <img
                    class="qris-code"
                    src="<?= e($qrImageUrl) ?>"
                    alt="QRIS pembayaran order <?= e($orderCode) ?>"
                    width="320"
                    height="320"
                >
            </div>
            <p class="muted">
                Jika QR tidak tampil, <a href="<?= e($qrImageUrl) ?>" target="_blank" rel="noopener noreferrer">buka gambar QRIS</a>
            </p>
        <?php elseif (($channelInfo['type'] ?? '') === 'va' && !empty($transaction['duitku_va_number'])): ?>
            <p>Transfer ke nomor Virtual Account berikut untuk menyelesaikan pembayaran:</p>
            <p class="payment-instruction-value"><strong><?= e($transaction['duitku_va_number']) ?></strong></p>
            <p class="muted"><?= e($paymentName) ?> · lakukan pembayaran sebelum invoice kedaluwarsa.</p>
        <?php elseif (($channelInfo['type'] ?? '') === 'retail' && !empty($transaction['duitku_payment_code'])): ?>
            <p>Tunjukkan kode pembayaran berikut di gerai yang dipilih:</p>
            <p class="payment-instruction-value"><strong><?= e($transaction['duitku_payment_code']) ?></strong></p>
            <p class="muted"><?= e($paymentName) ?> · lakukan pembayaran sebelum invoice kedaluwarsa.</p>
        <?php elseif (($channelInfo['type'] ?? '') === 'qris' && is_string($paymentUrl) && $paymentUrl !== ''): ?>
            <p>Buka halaman pembayaran Duitku untuk menyelesaikan pembayaran QRIS.</p>
        <?php else: ?>
            <p>Selesaikan pembayaran melalui aplikasi atau halaman pembayaran yang tersedia.</p>
        <?php endif; ?>
        <?php if (is_string($paymentUrl) && $paymentUrl !== ''): ?>
            <p><a class="button" href="<?= e($paymentUrl) ?>" target="_blank" rel="noopener noreferrer">Buka Pembayaran Duitku</a></p>
        <?php elseif (empty($transaction['duitku_va_number'])
            && empty($transaction['duitku_payment_code'])
            && ($channelInfo['type'] ?? '') !== 'qris'): ?>
            <div class="alert alert-info" role="status">Instruksi pembayaran sedang diproses oleh Duitku.</div>
        <?php endif; ?>
        <?php if ($transaction['status_pembayaran'] === 'pending'): ?>
            <p id="qris-poll-message" class="muted" role="status">Memeriksa status pembayaran otomatis...</p>
            <a class="button button-secondary" href="index.php?page=kasir_qris&amp;id=<?= e($transaction['id_qris_order']) ?>">Periksa status pembayaran</a>
        <?php endif; ?>
    <?php elseif ($transaction['status_pembayaran'] === 'paid'): ?>
        <div class="alert alert-success" role="status">Pembayaran Duitku berhasil diterima. Membuka struk...</div>
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
<?php if ($transaction !== false && in_array($transaction['status_pembayaran'], ['pending', 'paid'], true)): ?>
    <script src="assets/js/kasir_qris.js?v=<?= e((string) @filemtime(__DIR__ . '/../assets/js/kasir_qris.js')) ?>" defer></script>
<?php endif; ?>
