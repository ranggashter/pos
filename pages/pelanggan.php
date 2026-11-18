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
    'nama_pelanggan' => '',
    'kota' => '',
    'rfid_uid' => '',
    'diskon_persen' => '0.00',
    'saldo' => '0.00',
];
$formId = null;

$getCustomerId = static function (mixed $value): ?int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
};

$validateCustomer = static function (array $input): array {
    $name = isset($input['nama_pelanggan']) && is_string($input['nama_pelanggan'])
        ? trim($input['nama_pelanggan'])
        : '';
    $city = isset($input['kota']) && is_string($input['kota'])
        ? trim($input['kota'])
        : '';
    $rfidUid = isset($input['rfid_uid']) && is_string($input['rfid_uid'])
        ? strtoupper(trim($input['rfid_uid']))
        : '';
    $rfidUid = preg_replace('/[\s:-]+/', '', $rfidUid) ?? '';
    $discount = !isset($input['diskon_persen'])
        ? '0'
        : (is_string($input['diskon_persen']) ? trim($input['diskon_persen']) : '');
    $balance = !isset($input['saldo'])
        ? '0'
        : (is_string($input['saldo']) ? trim($input['saldo']) : '');
    $errors = [];

    if ($name === '') {
        $errors[] = 'Nama pelanggan wajib diisi.';
    } elseif (mb_strlen($name, 'UTF-8') > 100) {
        $errors[] = 'Nama pelanggan maksimal 100 karakter.';
    }

    if ($city === '') {
        $errors[] = 'Kota wajib diisi.';
    } elseif (mb_strlen($city, 'UTF-8') > 50) {
        $errors[] = 'Kota maksimal 50 karakter.';
    }

    if ($rfidUid !== '' && preg_match('/^[A-Z0-9]{4,64}$/D', $rfidUid) !== 1) {
        $errors[] = 'UID RFID harus terdiri dari 4–64 karakter huruf atau angka.';
    }

    if (preg_match('/^(?:100(?:\.0{1,2})?|(?:\d|[1-9]\d)(?:\.\d{1,2})?)$/D', $discount) !== 1) {
        $errors[] = 'Diskon harus berupa persentase antara 0 dan 100, maksimal 2 angka desimal.';
    }

    if (preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $balance) !== 1) {
        $errors[] = 'Saldo harus berupa angka Rupiah nonnegatif dengan maksimal 2 angka desimal.';
    }

    return [$name, $city, $rfidUid, $discount, $balance, $errors];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['aksi']) && is_string($_POST['aksi']) ? $_POST['aksi'] : '';

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Sesi formulir tidak valid atau sudah berakhir. Silakan coba kembali.');
        redirectToMaster();
    }

    if ($action === 'hapus') {
        $id = $getCustomerId($_POST['id_pelanggan'] ?? null);
        if ($id === null) {
            setFlash('error', 'ID pelanggan tidak valid.');
            redirectToMaster();
        }

        try {
            $statement = $pdo->prepare(
                'DELETE FROM tbl_pelanggan WHERE id_pelanggan = :id'
            );
            $statement->execute(['id' => $id]);
            setFlash(
                $statement->rowCount() > 0 ? 'success' : 'warning',
                $statement->rowCount() > 0
                    ? 'Pelanggan berhasil dihapus.'
                    : 'Pelanggan tidak ditemukan.'
            );
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                setFlash(
                    'error',
                    'Pelanggan tidak dapat dihapus karena masih memiliki transaksi. Riwayat transaksi harus dipertahankan.'
                );
            } else {
                error_log('Hapus pelanggan gagal: ' . $exception->getMessage());
                setFlash('error', 'Pelanggan gagal dihapus karena terjadi kesalahan database.');
            }
        }

        redirectToMaster();
    }

    if ($action === 'tambah' || $action === 'ubah') {
        [$name, $city, $rfidUid, $discount, $balance, $formErrors] = $validateCustomer($_POST);
        $formData = [
            'nama_pelanggan' => $name,
            'kota' => $city,
            'rfid_uid' => $rfidUid,
            'diskon_persen' => $discount,
            'saldo' => $balance,
        ];
        $formId = $getCustomerId($_POST['id_pelanggan'] ?? null);
        $mode = $action;

        if ($action === 'ubah' && $formId === null) {
            $formErrors[] = 'ID pelanggan tidak valid.';
        }

        if ($formErrors === []) {
            try {
                if ($action === 'tambah') {
                    $statement = $pdo->prepare(
                        'INSERT INTO tbl_pelanggan
                            (nama_pelanggan, kota, rfid_uid, diskon_persen, saldo)
                         VALUES (:nama, :kota, :rfid_uid, :diskon_persen, :saldo)'
                    );
                    $statement->execute([
                        'nama' => $name,
                        'kota' => $city,
                        'rfid_uid' => $rfidUid === '' ? null : $rfidUid,
                        'diskon_persen' => $discount,
                        'saldo' => $balance,
                    ]);
                    setFlash('success', 'Pelanggan berhasil ditambahkan.');
                    redirectToMaster();
                }

                $exists = $pdo->prepare(
                    'SELECT 1 FROM tbl_pelanggan WHERE id_pelanggan = :id'
                );
                $exists->execute(['id' => $formId]);
                if ($exists->fetchColumn() === false) {
                    $formErrors[] = 'Pelanggan yang akan diubah tidak ditemukan.';
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE tbl_pelanggan
                         SET nama_pelanggan = :nama, kota = :kota, rfid_uid = :rfid_uid,
                             diskon_persen = :diskon_persen, saldo = :saldo
                         WHERE id_pelanggan = :id'
                    );
                    $statement->execute([
                        'nama' => $name,
                        'kota' => $city,
                        'rfid_uid' => $rfidUid === '' ? null : $rfidUid,
                        'diskon_persen' => $discount,
                        'saldo' => $balance,
                        'id' => $formId,
                    ]);
                    setFlash('success', 'Data pelanggan berhasil diperbarui.');
                    redirectToMaster();
                }
            } catch (PDOException $exception) {
                error_log('Simpan pelanggan gagal: ' . $exception->getMessage());
                $formErrors[] = (int) ($exception->errorInfo[1] ?? 0) === 1062
                    ? 'UID kartu RFID sudah digunakan pelanggan lain.'
                    : 'Data pelanggan gagal disimpan karena terjadi kesalahan database.';
            }
        }
    } else {
        setFlash('error', 'Aksi pelanggan tidak dikenal.');
        redirectToMaster();
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'ubah') {
    $formId = $getCustomerId($_GET['id'] ?? null);
    if ($formId === null) {
        $queryError = 'ID pelanggan tidak valid.';
    } else {
        try {
            $statement = $pdo->prepare(
                'SELECT nama_pelanggan, kota, rfid_uid, diskon_persen, saldo
                 FROM tbl_pelanggan
                 WHERE id_pelanggan = :id'
            );
            $statement->execute(['id' => $formId]);
            $customer = $statement->fetch();
            if ($customer === false) {
                $queryError = 'Pelanggan tidak ditemukan.';
            } else {
                $formData = $customer;
            }
        } catch (PDOException $exception) {
            error_log('Membaca pelanggan gagal: ' . $exception->getMessage());
            $queryError = 'Data pelanggan gagal dibaca dari database.';
        }
    }
}

