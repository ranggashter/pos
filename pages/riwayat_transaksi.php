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
$isValidDate = static function (string $value): bool {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

$fromDate = isset($_GET['dari']) && is_string($_GET['dari']) ? trim($_GET['dari']) : '';
$toDate = isset($_GET['sampai']) && is_string($_GET['sampai']) ? trim($_GET['sampai']) : '';
$filterCustomer = isset($_GET['id_pelanggan']) && $_GET['id_pelanggan'] !== ''
    ? $getId($_GET['id_pelanggan'])
    : null;
$currentPage = isset($_GET['hal']) ? ($getId($_GET['hal']) ?? 1) : 1;
$perPage = 20;
$customers = [];
$transactions = [];
$totalRows = 0;
$queryError = null;
$filterError = null;

if (($fromDate !== '' && !$isValidDate($fromDate))
    || ($toDate !== '' && !$isValidDate($toDate))) {
    $filterError = 'Format tanggal filter tidak valid.';
} elseif ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
    $filterError = 'Tanggal mulai tidak boleh melewati tanggal akhir.';
} elseif (isset($_GET['id_pelanggan']) && $_GET['id_pelanggan'] !== '' && $filterCustomer === null) {
    $filterError = 'Filter pelanggan tidak valid.';
}

try {
    $customers = $pdo->query(
        'SELECT id_pelanggan, nama_pelanggan, kota
         FROM tbl_pelanggan
         ORDER BY nama_pelanggan, id_pelanggan'
    )->fetchAll();

    if ($filterError === null) {
        $conditions = [];
        $parameters = [];
        if ($fromDate !== '') {
            $conditions[] = 'v.tanggal >= :dari';
            $parameters['dari'] = $fromDate;
        }
        if ($toDate !== '') {
            $conditions[] = 'v.tanggal <= :sampai';
            $parameters['sampai'] = $toDate;
        }
        if ($filterCustomer !== null) {
            $conditions[] = 'v.id_pelanggan = :id_pelanggan';
            $parameters['id_pelanggan'] = $filterCustomer;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $from = ' FROM (
                      SELECT v.id_transaksi, v.tanggal, v.nama_pelanggan, v.kota,
                             v.total_belanja, t.id_pelanggan, t.metode_pembayaran,
                             t.status_pembayaran
                      FROM view_transaksi_pelanggan v
                      INNER JOIN tbl_transaksi t ON t.id_transaksi = v.id_transaksi
                      UNION ALL
                      SELECT t.id_transaksi, t.tanggal, NULL AS nama_pelanggan,
                             NULL AS kota, t.total_belanja, t.id_pelanggan,
                             t.metode_pembayaran, t.status_pembayaran
                      FROM tbl_transaksi t
                      WHERE t.id_pelanggan IS NULL
                  ) v';

        $countStatement = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
        $countStatement->execute($parameters);
        $totalRows = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;

        $statement = $pdo->prepare(
            'SELECT v.id_transaksi, v.tanggal, v.nama_pelanggan, v.kota, v.total_belanja,
                    v.metode_pembayaran, v.status_pembayaran
             ' . $from . $where . '
             ORDER BY v.tanggal DESC, v.id_transaksi DESC
             LIMIT :batas OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->bindValue(':batas', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $transactions = $statement->fetchAll();
    }
} catch (PDOException $exception) {
    error_log('Membaca riwayat transaksi gagal: ' . $exception->getMessage());
    $queryError = 'Riwayat transaksi gagal dibaca dari database.';
}
?>

<?php if ($queryError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($queryError) ?></div>
<?php endif; ?>
<?php if ($filterError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($filterError) ?></div>
<?php endif; ?>

<section class="panel">
    <div class="section-heading">
        <div>
            <h2>Riwayat Transaksi</h2>
            <p class="muted">Total <?= e($totalRows) ?> transaksi tercatat</p>
        </div>
        <div class="page-header-actions no-print">
            <a class="button button-secondary" href="index.php?page=kasir">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Transaksi Baru</span>
            </a>
            <?php if ($_SESSION['user_role'] === 'admin'): ?>
                <a class="button button-secondary" href="index.php?page=laporan">Lihat Laporan</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" action="index.php" class="report-filter no-print">
        <input type="hidden" name="page" value="riwayat_transaksi">
        <div>
            <label for="dari">Dari Tanggal</label>
            <input id="dari" type="date" name="dari" value="<?= e($fromDate) ?>">
        </div>
        <div>
            <label for="sampai">Sampai Tanggal</label>
            <input id="sampai" type="date" name="sampai" value="<?= e($toDate) ?>">
        </div>
        <div>
            <label for="filter-pelanggan">Pelanggan</label>
            <select id="filter-pelanggan" name="id_pelanggan">
                <option value="">Semua Pelanggan</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= e($customer['id_pelanggan']) ?>"
                        <?= $filterCustomer === (int) $customer['id_pelanggan'] ? 'selected' : '' ?>>
                        <?= e($customer['nama_pelanggan'] ?: 'Pelanggan') ?> (<?= e($customer['kota'] ?? '-') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions" style="display:flex;gap:8px;">
            <button class="button" type="submit">Filter</button>
            <?php if ($fromDate !== '' || $toDate !== '' || $filterCustomer !== null): ?>
                <a class="button button-secondary" href="index.php?page=riwayat_transaksi">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($transactions === []): ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h3>Tidak ada riwayat transaksi yang cocok</h3>
            <p>Silakan sesuaikan filter tanggal atau nama pelanggan di atas.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th style="width: 120px;">No. Transaksi</th>
                    <th>Tanggal</th>
                    <th>Pelanggan</th>
                    <th>Kota</th>
                    <th>Pembayaran</th>
                    <th>Status</th>
                    <th>Total Belanja</th>
                    <th class="actions-column no-print">Aksi</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($transactions as $transaction): ?>
                    <?php
                    $paymentMethodLabel = match ($transaction['metode_pembayaran']) {
                        'qris' => 'QRIS',
                        'member' => 'Member',
                        'tunai' => 'Tunai',
                        default => (string) $transaction['metode_pembayaran'],
                    };
                    ?>
                    <tr>
                        <td><span style="font-family:var(--font-mono);font-size:0.85rem;font-weight:700;color:var(--primary-700);">#<?= e($transaction['id_transaksi']) ?></span></td>
                        <td><span style="font-size:0.88rem;color:var(--text-body);"><?= e(date('d M Y', strtotime($transaction['tanggal']))) ?></span></td>
                        <td><strong><?= e($transaction['nama_pelanggan'] ?? 'Tanpa pelanggan') ?></strong></td>
                        <td><span style="display:inline-block;padding:2px 8px;border-radius:var(--radius-pill);background:var(--surface-subtle);border:1px solid var(--border-subtle);font-size:0.8rem;font-weight:600;"><?= e($transaction['kota'] ?? '—') ?></span></td>
                        <td><?= e($paymentMethodLabel) ?></td>
                        <td><?= e($transaction['status_pembayaran'] === 'reversed' ? 'Dibatalkan/dikembalikan' : 'Lunas') ?></td>
                        <td><span style="font-weight:700;color:var(--primary-700);"><?= e(formatRupiah($transaction['total_belanja'])) ?></span></td>
                        <td class="no-print">
                            <div class="row-actions">
                                <a class="button button-secondary button-sm" href="index.php?page=detail_transaksi&amp;id=<?= e($transaction['id_transaksi']) ?>">
                                    Detail
                                </a>
                                <a class="button button-secondary button-sm" href="index.php?page=struk&amp;id=<?= e($transaction['id_transaksi']) ?>">
                                    Struk
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php $totalPages = max(1, (int) ceil($totalRows / $perPage)); ?>
        <?php if ($totalPages > 1): ?>
            <?php
            $paginationParameters = [
                'page' => 'riwayat_transaksi',
                'dari' => $fromDate,
                'sampai' => $toDate,
                'id_pelanggan' => $filterCustomer,
            ];
            ?>
            <nav class="pagination no-print" aria-label="Halaman riwayat transaksi">
                <div>Menampilkan halaman <strong><?= e($currentPage) ?></strong> dari <strong><?= e($totalPages) ?></strong></div>
                <div class="pagination-links">
                    <?php if ($currentPage > 1): ?>
                        <a class="button button-secondary button-sm" href="index.php?<?= e(http_build_query($paginationParameters + ['hal' => $currentPage - 1])) ?>">Sebelumnya</a>
                    <?php endif; ?>
                    <?php if ($currentPage < $totalPages): ?>
                        <a class="button button-secondary button-sm" href="index.php?<?= e(http_build_query($paginationParameters + ['hal' => $currentPage + 1])) ?>">Berikutnya</a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
