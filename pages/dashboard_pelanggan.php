<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

if (!isset($_SESSION['pelanggan_id']) || (int) $_SESSION['pelanggan_id'] !== (int) $_SESSION['user_id']) {
    header('Location: index.php?page=login');
    exit;
}

if (!isset($_SESSION['pelanggan_cart']) || !is_array($_SESSION['pelanggan_cart'])) {
    $_SESSION['pelanggan_cart'] = [];
}

$cart = &$_SESSION['pelanggan_cart'];
$errors = [];
$customerId = (int) $_SESSION['pelanggan_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Sesi checkout tidak valid. Muat ulang halaman lalu coba kembali.');
        redirectToMaster('dashboard_pelanggan');
    }

    $action = isset($_POST['aksi']) && is_string($_POST['aksi']) ? $_POST['aksi'] : '';

    if ($action === 'tambah' || $action === 'kurangi' || $action === 'hapus') {
        $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($productId === false) {
            $errors[] = 'Produk tidak valid.';
        } elseif ($action === 'tambah') {
            $quantity = 1;
            $currentQuantity = (int) ($cart[$productId] ?? 0);

            $statement = $pdo->prepare(
                'SELECT nama_produk, stok
                 FROM tbl_produk
                 WHERE id_produk = :id AND status_aktif = 1'
            );
            $statement->execute(['id' => $productId]);
            $product = $statement->fetch();

            if ($product === false) {
                $errors[] = 'Produk tidak tersedia.';
            } elseif ((float) $product['stok'] <= $currentQuantity) {
                $errors[] = 'Stok ' . $product['nama_produk'] . ' tidak mencukupi.';
            } else {
                $cart[$productId] = $currentQuantity + $quantity;
            }
        } elseif ($action === 'kurangi') {
            $currentQuantity = (int) ($cart[$productId] ?? 0);
            if ($currentQuantity <= 1) {
                unset($cart[$productId]);
            } else {
                $cart[$productId] = $currentQuantity - 1;
            }
        } elseif ($action === 'hapus') {
            unset($cart[$productId]);
        }
    } elseif ($action === 'kosongkan') {
        $cart = [];
    } elseif ($action === 'checkout') {
        if ($cart === []) {
            $errors[] = 'Keranjang masih kosong.';
        } else {
            try {
                $pdo->beginTransaction();

                $customerStatement = $pdo->prepare(
                    'SELECT id_pelanggan FROM tbl_pelanggan WHERE id_pelanggan = :id FOR UPDATE'
                );
                $customerStatement->execute(['id' => $customerId]);
                if ($customerStatement->fetchColumn() === false) {
                    throw new DomainException('Pelanggan tidak ditemukan.');
                }

                $items = [];
                $totalCents = 0;
                $maximumTotalCents = 999999999999;
                ksort($cart, SORT_NUMERIC);

                $productStatement = $pdo->prepare(
                    'SELECT id_produk, nama_produk, harga, stok
                     FROM tbl_produk
                     WHERE id_produk = :id AND status_aktif = 1
                     FOR UPDATE'
                );

                foreach ($cart as $rawProductId => $rawQuantity) {
                    $productId = filter_var($rawProductId, FILTER_VALIDATE_INT, [
                        'options' => ['min_range' => 1],
                    ]);
                    $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT, [
                        'options' => ['min_range' => 1, 'max_range' => 1000000],
                    ]);
                    if ($productId === false || $quantity === false) {
                        throw new DomainException('Keranjang berisi data yang tidak valid.');
                    }

                    $productStatement->execute(['id' => $productId]);
                    $product = $productStatement->fetch();
                    if ($product === false) {
                        throw new DomainException('Salah satu produk sudah tidak tersedia.');
                    }
                    if ((float) $product['stok'] < $quantity) {
                        throw new DomainException(
                            'Stok ' . $product['nama_produk'] . ' tidak mencukupi.'
                        );
                    }

                    $unitCents = decimalToCents((string) $product['harga']);
                    $subtotalCents = $unitCents * $quantity;
                    if ($subtotalCents > $maximumTotalCents - $totalCents) {
                        throw new DomainException('Total transaksi melebihi batas yang dapat disimpan.');
                    }

                    $totalCents += $subtotalCents;
                    $items[] = [
                        'id_produk' => $productId,
                        'jumlah' => $quantity,
                        'harga_satuan' => (string) $product['harga'],
                        'subtotal' => centsToDecimal($subtotalCents),
                    ];
                }

                $transactionStatement = $pdo->prepare(
                    'INSERT INTO tbl_transaksi
                        (tanggal, id_pelanggan, total_belanja, uang_diterima)
                     VALUES (:tanggal, :pelanggan, :total, :dibayar)'
                );
                $transactionStatement->execute([
                    'tanggal' => date('Y-m-d'),
                    'pelanggan' => $customerId,
                    'total' => centsToDecimal($totalCents),
                    'dibayar' => centsToDecimal($totalCents),
                ]);
                $transactionId = (int) $pdo->lastInsertId();

                $stockStatement = $pdo->prepare(
                    'UPDATE tbl_produk
                     SET stok = stok - :jumlah
                     WHERE id_produk = :id AND stok >= :minimum'
                );
                $detailStatement = $pdo->prepare(
                    'INSERT INTO tbl_detail_transaksi
                        (id_transaksi, id_produk, jumlah, harga_satuan, diskon, subtotal)
                     VALUES
                        (:transaksi, :produk, :jumlah, :harga, 0.00, :subtotal)'
                );

                foreach ($items as $item) {
                    $stockStatement->execute([
                        'jumlah' => $item['jumlah'],
                        'id' => $item['id_produk'],
                        'minimum' => $item['jumlah'],
                    ]);
                    if ($stockStatement->rowCount() !== 1) {
                        throw new DomainException('Stok berubah saat checkout. Silakan coba kembali.');
                    }

                    $detailStatement->execute([
                        'transaksi' => $transactionId,
                        'produk' => $item['id_produk'],
                        'jumlah' => $item['jumlah'],
                        'harga' => $item['harga_satuan'],
                        'subtotal' => $item['subtotal'],
                    ]);
                }

                $pdo->commit();
                $cart = [];
                setFlash('success', 'Checkout berhasil. Pesanan #' . $transactionId . ' telah dibuat.');
            } catch (PDOException | DomainException | InvalidArgumentException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Checkout pelanggan gagal: ' . $exception->getMessage());
                $errors[] = $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'Checkout gagal diproses. Silakan coba kembali.';
            }
        }
    } else {
        $errors[] = 'Aksi keranjang tidak dikenal.';
    }

    if ($errors === []) {
        redirectToMaster('dashboard_pelanggan');
    }
}