$historyCustomer = null;
$history = [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'riwayat') {
    $historyId = $getCustomerId($_GET['id'] ?? null);
    if ($historyId === null) {
        $queryError = 'ID pelanggan tidak valid.';
    } else {
        try {
            $customerStatement = $pdo->prepare(
                'SELECT id_pelanggan, nama_pelanggan, kota
                 FROM tbl_pelanggan
                 WHERE id_pelanggan = :id'
            );
            $customerStatement->execute(['id' => $historyId]);
            $historyCustomer = $customerStatement->fetch();

            $insertLog = $pdo->prepare(
                'SELECT aktivitas, NULL AS nama_lama, NULL AS nama_baru, waktu
                 FROM log_pelanggan
                 WHERE id_pelanggan = :id'
            );
            $insertLog->execute(['id' => $historyId]);
            $history = $insertLog->fetchAll();

            $updateLog = $pdo->prepare(
                "SELECT 'PERUBAHAN NAMA' AS aktivitas, nama_lama, nama_baru, waktu
                 FROM log_perubahan_pelanggan
                 WHERE id_pelanggan = :id"
            );
            $updateLog->execute(['id' => $historyId]);
            $history = array_merge($history, $updateLog->fetchAll());
            usort(
                $history,
                static fn (array $a, array $b): int => strcmp($b['waktu'], $a['waktu'])
            );
        } catch (PDOException $exception) {
            error_log('Membaca riwayat pelanggan gagal: ' . $exception->getMessage());
            $queryError = 'Riwayat pelanggan gagal dibaca dari database.';
        }
    }
}

$search = isset($_GET['q']) && is_string($_GET['q'])
    ? mb_substr(trim($_GET['q']), 0, 100, 'UTF-8')
    : '';
