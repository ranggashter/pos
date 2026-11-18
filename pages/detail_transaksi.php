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
$items = [];
$detailError = null;

if ($transactionId === false) {
    $detailError = 'ID transaksi tidak valid.';
} else {
    try {
        $statement = $pdo->prepare(
            'SELECT t.id_transaksi, t.tanggal, t.id_pelanggan, t.total_belanja,
                    t.metode_pembayaran, t.status_pembayaran,
                    p.nama_pelanggan, p.kota
             FROM tbl_transaksi t
             LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
             WHERE t.id_transaksi = :id'
        );
        $statement->execute(['id' => $transactionId]);
        $transaction = $statement->fetch();
        if ($transaction === false) {
            $detailError = 'Transaksi tidak ditemukan.';
        } else {
            $itemStatement = $pdo->prepare(
                'SELECT d.id_detail, d.id_produk, d.jumlah, d.harga_satuan, d.diskon, d.subtotal,
                        p.nama_produk, p.satuan
                 FROM tbl_detail_transaksi d
                 LEFT JOIN tbl_produk p ON p.id_produk = d.id_produk
                 WHERE d.id_transaksi = :id
                 ORDER BY d.id_detail'
            );
            $itemStatement->execute(['id' => $transactionId]);
            $items = $itemStatement->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Membaca detail transaksi gagal: ' . $exception->getMessage());
        $detailError = 'Detail transaksi gagal dibaca dari database.';
    }
}
?>

<?php if ($detailError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($detailError) ?></div>
<?php else: ?>
    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Detail Transaksi #<?= e($transaction['id_transaksi']) ?></h2>
                <p class="muted">Tanggal transaksi <?= e(date('d M Y', strtotime($transaction['tanggal']))) ?></p>
            </div>
            <div class="page-header-actions no-print">
                <a class="button button-secondary" href="index.php?page=struk&amp;id=<?= e($transaction['id_transaksi']) ?>">
                    <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span>Lihat Struk</span>
                </a>
                <button class="button button-secondary" type="button" onclick="window.print()">
                    <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    <span>Cetak</span>
                </button>
                <a class="button button-secondary" href="index.php?page=riwayat_transaksi">Kembali</a>
            </div>
        </div>

        <dl class="receipt-meta">
            <div>
                <dt>Pelanggan</dt>
                <dd><?= e($transaction['nama_pelanggan'] ?? 'Tanpa pelanggan') ?></dd>
            </div>
            <div>
                <dt>Kota</dt>
                <dd><?= e($transaction['kota'] ?? '—') ?></dd>
            </div>
            <div>
                <dt>Total Belanja</dt>
                <dd style="color:var(--primary-700);font-size:1.15rem;"><?= e(formatRupiah($transaction['total_belanja'])) ?></dd>
            </div>
            <div>
                <dt>Metode Pembayaran</dt>
                <dd><?= e($transaction['metode_pembayaran'] === 'qris' ? 'QRIS' : ($transaction['metode_pembayaran'] === 'member' ? 'Saldo member' : 'Tunai')) ?></dd>
            </div>
            <div>
                <dt>Status Pembayaran</dt>
                <dd><?= e($transaction['status_pembayaran'] === 'reversed' ? 'Dibatalkan/dikembalikan' : 'Lunas') ?></dd>
            </div>
        </dl>

        <?php if ($items === []): ?>
            <div class="empty-state">
                <p>Transaksi ini tidak memiliki rincian item (data lama).</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>ID Detail</th><th>Produk</th><th>Harga Satuan</th><th>Jumlah</th><th>Diskon</th><th>Subtotal</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><span style="font-family:var(--font-mono);font-size:0.82rem;color:var(--text-muted);">#<?= e($item['id_detail']) ?></span></td>
                            <td><strong><?= e($item['nama_produk'] ?? 'Produk tidak tersedia') ?></strong></td>
                            <td><?= e(formatRupiah($item['harga_satuan'])) ?></td>
                            <td><?= e($item['jumlah']) ?> <?= e($item['satuan'] ?? '') ?></td>
                            <td><?= e(formatRupiah($item['diskon'])) ?></td>
                            <td><span style="font-weight:700;color:var(--primary-700);"><?= e(formatRupiah($item['subtotal'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr><th colspan="5" style="text-align:right;">Grand Total:</th><th style="color:var(--primary-700);font-size:1.1rem;"><?= e(formatRupiah($transaction['total_belanja'])) ?></th></tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
