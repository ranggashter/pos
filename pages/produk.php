<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$mode = $_GET['aksi'] ?? 'daftar';
$mode = is_string($mode) ? $mode : 'daftar';
$formErrors = [];
$queryError = null;
$formData = [
    'id_kategori' => '',
    'kode_produk' => '',
    'nama_produk' => '',
    'harga' => '',
    'stok' => '',
    'satuan' => 'pcs',
    'keterangan' => '',
    'status_aktif' => 1,
];
$formId = null;
$lowStockThreshold = 5.0;

$getId = static function (mixed $value): ?int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
};

$validDecimal = static function (string $value, int $scale, int $integerDigits): bool {
    $pattern = '/^\d{1,' . $integerDigits . '}(?:\.\d{1,' . $scale . '})?$/D';
    return preg_match($pattern, $value) === 1;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['aksi']) && is_string($_POST['aksi']) ? $_POST['aksi'] : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Sesi formulir tidak valid atau sudah berakhir. Silakan coba kembali.');
        redirectToMaster('produk');
    }

    if ($action === 'hapus') {
        $id = $getId($_POST['id_produk'] ?? null);
        if ($id === null) {
            setFlash('error', 'ID produk tidak valid.');
            redirectToMaster('produk');
        }
        try {
            $statement = $pdo->prepare('DELETE FROM tbl_produk WHERE id_produk = :id');
            $statement->execute(['id' => $id]);
            setFlash(
                $statement->rowCount() > 0 ? 'success' : 'warning',
                $statement->rowCount() > 0
                    ? 'Produk berhasil dihapus.'
                    : 'Produk tidak ditemukan.'
            );
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                setFlash('error', 'Produk tidak dapat dihapus karena sudah tercatat pada detail transaksi.');
            } else {
                error_log('Hapus produk gagal: ' . $exception->getMessage());
                setFlash('error', 'Produk gagal dihapus karena terjadi kesalahan database.');
            }
        }
        redirectToMaster('produk');
    }

    if ($action !== 'tambah' && $action !== 'ubah') {
        setFlash('error', 'Aksi produk tidak dikenal.');
        redirectToMaster('produk');
    }

    $mode = $action;
    $formId = $getId($_POST['id_produk'] ?? null);
    $categoryId = $getId($_POST['id_kategori'] ?? null);
    $code = isset($_POST['kode_produk']) && is_string($_POST['kode_produk'])
        ? trim($_POST['kode_produk'])
        : '';
    $name = isset($_POST['nama_produk']) && is_string($_POST['nama_produk'])
        ? trim($_POST['nama_produk'])
        : '';
    $price = isset($_POST['harga']) && is_string($_POST['harga'])
        ? trim($_POST['harga'])
        : '';
    $stock = isset($_POST['stok']) && is_string($_POST['stok'])
        ? trim($_POST['stok'])
        : '';
    $unit = isset($_POST['satuan']) && is_string($_POST['satuan'])
        ? trim($_POST['satuan'])
        : '';
    $description = isset($_POST['keterangan']) && is_string($_POST['keterangan'])
        ? trim($_POST['keterangan'])
        : '';
    $active = isset($_POST['status_aktif']) && $_POST['status_aktif'] === '1' ? 1 : 0;
    $formData = [
        'id_kategori' => $categoryId ?? '',
        'kode_produk' => $code,
        'nama_produk' => $name,
        'harga' => $price,
        'stok' => $stock,
        'satuan' => $unit,
        'keterangan' => $description,
        'status_aktif' => $active,
    ];

    if ($categoryId === null) {
        $formErrors[] = 'Kategori wajib dipilih.';
    }
    if ($name === '') {
        $formErrors[] = 'Nama produk wajib diisi.';
    } elseif (mb_strlen($name, 'UTF-8') > 150) {
        $formErrors[] = 'Nama produk maksimal 150 karakter.';
    }
    if ($code !== '' && mb_strlen($code, 'UTF-8') > 50) {
        $formErrors[] = 'Kode produk maksimal 50 karakter.';
    }
    if (!$validDecimal($price, 2, 10)) {
        $formErrors[] = 'Harga harus berupa angka nonnegatif dengan maksimal 2 angka desimal.';
    }
    if (!$validDecimal($stock, 3, 9)) {
        $formErrors[] = 'Stok harus berupa angka nonnegatif dengan maksimal 3 angka desimal.';
    }
    if ($unit === '') {
        $formErrors[] = 'Satuan wajib diisi.';
    } elseif (mb_strlen($unit, 'UTF-8') > 30) {
        $formErrors[] = 'Satuan maksimal 30 karakter.';
    }
    if (mb_strlen($description, 'UTF-8') > 255) {
        $formErrors[] = 'Keterangan maksimal 255 karakter.';
    }
    if ($action === 'ubah' && $formId === null) {
        $formErrors[] = 'ID produk tidak valid.';
    }

    if ($formErrors === []) {
        try {
            $categoryStatement = $pdo->prepare(
                'SELECT status_aktif FROM tbl_kategori WHERE id_kategori = :id'
            );
            $categoryStatement->execute(['id' => $categoryId]);
            $categoryActive = $categoryStatement->fetchColumn();
            if ($categoryActive === false) {
                $formErrors[] = 'Kategori yang dipilih tidak ditemukan.';
            } elseif ((int) $categoryActive !== 1) {
                $currentCategoryId = null;
                if ($action === 'ubah') {
                    $currentStatement = $pdo->prepare(
                        'SELECT id_kategori FROM tbl_produk WHERE id_produk = :id'
                    );
                    $currentStatement->execute(['id' => $formId]);
                    $currentCategoryId = $currentStatement->fetchColumn();
                }
                if ($currentCategoryId === false || (int) $currentCategoryId !== $categoryId) {
                    $formErrors[] = 'Kategori nonaktif tidak dapat dipilih untuk produk.';
                }
            }
        } catch (PDOException $exception) {
            error_log('Validasi kategori produk gagal: ' . $exception->getMessage());
            $formErrors[] = 'Kategori produk gagal divalidasi.';
        }
    }

    if ($formErrors === []) {
        try {
            $values = [
                'id_kategori' => $categoryId,
                'kode_produk' => $code === '' ? null : $code,
                'nama_produk' => $name,
                'harga' => $price,
                'stok' => $stock,
                'satuan' => $unit,
                'keterangan' => $description === '' ? null : $description,
                'status_aktif' => $active,
            ];
            if ($action === 'tambah') {
                $statement = $pdo->prepare(
                    'INSERT INTO tbl_produk
                        (id_kategori, kode_produk, nama_produk, harga, stok, satuan, keterangan, status_aktif)
                     VALUES
                        (:id_kategori, :kode_produk, :nama_produk, :harga, :stok, :satuan, :keterangan, :status_aktif)'
                );
                $statement->execute($values);
                setFlash('success', 'Produk berhasil ditambahkan.');
                redirectToMaster('produk');
            }

            $values['id'] = $formId;
            $statement = $pdo->prepare(
                'UPDATE tbl_produk
                 SET id_kategori = :id_kategori, kode_produk = :kode_produk,
                     nama_produk = :nama_produk, harga = :harga, stok = :stok,
                     satuan = :satuan, keterangan = :keterangan, status_aktif = :status_aktif
                 WHERE id_produk = :id'
            );
            $statement->execute($values);
            setFlash(
                $statement->rowCount() > 0 ? 'success' : 'info',
                $statement->rowCount() > 0
                    ? 'Produk berhasil diperbarui.'
                    : 'Data produk tidak berubah atau produk tidak ditemukan.'
            );
            redirectToMaster('produk');
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                $formErrors[] = 'Kode produk sudah digunakan. Gunakan kode lain.';
            } else {
                error_log('Simpan produk gagal: ' . $exception->getMessage());
                $formErrors[] = 'Produk gagal disimpan karena terjadi kesalahan database.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'ubah') {
    $formId = $getId($_GET['id'] ?? null);
    if ($formId === null) {
        $queryError = 'ID produk tidak valid.';
    } else {
        try {
            $statement = $pdo->prepare(
                'SELECT id_kategori, kode_produk, nama_produk, harga, stok, satuan, keterangan, status_aktif
                 FROM tbl_produk WHERE id_produk = :id'
            );
            $statement->execute(['id' => $formId]);
            $product = $statement->fetch();
            if ($product === false) {
                $queryError = 'Produk tidak ditemukan.';
            } else {
                $formData = $product;
            }
        } catch (PDOException $exception) {
            error_log('Membaca produk gagal: ' . $exception->getMessage());
            $queryError = 'Data produk gagal dibaca dari database.';
        }
    }
}

$categories = [];
try {
    $categoryStatement = $pdo->query(
        'SELECT id_kategori, nama_kategori, status_aktif
         FROM tbl_kategori
         ORDER BY status_aktif DESC, nama_kategori, id_kategori'
    );
    $categories = $categoryStatement->fetchAll();
} catch (PDOException $exception) {
    error_log('Memuat kategori produk gagal: ' . $exception->getMessage());
    $queryError = 'Data kategori gagal dibaca dari database.';
}

$search = isset($_GET['q']) && is_string($_GET['q'])
    ? mb_substr(trim($_GET['q']), 0, 100, 'UTF-8')
    : '';
$filterCategory = isset($_GET['kategori']) ? $getId($_GET['kategori']) : null;
$currentPage = isset($_GET['hal']) ? ($getId($_GET['hal']) ?? 1) : 1;
$perPage = 20;
$totalRows = 0;
$products = [];
$filterError = isset($_GET['kategori']) && $_GET['kategori'] !== '' && $filterCategory === null
    ? 'Filter kategori tidak valid.'
    : null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'daftar' && $queryError === null && $filterError === null) {
    try {
        $conditions = [];
        $parameters = [];
        if ($search !== '') {
            $conditions[] = '(p.nama_produk LIKE :nama OR p.kode_produk LIKE :kode)';
            $parameters['nama'] = '%' . $search . '%';
            $parameters['kode'] = '%' . $search . '%';
        }
        if ($filterCategory !== null) {
            $conditions[] = 'p.id_kategori = :kategori';
            $parameters['kategori'] = $filterCategory;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $countStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM tbl_produk p INNER JOIN tbl_kategori k
             ON k.id_kategori = p.id_kategori' . $where
        );
        $countStatement->execute($parameters);
        $totalRows = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;

        $statement = $pdo->prepare(
            'SELECT p.id_produk, p.kode_produk, p.nama_produk, p.harga, p.stok,
                    p.satuan, p.status_aktif, k.nama_kategori
             FROM tbl_produk p
             INNER JOIN tbl_kategori k ON k.id_kategori = p.id_kategori' . $where . '
             ORDER BY p.nama_produk, p.id_produk
             LIMIT :batas OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':batas', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $products = $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('Daftar produk gagal dibaca: ' . $exception->getMessage());
        $queryError = 'Daftar produk gagal dibaca dari database.';
    }
}
?>

<?php if ($queryError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($queryError) ?></div>
<?php endif; ?>
<?php if ($filterError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($filterError) ?></div>
<?php endif; ?>
<?php if ($formErrors !== []): ?>
    <div class="alert alert-error" role="alert">
        <strong>Periksa kembali formulir:</strong>
        <ul><?php foreach ($formErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($mode === 'tambah' || $mode === 'ubah'): ?>
    <section class="panel form-panel">
        <div class="section-heading">
            <div>
                <h2><?= $mode === 'tambah' ? 'Tambah Produk' : 'Ubah Produk' ?></h2>
                <p class="muted">Masukkan informasi produk. Harga maksimal 2 desimal; stok maksimal 3 desimal.</p>
            </div>
            <a class="button button-secondary" href="index.php?page=produk">Kembali</a>
        </div>
        <form method="post" action="index.php?page=produk" class="customer-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="aksi" value="<?= e($mode) ?>">
            <?php if ($mode === 'ubah'): ?>
                <input type="hidden" name="id_produk" value="<?= e($formId) ?>">
            <?php endif; ?>

            <div>
                <label for="nama_produk">Nama Produk</label>
                <input id="nama_produk" name="nama_produk" type="text" maxlength="150" required
                       placeholder="Contoh: Kopi Susu Aren"
                       value="<?= e($formData['nama_produk']) ?>" autofocus>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div>
                    <label for="id_kategori">Kategori</label>
                    <select id="id_kategori" name="id_kategori" required>
                        <option value="">Pilih kategori...</option>
                        <?php foreach ($categories as $category): ?>
                            <?php
                            $isSelected = (string) $formData['id_kategori'] === (string) $category['id_kategori'];
                            $isDisabled = (int) $category['status_aktif'] !== 1 && !$isSelected;
                            ?>
                            <option value="<?= e($category['id_kategori']) ?>"
                                <?= $isSelected ? 'selected' : '' ?>
                                <?= $isDisabled ? 'disabled' : '' ?>>
                                <?= e($category['nama_kategori']) ?><?= $isDisabled ? ' (nonaktif)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="kode_produk">Kode Produk / Barcode <span style="font-weight:normal;color:var(--text-muted);">(Opsional)</span></label>
                    <input id="kode_produk" name="kode_produk" type="text" maxlength="50"
                           placeholder="Masukkan kode yang terbaca pada barcode"
                           value="<?= e($formData['kode_produk'] ?? '') ?>">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
                <div>
                    <label for="harga">Harga (Rp)</label>
                    <input id="harga" name="harga" type="number" min="0" max="9999999999.99" step="0.01" required
                           placeholder="0.00"
                           value="<?= e($formData['harga']) ?>">
                </div>
                <div>
                    <label for="stok">Stok Awal</label>
                    <input id="stok" name="stok" type="number" min="0" max="999999999.999" step="0.001" required
                           placeholder="0"
                           value="<?= e($formData['stok']) ?>">
                </div>
                <div>
                    <label for="satuan">Satuan</label>
                    <input id="satuan" name="satuan" type="text" maxlength="30" required
                           placeholder="pcs / kg / box"
                           value="<?= e($formData['satuan']) ?>">
                </div>
            </div>

            <div>
                <label for="keterangan">Keterangan <span style="font-weight:normal;color:var(--text-muted);">(Opsional)</span></label>
                <textarea id="keterangan" name="keterangan" maxlength="255" rows="2" placeholder="Catatan tambahan produk..."><?= e($formData['keterangan'] ?? '') ?></textarea>
            </div>

            <div>
                <label class="checkbox-label">
                    <input type="checkbox" name="status_aktif" value="1" <?= (int) $formData['status_aktif'] === 1 ? 'checked' : '' ?>>
                    <span>Produk aktif dan dapat ditransaksikan di kasir</span>
                </label>
            </div>

            <div class="form-actions">
                <button class="button" type="submit"><?= $mode === 'tambah' ? 'Simpan Produk' : 'Simpan Perubahan' ?></button>
                <a class="button button-secondary" href="index.php?page=produk">Batal</a>
            </div>
        </form>
    </section>
<?php elseif ($mode === 'daftar'): ?>
    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Daftar Produk</h2>
                <p class="muted">Total <?= e($totalRows) ?> produk terdaftar · Stok menipis jika kurang dari <?= e($lowStockThreshold) ?></p>
            </div>
            <a class="button" href="index.php?page=produk&amp;aksi=tambah">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Tambah Produk</span>
            </a>
        </div>

        <form method="get" action="index.php" class="search-form product-filter">
            <input type="hidden" name="page" value="produk">
            <div class="search-input-wrap">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input id="cari-produk" type="search" name="q" maxlength="100"
                       placeholder="Cari nama atau kode produk..." value="<?= e($search) ?>">
            </div>

            <div style="min-width: 180px;">
                <label class="sr-only" for="filter-kategori">Filter kategori</label>
                <select id="filter-kategori" name="kategori">
                    <option value="">Semua Kategori</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category['id_kategori']) ?>"
                            <?= $filterCategory === (int) $category['id_kategori'] ? 'selected' : '' ?>>
                            <?= e($category['nama_kategori']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="button button-secondary" type="submit">Filter</button>
            <?php if ($search !== '' || $filterCategory !== null): ?>
                <a class="button button-secondary" href="index.php?page=produk" title="Hapus filter">Reset</a>
            <?php endif; ?>
        </form>

        <?php if ($products === []): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <h3>Tidak ada produk yang cocok</h3>
                <p>Coba sesuaikan kata kunci pencarian atau filter kategori Anda.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>Kode</th><th>Nama Produk</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Status</th><th class="actions-column">Aksi</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $stokNum = (float) $product['stok'];
                        $isLowStock = $stokNum < $lowStockThreshold && $stokNum > 0;
                        $isEmptyStock = $stokNum <= 0;
                        ?>
                        <tr>
                            <td><span style="font-family:var(--font-mono);font-size:0.82rem;color:var(--text-muted);"><?= e($product['kode_produk'] ?? '—') ?></span></td>
                            <td><strong><?= e($product['nama_produk']) ?></strong></td>
                            <td><span style="display:inline-block;padding:2px 8px;border-radius:var(--radius-pill);background:var(--surface-subtle);border:1px solid var(--border-subtle);font-size:0.78rem;font-weight:600;"><?= e($product['nama_kategori']) ?></span></td>
                            <td><span style="font-weight:650;color:var(--primary-700);"><?= e(formatRupiah($product['harga'])) ?></span></td>
                            <td>
                                <span class="stock-pill <?= $isEmptyStock ? 'stock-empty' : ($isLowStock ? 'stock-low' : 'stock-safe') ?>">
                                    <?= e(rtrim(rtrim(number_format($stokNum, 3, ',', '.'), '0'), ',')) ?> <?= e($product['satuan']) ?>
                                    <?= $isLowStock ? '· Menipis' : ($isEmptyStock ? '· Habis' : '') ?>
                                </span>
                            </td>
                            <td><span class="status-badge <?= (int) $product['status_aktif'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                <?= (int) $product['status_aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                            </span></td>
                            <td>
                                <div class="row-actions">
                                    <a class="text-link" href="index.php?page=produk&amp;aksi=ubah&amp;id=<?= e($product['id_produk']) ?>">Ubah</a>
                                    <form method="post" action="index.php?page=produk"
                                          onsubmit="return confirm('Hapus produk ini? Produk yang memiliki transaksi tidak dapat dihapus.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id_produk" value="<?= e($product['id_produk']) ?>">
                                        <button class="link-button danger-link" type="submit">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $totalPages = max(1, (int) ceil($totalRows / $perPage)); ?>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Halaman daftar produk">
                    <div>Menampilkan halaman <strong><?= e($currentPage) ?></strong> dari <strong><?= e($totalPages) ?></strong></div>
                    <div class="pagination-links">
                        <?php if ($currentPage > 1): ?>
                            <a class="button button-secondary button-sm" href="index.php?<?= e(http_build_query(['page' => 'produk', 'q' => $search, 'kategori' => $filterCategory, 'hal' => $currentPage - 1])) ?>">Sebelumnya</a>
                        <?php endif; ?>
                        <?php if ($currentPage < $totalPages): ?>
                            <a class="button button-secondary button-sm" href="index.php?<?= e(http_build_query(['page' => 'produk', 'q' => $search, 'kategori' => $filterCategory, 'hal' => $currentPage + 1])) ?>">Berikutnya</a>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