$currentPage = isset($_GET['hal']) ? $getCustomerId($_GET['hal']) : 1;
$currentPage = $currentPage ?? 1;
$perPage = 20;
$totalRows = 0;
$customers = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'daftar' && $queryError === null) {
    try {
        $where = $search === ''
            ? ''
            : ' WHERE nama_pelanggan LIKE :nama OR kota LIKE :kota';
        $countStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM view_pelanggan' . $where
        );
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            $countStatement->execute(['nama' => $pattern, 'kota' => $pattern]);
        } else {
            $countStatement->execute();
        }
        $totalRows = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;

        $listStatement = $pdo->prepare(
            'SELECT id_pelanggan, nama_pelanggan, kota, rfid_uid, diskon_persen, saldo
             FROM tbl_pelanggan' . $where . '
             ORDER BY nama_pelanggan, id_pelanggan
             LIMIT :batas OFFSET :offset'
        );
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            $listStatement->bindValue(':nama', $pattern, PDO::PARAM_STR);
            $listStatement->bindValue(':kota', $pattern, PDO::PARAM_STR);
        }
        $listStatement->bindValue(':batas', $perPage, PDO::PARAM_INT);
        $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStatement->execute();
        $customers = $listStatement->fetchAll();
    } catch (PDOException $exception) {
        error_log('Daftar pelanggan gagal dibaca: ' . $exception->getMessage());
        $queryError = 'Daftar pelanggan gagal dibaca dari database.';
    }
}