$products = [];
$cartItems = [];
$recentTransactions = [];
$queryError = null;

try {
    $products = $pdo->query(
        'SELECT p.id_produk, p.kode_produk, p.nama_produk, p.harga, p.stok, p.satuan,
                p.keterangan, k.nama_kategori
         FROM tbl_produk p
         LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori
         WHERE p.status_aktif = 1
         ORDER BY p.nama_produk, p.id_produk'
    )->fetchAll();

    $totalCents = 0;
    if ($cart !== []) {
        $placeholders = implode(',', array_fill(0, count($cart), '?'));
        $statement = $pdo->prepare(
            'SELECT id_produk, nama_produk, harga, stok, satuan
             FROM tbl_produk
             WHERE id_produk IN (' . $placeholders . ') AND status_aktif = 1'
        );
        $statement->execute(array_map('intval', array_keys($cart)));
        foreach ($statement->fetchAll() as $product) {
            $productId = (int) $product['id_produk'];
            $quantity = (int) ($cart[$productId] ?? 0);
            $subtotalCents = decimalToCents((string) $product['harga']) * $quantity;
            $totalCents += $subtotalCents;
            $cartItems[] = [
                'id_produk' => $productId,
                'nama_produk' => (string) $product['nama_produk'],
                'harga' => (string) $product['harga'],
                'stok' => (float) $product['stok'],
                'satuan' => (string) $product['satuan'],
                'jumlah' => $quantity,
                'subtotal' => centsToDecimal($subtotalCents),
            ];
        }
    }

    $historyStatement = $pdo->prepare(
        'SELECT id_transaksi, tanggal, total_belanja
         FROM tbl_transaksi
         WHERE id_pelanggan = :pelanggan
         ORDER BY tanggal DESC, id_transaksi DESC
         LIMIT 10'
    );
    $historyStatement->execute(['pelanggan' => $customerId]);
    $recentTransactions = $historyStatement->fetchAll();
} catch (PDOException | InvalidArgumentException $exception) {
    error_log('Dashboard pelanggan gagal dibaca: ' . $exception->getMessage());
    $queryError = 'Katalog atau riwayat pesanan gagal dimuat.';
}
?>

