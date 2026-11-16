<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$getId = static function (mixed $value): ?int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
};
$selectedCustomer = isset($_GET['id_pelanggan']) && $_GET['id_pelanggan'] !== ''
    ? $getId($_GET['id_pelanggan'])
    : null;
$customers = [];
$customerTotals = [];
$customerTransactions = [];
$aboveAverage = [];
$neverPurchased = [];
$topTransactions = [];
$procedureStatus = null;
$reportError = null;
$filterError = null;

if (isset($_GET['id_pelanggan']) && $_GET['id_pelanggan'] !== '' && $selectedCustomer === null) {
    $filterError = 'Pelanggan yang dipilih tidak valid.';
}

try {
    $customers = $pdo->query(
        'SELECT id_pelanggan, nama_pelanggan
         FROM tbl_pelanggan
         ORDER BY nama_pelanggan, id_pelanggan'
    )->fetchAll();

    $customerTotals = $pdo->query(
        'SELECT id_pelanggan, nama_pelanggan, total_transaksi, total_belanja,
                KategoriBelanja(total_belanja) AS kategori_belanja
         FROM view_total_belanja
         ORDER BY total_belanja DESC, nama_pelanggan'
    )->fetchAll();

    $aboveAverage = $pdo->query(
        'SELECT t.id_transaksi, t.tanggal, p.nama_pelanggan, t.total_belanja
         FROM tbl_transaksi t
         LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
         WHERE t.total_belanja > (
             SELECT AVG(total_belanja)
             FROM tbl_transaksi
         )
         ORDER BY t.total_belanja DESC, t.id_transaksi DESC'
    )->fetchAll();

    $neverPurchased = $pdo->query(
        'SELECT p.id_pelanggan, p.nama_pelanggan, p.kota
         FROM tbl_pelanggan p
         WHERE NOT EXISTS (
             SELECT 1
             FROM tbl_transaksi t
             WHERE t.id_pelanggan = p.id_pelanggan
         )
         ORDER BY p.nama_pelanggan, p.id_pelanggan'
    )->fetchAll();

    $topTransactions = $pdo->query(
        'SELECT t.id_transaksi, t.tanggal, p.nama_pelanggan, t.total_belanja
         FROM tbl_transaksi t
         LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
         ORDER BY t.total_belanja DESC, t.id_transaksi DESC
         LIMIT 5'
    )->fetchAll();

    if ($selectedCustomer !== null && $filterError === null) {
        $statusStatement = $pdo->prepare('CALL CekTransaksi(:id)');
        $statusStatement->execute(['id' => $selectedCustomer]);
        $procedureStatus = $statusStatement->fetchColumn();
        $statusStatement->closeCursor();

        $transactionStatement = $pdo->prepare('CALL LaporanTransaksi(:id)');
        $transactionStatement->execute(['id' => $selectedCustomer]);
        $customerTransactions = $transactionStatement->fetchAll();
        $transactionStatement->closeCursor();
    }

    $totalRevenueCents = 0;
    $totalTransactionsCount = 0;
    foreach ($customerTotals as $ct) {
        $totalRevenueCents += decimalToCents($ct['total_belanja'] ?? '0');
        $totalTransactionsCount += (int) ($ct['total_transaksi'] ?? 0);
    }
    $avgTicketCents = $totalTransactionsCount > 0 ? intdiv($totalRevenueCents, $totalTransactionsCount) : 0;
} catch (PDOException $exception) {
    error_log('Membuat laporan gagal: ' . $exception->getMessage());
    $reportError = 'Laporan gagal dibaca dari database. Periksa view, function, dan stored procedure existing.';
}
?>