if ($queryError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($queryError) ?></div>
<?php endif; ?>

<?php if ($formErrors !== []): ?>
    <div class="alert alert-error" role="alert">
        <strong>Periksa kembali formulir:</strong>
        <ul>
            <?php foreach ($formErrors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($mode === 'riwayat' && $queryError === null): ?>
    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Riwayat pelanggan</h2>
                <?php if ($historyCustomer !== false && $historyCustomer !== null): ?>
                    <p class="muted">
                        <?= e($historyCustomer['nama_pelanggan']) ?>
                        · <?= e($historyCustomer['kota']) ?>
                    </p>
                <?php else: ?>
                    <p class="muted">Data pelanggan sudah tidak tersedia; log tetap ditampilkan.</p>
                <?php endif; ?>
            </div>
            <a class="button button-secondary" href="index.php?page=pelanggan">Kembali</a>
        </div>
        <?php if ($history === []): ?>
            <p class="empty-state">Belum ada log untuk pelanggan ini.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Aktivitas</th>
                        <th>Perubahan nama</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $item): ?>
                        <tr>
                            <td><?= e($item['waktu']) ?></td>
                            <td><?= e($item['aktivitas']) ?></td>
                            <td>
                                <?php if ($item['nama_lama'] !== null): ?>
                                    <?= e($item['nama_lama']) ?> → <?= e($item['nama_baru']) ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php elseif ($mode === 'tambah' || $mode === 'ubah'): ?>
    <section class="panel form-panel">
        <div class="section-heading">
            <div>
                <h2><?= $mode === 'tambah' ? 'Tambah Pelanggan' : 'Ubah Pelanggan' ?></h2>
                <p class="muted">Atur persentase diskon untuk profil pelanggan. Nilai ini hanya disimpan dan belum diterapkan otomatis.</p>
            </div>
            <a class="button button-secondary" href="index.php?page=pelanggan">Kembali</a>
        </div>
        <form method="post" action="index.php?page=pelanggan" class="customer-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="aksi" value="<?= e($mode) ?>">
            <?php if ($mode === 'ubah'): ?>
                <input type="hidden" name="id_pelanggan" value="<?= e($formId) ?>">
            <?php endif; ?>

            <div>
                <label for="nama_pelanggan">Nama Pelanggan</label>
                <input
                    id="nama_pelanggan"
                    name="nama_pelanggan"
                    type="text"
                    maxlength="100"
                    required
                    placeholder="Contoh: Budi Santoso"
                    value="<?= e($formData['nama_pelanggan']) ?>"
                    autocomplete="name"
                    autofocus
                >
            </div>

            <div>
                <label for="kota">Kota Domisili</label>
                <input
                    id="kota"
                    name="kota"
                    type="text"
                    maxlength="50"
                    required
                    placeholder="Contoh: Surabaya"
                    value="<?= e($formData['kota']) ?>"
                    autocomplete="address-level2"
                >
            </div>

            <div>
                <label for="rfid_uid">UID Kartu Member (RFID)</label>
                <input
                    id="rfid_uid"
                    name="rfid_uid"
                    type="text"
                    maxlength="64"
                    placeholder="Klik lalu scan kartu RFID"
                    value="<?= e($formData['rfid_uid'] ?? '') ?>"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                >
                <p class="input-hint">Reader USB keyboard akan mengetikkan UID ke kolom ini. Kosongkan untuk melepas kartu.</p>
            </div>

            <div>
                <label for="diskon_persen">Diskon Pelanggan (%)</label>
                <input
                    id="diskon_persen"
                    name="diskon_persen"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    required
                    value="<?= e($formData['diskon_persen'] ?? '0.00') ?>"
                >
            </div>

            <div>
                <label for="saldo">Saldo Pelanggan (Rp)</label>
                <input
                    id="saldo"
                    name="saldo"
                    type="number"
                    min="0"
                    max="9999999999.99"
                    step="0.01"
                    required
                    value="<?= e($formData['saldo'] ?? '0.00') ?>"
                >
                <p class="input-hint">Saldo diubah oleh admin dan otomatis dipakai di kasir hingga tagihan lunas atau saldo habis.</p>
            </div>

            <div class="form-actions">
                <button class="button" type="submit">
                    <?= $mode === 'tambah' ? 'Simpan Pelanggan' : 'Simpan Perubahan' ?>
                </button>
                <a class="button button-secondary" href="index.php?page=pelanggan">Batal</a>
            </div>
        </form>
    </section>
<?php elseif ($mode === 'daftar'): ?>
    <section class="panel">
        <div class="section-heading">
            <div>
                <h2>Daftar Pelanggan</h2>
                <p class="muted">Total <?= e($totalRows) ?> pelanggan terdaftar di sistem</p>
            </div>
            <a class="button" href="index.php?page=pelanggan&amp;aksi=tambah">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span>Tambah Pelanggan</span>
            </a>
        </div>

        <form method="get" action="index.php" class="search-form">
            <input type="hidden" name="page" value="pelanggan">
            <div class="search-input-wrap">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    id="cari"
                    type="search"
                    name="q"
                    maxlength="100"
                    placeholder="Cari nama atau kota pelanggan..."
                    value="<?= e($search) ?>"
                >
            </div>
            <button class="button button-secondary" type="submit">Cari</button>
            <?php if ($search !== ''): ?>
                <a class="button button-secondary" href="index.php?page=pelanggan">Reset</a>
            <?php endif; ?>
        </form>

        <?php if ($customers === []): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </div>
                <h3>Tidak ada pelanggan yang cocok</h3>
                <p>Coba kata kunci lain atau tambahkan pelanggan baru.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th>Nama Pelanggan</th>
                        <th>Kota</th>
                        <th>Kartu RFID</th>
                        <th>Diskon</th>
                        <th>Saldo</th>
                        <th class="actions-column">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><span style="font-family:var(--font-mono);font-size:0.82rem;color:var(--text-muted);">#<?= e($customer['id_pelanggan']) ?></span></td>
                            <td><strong><?= e($customer['nama_pelanggan']) ?></strong></td>
                            <td><span style="display:inline-block;padding:2px 8px;border-radius:var(--radius-pill);background:var(--surface-subtle);border:1px solid var(--border-subtle);font-size:0.82rem;font-weight:600;"><?= e($customer['kota']) ?></span></td>
                            <td><span style="font-family:var(--font-mono);font-size:0.82rem;"><?= e($customer['rfid_uid'] ?? 'Belum terhubung') ?></span></td>
                            <td><?= e(number_format((float) $customer['diskon_persen'], 2, ',', '.')) ?>%</td>
                            <td><?= e(formatRupiah($customer['saldo'])) ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="text-link" href="index.php?page=pelanggan&amp;aksi=ubah&amp;id=<?= e($customer['id_pelanggan']) ?>">Ubah</a>
                                    <a class="text-link" href="index.php?page=pelanggan&amp;aksi=riwayat&amp;id=<?= e($customer['id_pelanggan']) ?>">Riwayat</a>
                                    <form
                                        method="post"
                                        action="index.php?page=pelanggan"
                                        onsubmit="return confirm('Hapus pelanggan ini? Pelanggan dengan transaksi tidak dapat dihapus.');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id_pelanggan" value="<?= e($customer['id_pelanggan']) ?>">
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
                <nav class="pagination" aria-label="Halaman daftar pelanggan">
                    <div>Menampilkan halaman <strong><?= e($currentPage) ?></strong> dari <strong><?= e($totalPages) ?></strong></div>
                    <div class="pagination-links">
                        <?php if ($currentPage > 1): ?>
                            <a class="button button-secondary button-sm" href="index.php?<?= e(http_build_query(['page' => 'pelanggan', 'q' => $search, 'hal' => $currentPage - 1])) ?>">Sebelumnya</a>
                        <?php endif; ?>
                        <?php if ($currentPage < $totalPages): ?>
                            <a class="button button-secondary button-sm" href="index.php?<?= e(http_build_query(['page' => 'pelanggan', 'q' => $search, 'hal' => $currentPage + 1])) ?>">Berikutnya</a>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
