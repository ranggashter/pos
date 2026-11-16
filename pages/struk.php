<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$transactionId = filter_var($_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$transaction = false;
$details = [];
$paymentSummary = null;
$receiptError = null;
$formatReceiptRupiah = static function (mixed $value): string {
    $formatted = formatRupiah($value);
    return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : $formatted;
};

if ($transactionId === false) {
    $receiptError = 'ID transaksi tidak valid.';
} else {
    try {
        $statement = $pdo->prepare(
            'SELECT t.id_transaksi, t.tanggal, t.id_pelanggan, t.total_belanja, t.uang_diterima,
                    p.nama_pelanggan, p.kota
             FROM tbl_transaksi t
             LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
             WHERE t.id_transaksi = :id'
        );
        $statement->execute(['id' => $transactionId]);
        $transaction = $statement->fetch();

        if ($transaction === false) {
            $receiptError = 'Transaksi tidak ditemukan.';
        } else {
            $detailStatement = $pdo->prepare(
                'SELECT d.id_detail, d.id_produk, d.jumlah, d.harga_satuan, d.diskon, d.subtotal,
                        p.nama_produk, p.satuan
                 FROM tbl_detail_transaksi d
                 LEFT JOIN tbl_produk p ON p.id_produk = d.id_produk
                 WHERE d.id_transaksi = :id
                 ORDER BY d.id_detail'
            );
            $detailStatement->execute(['id' => $transactionId]);
            $details = $detailStatement->fetchAll();

            if ($transaction['uang_diterima'] !== null) {
                try {
                    $totalCents = decimalToCents($transaction['total_belanja']);
                    $paidCents = decimalToCents($transaction['uang_diterima']);
                    if ($paidCents < $totalCents) {
                        throw new DomainException('Uang diterima lebih kecil daripada total belanja.');
                    }
                    $paymentSummary = [
                        'paid' => $transaction['uang_diterima'],
                        'change' => centsToDecimal($paidCents - $totalCents),
                    ];
                } catch (InvalidArgumentException | DomainException $exception) {
                    error_log('Data pembayaran struk tidak valid: ' . $exception->getMessage());
                    $receiptError = 'Data pembayaran pada struk tidak valid.';
                }
            }
        }
    } catch (PDOException $exception) {
        error_log('Membaca struk transaksi gagal: ' . $exception->getMessage());
        $receiptError = 'Struk gagal dibaca dari database.';
    }
}
?>

<?php if ($receiptError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($receiptError) ?></div>
    <a class="button button-secondary" href="index.php?page=kasir">Kembali ke kasir</a>
<?php else: ?>
    <section class="receipt panel">
        <div class="receipt-heading">
            <div class="receipt-brand-header">
                <p class="eyebrow receipt-kicker">Toko Kita <span>/</span> Bukti Penjualan</p>
                <h2 class="receipt-title">Nota Penjualan</h2>
                <p class="muted receipt-number">No. Transaksi <strong>#<?= e($transaction['id_transaksi']) ?></strong></p>
            </div>
            <div class="receipt-actions no-print">
                <button class="button" type="button" onclick="window.print()">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    <span>Cetak Struk</span>
                </button>
                <a class="button button-secondary" href="index.php?page=kasir">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>Transaksi Baru</span>
                </a>
            </div>
        </div>

        <dl class="receipt-meta">
            <div>
                <dt>Tanggal</dt>
                <dd><?= e(date('d M Y', strtotime($transaction['tanggal']))) ?></dd>
            </div>
            <div>
                <dt>Pelanggan</dt>
                <dd><?= e($transaction['nama_pelanggan'] ?? 'Tanpa pelanggan') ?></dd>
            </div>
            <div>
                <dt>Kota</dt>
                <dd><?= e($transaction['kota'] ?? '—') ?></dd>
            </div>
        </dl>

        <div class="table-wrap receipt-table-wrap">
            <table class="receipt-items">
                <thead>
                <tr>
                    <th>Produk</th>
                    <th class="receipt-amount">Harga</th>
                    <th class="receipt-quantity">Qty</th>
                    <th class="receipt-amount">Diskon</th>
                    <th class="receipt-amount">Subtotal</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($details as $item): ?>
                    <?php $quantity = rtrim(rtrim(number_format((float) $item['jumlah'], 3, ',', '.'), '0'), ','); ?>
                    <tr>
                        <td class="receipt-product"><?= e($item['nama_produk'] ?? 'Produk dihapus') ?></td>
                        <td class="receipt-amount" data-label="Harga"><?= e($formatReceiptRupiah($item['harga_satuan'])) ?></td>
                        <td class="receipt-quantity" data-label="Qty"><?= e($quantity) ?> <?= e($item['satuan'] ?? '') ?></td>
                        <td class="receipt-amount" data-label="Diskon"><?= e($formatReceiptRupiah($item['diskon'])) ?></td>
                        <td class="receipt-amount receipt-subtotal" data-label="Subtotal"><?= e($formatReceiptRupiah($item['subtotal'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th class="receipt-grand-label" colspan="4">Total Pembayaran</th>
                    <th class="receipt-grand-total"><?= e($formatReceiptRupiah($transaction['total_belanja'])) ?></th>
                </tr>
                </tfoot>
            </table>
        </div>

        <dl class="receipt-payment-summary">
            <div>
                <dt>Metode pembayaran</dt>
                <dd><?= $paymentSummary === null ? 'Tidak tercatat' : 'Tunai' ?></dd>
            </div>
            <div>
                <dt>Uang diterima</dt>
                <dd><?= $paymentSummary === null ? 'Tidak tercatat' : e($formatReceiptRupiah($paymentSummary['paid'])) ?></dd>
            </div>
            <div>
                <dt>Kembalian</dt>
                <dd><?= $paymentSummary === null ? 'Tidak tercatat' : e($formatReceiptRupiah($paymentSummary['change'])) ?></dd>
            </div>
        </dl>

        <?php if ($details === []): ?>
            <p class="receipt-empty">Transaksi ini tidak memiliki rincian item.</p>
        <?php endif; ?>

        <div class="receipt-thanks">
            <p>Terima kasih telah berbelanja di Toko Kita.</p>
            <p class="receipt-subnote">Barang yang sudah dibeli tidak dapat ditukar atau dikembalikan.</p>
        </div>
    </section>
<?php endif; ?>