<section class="panel">
    <div class="section-heading">
        <div>
            <h2>Halo, <?= e($_SESSION['user_name']) ?></h2>
            <p class="muted">Pilih barang, lalu checkout pesanan Anda.</p>
        </div>
    </div>

    <?php if ($queryError !== null): ?>
        <div class="alert alert-error" role="alert"><?= e($queryError) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="pos-layout">
        <div>
            <?php if ($products === []): ?>
                <div class="empty-state">
                    <h3>Belum ada barang tersedia</h3>
                    <p>Produk aktif akan muncul di sini setelah ditambahkan toko.</p>
                </div>
            <?php else: ?>
                <div class="pos-grid">
                    <?php foreach ($products as $product): ?>
                        <?php
                        $stock = (float) $product['stok'];
                        $inCart = (int) ($cart[(int) $product['id_produk']] ?? 0);
                        ?>
                        <form method="post" action="index.php?page=dashboard_pelanggan">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="aksi" value="tambah">
                            <input type="hidden" name="id_produk" value="<?= e($product['id_produk']) ?>">
                            <button type="submit" class="pos-card" <?= $stock <= 0 || $inCart >= $stock ? 'disabled' : '' ?>>
                                <span class="pos-card-code"><?= e($product['kode_produk'] ?: 'Produk') ?></span>
                                <strong class="pos-card-name"><?= e($product['nama_produk']) ?></strong>
                                <span class="pos-card-price"><?= e(formatRupiah($product['harga'])) ?></span>
                                <span class="muted" style="font-size:0.78rem;">
                                    Stok: <?= e(rtrim(rtrim(number_format($stock, 3, ',', '.'), '0'), ',')) ?>
                                    <?= e($product['satuan']) ?>
                                </span>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <aside class="pos-cart-panel">
            <div class="pos-cart-header">
                <h3 class="pos-cart-title">Keranjang</h3>
                <span class="pos-cart-count"><?= e(count($cart)) ?></span>
            </div>
            <div class="pos-cart-items">
                <?php if ($cartItems === []): ?>
                    <div class="pos-cart-empty">Keranjang masih kosong.</div>
                <?php else: ?>
                    <?php foreach ($cartItems as $item): ?>
                        <div class="pos-cart-item">
                            <div class="pos-item-meta">
                                <strong class="pos-item-name"><?= e($item['nama_produk']) ?></strong>
                                <span class="pos-item-price"><?= e(formatRupiah($item['harga'])) ?></span>
                            </div>
                            <div class="pos-quantity-controls">
                                <form method="post" action="index.php?page=dashboard_pelanggan">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="aksi" value="kurangi">
                                    <input type="hidden" name="id_produk" value="<?= e($item['id_produk']) ?>">
                                    <button type="submit" class="pos-qty-btn">-</button>
                                </form>
                                <strong><?= e($item['jumlah']) ?></strong>
                                <form method="post" action="index.php?page=dashboard_pelanggan">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="aksi" value="tambah">
                                    <input type="hidden" name="id_produk" value="<?= e($item['id_produk']) ?>">
                                    <button type="submit" class="pos-qty-btn"
                                            <?= $item['jumlah'] >= $item['stok'] ? 'disabled' : '' ?>>+</button>
                                </form>
                                <form method="post" action="index.php?page=dashboard_pelanggan">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="aksi" value="hapus">
                                    <input type="hidden" name="id_produk" value="<?= e($item['id_produk']) ?>">
                                    <button type="submit" class="pos-qty-btn" title="Hapus">x</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="pos-cart-footer">
                <div class="pos-total-row">
                    <span class="pos-total-label">Total</span>
                    <strong class="pos-total-amount"><?= e(formatRupiah(centsToDecimal($totalCents))) ?></strong>
                </div>
                <div class="pos-cart-actions">
                    <form method="post" action="index.php?page=dashboard_pelanggan" style="flex:1;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="aksi" value="kosongkan">
                        <button type="submit" class="button button-secondary button-full"
                                <?= $cart === [] ? 'disabled' : '' ?>>Kosongkan</button>
                    </form>
                    <form method="post" action="index.php?page=dashboard_pelanggan" style="flex:2;"
                          onsubmit="return confirm('Proses checkout pesanan ini?');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="aksi" value="checkout">
                        <button type="submit" class="button button-full"
                                <?= $cart === [] ? 'disabled' : '' ?>>Checkout</button>
                    </form>
                </div>
            </div>
        </aside>
    </div>
</section>

<section class="panel" style="margin-top:20px;">
    <div class="section-heading">
        <div>
            <h2>Pesanan Terakhir</h2>
            <p class="muted">10 transaksi terakhir milik Anda.</p>
        </div>
    </div>
    <?php if ($recentTransactions === []): ?>
        <div class="empty-state">
            <h3>Belum ada pesanan</h3>
            <p>Pesanan yang berhasil dicheckout akan tampil di sini.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>No. Pesanan</th>
                    <th>Tanggal</th>
                    <th>Total</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentTransactions as $transaction): ?>
                    <tr>
                        <td>#<?= e($transaction['id_transaksi']) ?></td>
                        <td><?= e(date('d M Y', strtotime((string) $transaction['tanggal']))) ?></td>
                        <td><?= e(formatRupiah($transaction['total_belanja'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
