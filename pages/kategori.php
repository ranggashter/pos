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
$formData = ['nama_kategori' => '', 'keterangan' => '', 'status_aktif' => 1];
$formId = null;

$getId = static function (mixed $value): ?int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['aksi']) && is_string($_POST['aksi']) ? $_POST['aksi'] : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Sesi formulir tidak valid atau sudah berakhir. Silakan coba kembali.');
        redirectToMaster('kategori');
    }

    if ($action === 'hapus') {
        $id = $getId($_POST['id_kategori'] ?? null);
        if ($id === null) {
            setFlash('error', 'ID kategori tidak valid.');
            redirectToMaster('kategori');
        }

        try {
            $statement = $pdo->prepare('DELETE FROM tbl_kategori WHERE id_kategori = :id');
            $statement->execute(['id' => $id]);
            setFlash(
                $statement->rowCount() > 0 ? 'success' : 'warning',
                $statement->rowCount() > 0
                    ? 'Kategori berhasil dihapus.'
                    : 'Kategori tidak ditemukan.'
            );
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                setFlash('error', 'Kategori tidak dapat dihapus karena masih digunakan oleh produk.');
            } else {
                error_log('Hapus kategori gagal: ' . $exception->getMessage());
                setFlash('error', 'Kategori gagal dihapus karena terjadi kesalahan database.');
            }
        }
        redirectToMaster('kategori');
    }

    if ($action !== 'tambah' && $action !== 'ubah') {
        setFlash('error', 'Aksi kategori tidak dikenal.');
        redirectToMaster('kategori');
    }

    $mode = $action;
    $formId = $getId($_POST['id_kategori'] ?? null);
    $name = isset($_POST['nama_kategori']) && is_string($_POST['nama_kategori'])
        ? trim($_POST['nama_kategori'])
        : '';
    $description = isset($_POST['keterangan']) && is_string($_POST['keterangan'])
        ? trim($_POST['keterangan'])
        : '';
    $active = isset($_POST['status_aktif']) && $_POST['status_aktif'] === '1' ? 1 : 0;
    $formData = [
        'nama_kategori' => $name,
        'keterangan' => $description,
        'status_aktif' => $active,
    ];

    if ($name === '') {
        $formErrors[] = 'Nama kategori wajib diisi.';
    } elseif (mb_strlen($name, 'UTF-8') > 100) {
        $formErrors[] = 'Nama kategori maksimal 100 karakter.';
    }
    if (mb_strlen($description, 'UTF-8') > 255) {
        $formErrors[] = 'Keterangan maksimal 255 karakter.';
    }
    if ($action === 'ubah' && $formId === null) {
        $formErrors[] = 'ID kategori tidak valid.';
    }

    if ($formErrors === []) {
        try {
            if ($action === 'tambah') {
                $statement = $pdo->prepare(
                    'INSERT INTO tbl_kategori (nama_kategori, keterangan, status_aktif)
                     VALUES (:nama, :keterangan, :aktif)'
                );
                $statement->execute([
                    'nama' => $name,
                    'keterangan' => $description === '' ? null : $description,
                    'aktif' => $active,
                ]);
                setFlash('success', 'Kategori berhasil ditambahkan.');
                redirectToMaster('kategori');
            }

            $statement = $pdo->prepare(
                'UPDATE tbl_kategori
                 SET nama_kategori = :nama, keterangan = :keterangan, status_aktif = :aktif
                 WHERE id_kategori = :id'
            );
            $statement->execute([
                'nama' => $name,
                'keterangan' => $description === '' ? null : $description,
                'aktif' => $active,
                'id' => $formId,
            ]);
            setFlash(
                $statement->rowCount() > 0 ? 'success' : 'info',
                $statement->rowCount() > 0
                    ? 'Kategori berhasil diperbarui.'
                    : 'Data kategori tidak berubah atau kategori tidak ditemukan.'
            );
            redirectToMaster('kategori');
        } catch (PDOException $exception) {
            error_log('Simpan kategori gagal: ' . $exception->getMessage());
            $formErrors[] = 'Kategori gagal disimpan karena terjadi kesalahan database.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'ubah') {
    $formId = $getId($_GET['id'] ?? null);
    if ($formId === null) {
        $queryError = 'ID kategori tidak valid.';
    } else {
        try {
            $statement = $pdo->prepare(
                'SELECT nama_kategori, keterangan, status_aktif
                 FROM tbl_kategori WHERE id_kategori = :id'
            );
            $statement->execute(['id' => $formId]);
            $category = $statement->fetch();
            if ($category === false) {
                $queryError = 'Kategori tidak ditemukan.';
            } else {
                $formData = $category;
            }
        } catch (PDOException $exception) {
            error_log('Membaca kategori gagal: ' . $exception->getMessage());
            $queryError = 'Data kategori gagal dibaca dari database.';
        }
    }
}

$categories = [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'daftar' && $queryError === null) {
    try {
        $statement = $pdo->query(
            'SELECT k.id_kategori, k.nama_kategori, k.keterangan, k.status_aktif,
                    COUNT(p.id_produk) AS jumlah_produk
             FROM tbl_kategori k
             LEFT JOIN tbl_produk p ON p.id_kategori = k.id_kategori
             GROUP BY k.id_kategori, k.nama_kategori, k.keterangan, k.status_aktif
             ORDER BY k.nama_kategori, k.id_kategori'
        );
        $categories = $statement->fetchAll();
    } catch (PDOException $exception) {
        error_log('Daftar kategori gagal dibaca: ' . $exception->getMessage());
        $queryError = 'Daftar kategori gagal dibaca dari database.';
    }
}
?>

<?php if ($queryError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($queryError) ?></div>
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
                <h2><?= $mode === 'tambah' ? 'Tambah Kategori' : 'Ubah Kategori' ?></h2>
                <p class="muted">Kategori nonaktif tidak akan muncul saat membuat produk baru.</p>
            </div>
            <a class="button button-secondary" href="index.php?page=kategori">Kembali</a>
        </div>
        <form method="post" action="index.php?page=kategori" class="customer-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="aksi" value="<?= e($mode) ?>">
            <?php if ($mode === 'ubah'): ?>
                <input type="hidden" name="id_kategori" value="<?= e($formId) ?>">
            <?php endif; ?>

            <div>
                <label for="nama_kategori">Nama Kategori</label>
                <input id="nama_kategori" name="nama_kategori" type="text" maxlength="100" required
                       placeholder="Contoh: Makanan Ringan"
                       value="<?= e($formData['nama_kategori']) ?>" autofocus>
            </div>

            <div>
                <label for="keterangan">Keterangan <span style="font-weight:normal;color:var(--text-muted);">(Opsional)</span></label>
                <textarea id="keterangan" name="keterangan" maxlength="255" rows="3"
                          placeholder="Penjelasan singkat kategori..."><?= e($formData['keterangan']) ?></textarea>
            </div>

            <div>
                <label class="checkbox-label">
                    <input type="checkbox" name="status_aktif" value="1" <?= (int) $formData['status_aktif'] === 1 ? 'checked' : '' ?>>
                    <span>Kategori aktif untuk pemilihan produk</span>
                </label>
            </div>

            <div class="form-actions">
                <button class="button" type="submit"><?= $mode === 'tambah' ? 'Simpan Kategori' : 'Simpan Perubahan' ?></button>
                <a class="button button-secondary" href="index.php?page=kategori">Batal</a>
            </div>
        </form>
    </section>
<?php elseif ($mode === 'daftar'): ?>
    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Daftar Kategori</h2>
                <p class="muted">Total <?= e(count($categories)) ?> kategori produk terdaftar</p>
            </div>
            <a class="button" href="index.php?page=kategori&amp;aksi=tambah">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Tambah Kategori</span>
            </a>
        </div>
        <?php if ($categories === []): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                </div>
                <h3>Belum ada kategori</h3>
                <p>Klik tombol "+ Tambah Kategori" untuk membuat kategori produk pertama.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th style="width:70px;">ID</th><th>Nama Kategori</th><th>Keterangan</th><th>Jumlah Produk</th><th>Status</th><th class="actions-column">Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td><span style="font-family:var(--font-mono);font-size:0.82rem;color:var(--text-muted);">#<?= e($category['id_kategori']) ?></span></td>
                            <td><strong><?= e($category['nama_kategori']) ?></strong></td>
                            <td><?= e($category['keterangan'] ?? '—') ?></td>
                            <td><span style="display:inline-block;padding:2px 8px;border-radius:var(--radius-pill);background:var(--primary-50);border:1px solid var(--primary-100);color:var(--primary-700);font-size:0.8rem;font-weight:700;"><?= e($category['jumlah_produk']) ?> produk</span></td>
                            <td><span class="status-badge <?= (int) $category['status_aktif'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                <?= (int) $category['status_aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                            </span></td>
                            <td>
                                <div class="row-actions">
                                    <a class="text-link" href="index.php?page=kategori&amp;aksi=ubah&amp;id=<?= e($category['id_kategori']) ?>">Ubah</a>
                                    <form method="post" action="index.php?page=kategori"
                                          onsubmit="return confirm('Hapus kategori ini? Kategori yang masih digunakan produk tidak dapat dihapus.');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id_kategori" value="<?= e($category['id_kategori']) ?>">
                                        <button class="link-button danger-link" type="submit">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