<?php if ($reportError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($reportError) ?></div>
<?php endif; ?>
<?php if ($filterError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($filterError) ?></div>
<?php endif; ?>

<div class="page-header-actions no-print" style="margin-bottom: 20px; justify-content: flex-end;">
    <a class="button button-secondary" href="actions/export_laporan.php">
        <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
        </svg>
        <span>Ekspor Ringkasan CSV</span>
    </a>
    <button class="button button-secondary" type="button" onclick="window.print()">
        <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
        </svg>
        <span>Cetak Laporan</span>
    </button>
</div>

<!-- KPI Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-title">Total Omset Belanja</div>
            <div class="stat-value" style="color:var(--primary-700);"><?= e(formatRupiah(centsToDecimal($totalRevenueCents ?? 0))) ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-title">Total Transaksi</div>
            <div class="stat-value"><?= e((string) ($totalTransactionsCount ?? 0)) ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-title">Rata-rata Transaksi</div>
            <div class="stat-value"><?= e(formatRupiah(centsToDecimal($avgTicketCents ?? 0))) ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
        </div>
        <div class="stat-info">
            <div class="stat-title">Pelanggan Terdaftar</div>
            <div class="stat-value"><?= e((string) count($customers)) ?></div>
        </div>
    </div>
</div>

<section class="panel report-section">
    <div class="section-heading">
        <div>
            <h2>Total belanja per pelanggan</h2>
            <p class="muted">Sumber: <code>view_total_belanja</code> dan function <code>KategoriBelanja</code>.</p>
        </div>
    </div>
    <?php if ($customerTotals === []): ?>
        <p class="empty-state">Belum ada data pelanggan.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Pelanggan</th><th>Jumlah transaksi</th><th>Total belanja</th><th>Kategori belanja</th></tr></thead>
                <tbody>
                <?php foreach ($customerTotals as $row): ?>
                    <tr>
                        <td><?= e($row['nama_pelanggan']) ?></td>
                        <td><?= e($row['total_transaksi']) ?></td>
                        <td><?= e(formatRupiah($row['total_belanja'])) ?></td>
                        <td><?= e($row['kategori_belanja']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel report-section">
    <div class="section-heading">
        <div>
            <h2>Transaksi per pelanggan</h2>
            <p class="muted">Memanggil stored procedure <code>CekTransaksi</code> dan <code>LaporanTransaksi</code>.</p>
        </div>
    </div>
    <form method="get" action="index.php" class="report-filter no-print">
        <input type="hidden" name="page" value="laporan">
        <div style="flex:1;">
            <label for="laporan-pelanggan">Pilih Pelanggan</label>
            <select id="laporan-pelanggan" name="id_pelanggan" required>
                <option value="">Pilih pelanggan...</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= e($customer['id_pelanggan']) ?>"
                        <?= $selectedCustomer === (int) $customer['id_pelanggan'] ? 'selected' : '' ?>>
                        <?= e($customer['nama_pelanggan']) ?> (#<?= e($customer['id_pelanggan']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="button" type="submit">Cek Transaksi</button>
            <?php if ($selectedCustomer !== null): ?>
                <a class="button button-secondary" href="index.php?page=laporan">Reset</a>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($procedureStatus !== null): ?>
        <p class="procedure-status"><?= e($procedureStatus) ?></p>
        <?php if ($customerTransactions === []): ?>
            <p class="empty-state">Pelanggan belum memiliki transaksi.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>No. transaksi</th><th>Tanggal</th><th>Pelanggan</th><th>Kota</th><th>Total</th><th class="no-print">Detail</th></tr></thead>
                    <tbody>
                    <?php foreach ($customerTransactions as $row): ?>
                        <tr>
                            <td>#<?= e($row['id_transaksi']) ?></td>
                            <td><?= e($row['tanggal']) ?></td>
                            <td><?= e($row['nama_pelanggan']) ?></td>
                            <td><?= e($row['kota']) ?></td>
                            <td><?= e(formatRupiah($row['total_belanja'])) ?></td>
                            <td class="no-print"><a class="text-link" href="index.php?page=detail_transaksi&amp;id=<?= e($row['id_transaksi']) ?>">Detail</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="panel report-section">
    <div class="section-heading">
        <div>
            <h2>Transaksi di atas rata-rata</h2>
            <p class="muted">Subquery membandingkan transaksi dengan nilai rata-rata seluruh transaksi.</p>
        </div>
    </div>
    <?php if ($aboveAverage === []): ?>
        <p class="empty-state">Tidak ada transaksi di atas rata-rata.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>No. transaksi</th><th>Tanggal</th><th>Pelanggan</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($aboveAverage as $row): ?>
                    <tr>
                        <td><a class="text-link" href="index.php?page=detail_transaksi&amp;id=<?= e($row['id_transaksi']) ?>">#<?= e($row['id_transaksi']) ?></a></td>
                        <td><?= e($row['tanggal']) ?></td>
                        <td><?= e($row['nama_pelanggan'] ?? '—') ?></td>
                        <td><?= e(formatRupiah($row['total_belanja'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel report-section">
    <div class="section-heading">
        <div>
            <h2>Pelanggan belum pernah bertransaksi</h2>
            <p class="muted">Subquery menggunakan <code>NOT EXISTS</code>.</p>
        </div>
    </div>
    <?php if ($neverPurchased === []): ?>
        <p class="empty-state">Semua pelanggan sudah memiliki transaksi.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID pelanggan</th><th>Nama pelanggan</th><th>Kota</th></tr></thead>
                <tbody>
                <?php foreach ($neverPurchased as $row): ?>
                    <tr>
                        <td><?= e($row['id_pelanggan']) ?></td>
                        <td><?= e($row['nama_pelanggan']) ?></td>
                        <td><?= e($row['kota']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel report-section">
    <div class="section-heading">
        <div>
            <h2>Lima transaksi terbesar</h2>
            <p class="muted">Diurutkan berdasarkan total belanja tertinggi.</p>
        </div>
    </div>
    <?php if ($topTransactions === []): ?>
        <p class="empty-state">Belum ada transaksi.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>No. transaksi</th><th>Tanggal</th><th>Pelanggan</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($topTransactions as $row): ?>
                    <tr>
                        <td><a class="text-link" href="index.php?page=detail_transaksi&amp;id=<?= e($row['id_transaksi']) ?>">#<?= e($row['id_transaksi']) ?></a></td>
                        <td><?= e($row['tanggal']) ?></td>
                        <td><?= e($row['nama_pelanggan'] ?? '—') ?></td>
                        <td><?= e(formatRupiah($row['total_belanja'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
