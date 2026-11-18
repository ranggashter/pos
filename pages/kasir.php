<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/midtrans.php';

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
$percentageToBasisPoints = static function (mixed $value): int {
    $percentage = (string) $value;
    if (preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $percentage) !== 1) {
        throw new InvalidArgumentException('Persentase diskon pelanggan tidak valid.');
    }

    [$whole, $fraction] = array_pad(explode('.', $percentage, 2), 2, '');
    $basisPoints = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    if ($basisPoints > 10000) {
        throw new InvalidArgumentException('Persentase diskon pelanggan melebihi 100%.');
    }

    return $basisPoints;
};
$discountCentsFor = static function (int $amountCents, int $basisPoints): int {
    return intdiv($amountCents, 10000) * $basisPoints
        + intdiv(($amountCents % 10000) * $basisPoints + 5000, 10000);
};

if (!isset($_SESSION['kasir_cart']) || !is_array($_SESSION['kasir_cart'])) {
    $_SESSION['kasir_cart'] = [];
}
if (!isset($_SESSION['kasir_form']) || !is_array($_SESSION['kasir_form'])) {
    $_SESSION['kasir_form'] = [
        'id_pelanggan' => '',
        'tanggal' => date('Y-m-d'),
        'uang_diterima' => '',
        'metode_pembayaran' => 'tunai',
    ];
}
$cart = $_SESSION['kasir_cart'];
$formState = $_SESSION['kasir_form'];
$formState['id_pelanggan'] = is_scalar($formState['id_pelanggan'] ?? null)
    ? (string) $formState['id_pelanggan']
    : '';
$formState['tanggal'] = is_string($formState['tanggal'] ?? null)
    && $isValidDate($formState['tanggal'])
        ? $formState['tanggal']
        : date('Y-m-d');
$formState['uang_diterima'] = is_scalar($formState['uang_diterima'] ?? null)
    ? (string) $formState['uang_diterima']
    : '';
$formState['metode_pembayaran'] = in_array(
    $formState['metode_pembayaran'] ?? null,
    ['tunai', 'qris', 'member'],
    true
) ? $formState['metode_pembayaran'] : 'tunai';
$formErrors = [];
$barcodeValue = '';
$memberCardValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Sesi formulir tidak valid atau sudah berakhir. Silakan coba kembali.');
        redirectToMaster('kasir');
    }

    $action = isset($_POST['aksi']) && is_string($_POST['aksi']) ? $_POST['aksi'] : '';
    $postedCustomer = isset($_POST['id_pelanggan']) && is_string($_POST['id_pelanggan'])
        ? $_POST['id_pelanggan']
        : '';
    $postedDate = isset($_POST['tanggal']) && is_string($_POST['tanggal'])
        ? $_POST['tanggal']
        : '';
    $postedCash = isset($_POST['uang_diterima']) && is_string($_POST['uang_diterima'])
        ? trim($_POST['uang_diterima'])
        : '';
    $postedPaymentMethod = isset($_POST['metode_pembayaran'])
        && is_string($_POST['metode_pembayaran'])
            ? $_POST['metode_pembayaran']
            : '';
    $barcodeValue = isset($_POST['kode_barcode']) && is_string($_POST['kode_barcode'])
        ? trim($_POST['kode_barcode'])
        : '';
    $memberCardValue = isset($_POST['uid_member']) && is_string($_POST['uid_member'])
        ? trim($_POST['uid_member'])
        : '';
    $formState = [
        'id_pelanggan' => $postedCustomer,
        'tanggal' => $postedDate,
        'uang_diterima' => $postedCash,
        'metode_pembayaran' => $postedPaymentMethod,
    ];
    $_SESSION['kasir_form'] = $formState;

    if ($action === 'scan_member_rfid') {
        $memberUid = strtoupper($memberCardValue);
        $memberUid = preg_replace('/[\s:-]+/', '', $memberUid) ?? '';
        if (preg_match('/^[A-Z0-9]{4,64}$/D', $memberUid) !== 1) {
            $formErrors[] = 'UID kartu RFID tidak valid. Silakan scan ulang kartu member.';
        } else {
            try {
                $memberStatement = $pdo->prepare(
                    'SELECT id_pelanggan, nama_pelanggan
                     FROM tbl_pelanggan
                     WHERE rfid_uid = :uid
                     LIMIT 1'
                );
                $memberStatement->execute(['uid' => $memberUid]);
                $member = $memberStatement->fetch();
                if ($member === false) {
                    $formErrors[] = 'Kartu RFID belum terhubung ke data pelanggan.';
                } else {
                    $formState['id_pelanggan'] = (string) $member['id_pelanggan'];
                    $_SESSION['kasir_form'] = $formState;
                    setFlash('success', 'Kartu member terdeteksi: ' . $member['nama_pelanggan'] . '.');
                    redirectToMaster('kasir');
                }
            } catch (PDOException $exception) {
                error_log('Membaca kartu member RFID gagal: ' . $exception->getMessage());
                $formErrors[] = 'Kartu member gagal dibaca. Silakan coba kembali.';
            }
        }
    } elseif (str_starts_with($action, 'hapus:')) {
        $removeId = $getId(substr($action, 6));
        if ($removeId === null) {
            $formErrors[] = 'Produk pada keranjang tidak valid.';
        } else {
            unset($_SESSION['kasir_cart'][$removeId]);
            setFlash('info', 'Produk dihapus dari keranjang.');
            redirectToMaster('kasir');
        }
    } elseif (str_starts_with($action, 'kurangi:')) {
        // Kurangi 1 qty dari keranjang
        $reduceId = $getId(substr($action, 8));
        if ($reduceId === null) {
            $formErrors[] = 'Produk pada keranjang tidak valid.';
        } else {
            $current = (int) ($_SESSION['kasir_cart'][$reduceId] ?? 0);
            if ($current <= 1) {
                unset($_SESSION['kasir_cart'][$reduceId]);
            } else {
                $_SESSION['kasir_cart'][$reduceId] = $current - 1;
            }
            redirectToMaster('kasir');
        }
    } elseif ($action === 'kosongkan') {
        $_SESSION['kasir_cart'] = [];
        setFlash('info', 'Keranjang dikosongkan.');
        redirectToMaster('kasir');
    } elseif ($action === 'tambah') {
        $productId = $getId($_POST['id_produk'] ?? null);
        $quantity = filter_var(
            $_POST['jumlah'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000000]]
        );

        if ($productId === null) {
            $formErrors[] = 'Pilih produk yang valid.';
        }
        if ($quantity === false) {
            $formErrors[] = 'Jumlah harus bilangan bulat minimal 1.';
        }

        if ($formErrors === []) {
            try {
                $statement = $pdo->prepare(
                    'SELECT nama_produk, harga, stok
                     FROM tbl_produk
                     WHERE id_produk = :id AND status_aktif = 1'
                );
                $statement->execute(['id' => $productId]);
                $product = $statement->fetch();
                if ($product === false) {
                    $formErrors[] = 'Produk tidak ditemukan atau sedang nonaktif.';
                } else {
                    $newQuantity = (int) ($_SESSION['kasir_cart'][$productId] ?? 0) + $quantity;
                    if ((float) $product['stok'] < $newQuantity) {
                        $formErrors[] = 'Stok ' . $product['nama_produk']
                            . ' tidak mencukupi. Stok tersedia: '
                            . rtrim(rtrim(number_format((float) $product['stok'], 3, ',', '.'), '0'), ',')
                            . '.';
                    } elseif (decimalToCents($product['harga']) > 0
                        && $newQuantity > intdiv(999999999999, decimalToCents($product['harga']))) {
                        $formErrors[] = 'Subtotal produk melebihi batas nilai transaksi.';
                    } else {
                        $_SESSION['kasir_cart'][$productId] = $newQuantity;
                        setFlash('success', 'Produk ditambahkan ke keranjang.');
                        redirectToMaster('kasir');
                    }
                }
            } catch (PDOException $exception) {
                error_log('Tambah produk ke keranjang gagal: ' . $exception->getMessage());
                $formErrors[] = 'Produk gagal ditambahkan ke keranjang.';
            } catch (InvalidArgumentException $exception) {
                $formErrors[] = 'Harga produk tidak valid untuk transaksi.';
            }
        }
    } elseif ($action === 'tambah_cepat') {
        // Klik kartu produk di POS grid
        $productId = $getId($_POST['id_produk'] ?? null);
        if ($productId === null) {
            $formErrors[] = 'Produk tidak valid.';
        } else {
            try {
                $statement = $pdo->prepare(
                    'SELECT nama_produk, harga, stok
                     FROM tbl_produk
                     WHERE id_produk = :id AND status_aktif = 1'
                );
                $statement->execute(['id' => $productId]);
                $product = $statement->fetch();
                if ($product === false) {
                    $formErrors[] = 'Produk tidak ditemukan atau sedang nonaktif.';
                } else {
                    $newQuantity = (int) ($_SESSION['kasir_cart'][$productId] ?? 0) + 1;
                    if ((float) $product['stok'] < $newQuantity) {
                        $formErrors[] = 'Stok ' . $product['nama_produk'] . ' tidak mencukupi.';
                    } else {
                        $_SESSION['kasir_cart'][$productId] = $newQuantity;
                        redirectToMaster('kasir');
                    }
                }
            } catch (PDOException $exception) {
                error_log('Tambah cepat gagal: ' . $exception->getMessage());
                $formErrors[] = 'Produk gagal ditambahkan.';
            }
        }
    } elseif ($action === 'scan_barcode') {
        if ($barcodeValue === '' || strlen($barcodeValue) > 50) {
            $formErrors[] = 'Barcode wajib diisi dan maksimal 50 karakter.';
        } else {
            try {
                $statement = $pdo->prepare(
                    'SELECT id_produk, nama_produk, harga, stok
                     FROM tbl_produk
                     WHERE kode_produk = :kode AND status_aktif = 1'
                );
                $statement->execute(['kode' => $barcodeValue]);
                $product = $statement->fetch();
                if ($product === false) {
                    $formErrors[] = 'Barcode tidak ditemukan pada produk aktif.';
                } else {
                    $productId = (int) $product['id_produk'];
                    $currentQuantity = (int) ($_SESSION['kasir_cart'][$productId] ?? 0);
                    if ($currentQuantity >= 1000000) {
                        $formErrors[] = 'Jumlah produk sudah mencapai batas maksimum.';
                    } else {
                        $newQuantity = $currentQuantity + 1;
                        if ((float) $product['stok'] < $newQuantity) {
                            $formErrors[] = 'Stok ' . $product['nama_produk']
                                . ' tidak mencukupi. Stok tersedia: '
                                . rtrim(rtrim(number_format((float) $product['stok'], 3, ',', '.'), '0'), ',')
                                . '.';
                        } else {
                            $unitCents = decimalToCents($product['harga']);
                            if ($unitCents > 0 && $newQuantity > intdiv(999999999999, $unitCents)) {
                                $formErrors[] = 'Subtotal produk melebihi batas nilai transaksi.';
                            } else {
                                $_SESSION['kasir_cart'][$productId] = $newQuantity;
                                setFlash('success', $product['nama_produk'] . ' ditambahkan melalui barcode.');
                                redirectToMaster('kasir');
                            }
                        }
                    }
                }
            } catch (PDOException $exception) {
                error_log('Pemindaian barcode produk gagal: ' . $exception->getMessage());
                $formErrors[] = 'Produk gagal dicari berdasarkan barcode.';
            } catch (InvalidArgumentException $exception) {
                $formErrors[] = 'Harga produk tidak valid untuk transaksi.';
            }
        }
    } elseif ($action === 'simpan') {
        $customerId = $postedCustomer === '' ? null : $getId($postedCustomer);
        $paymentMethod = $postedPaymentMethod;
        $cashCents = 0;
        $cashDigits = null;
        if ($postedCustomer !== '' && $customerId === null) {
            $formErrors[] = 'Pelanggan yang dipilih tidak valid.';
        }
        if (!in_array($paymentMethod, ['tunai', 'qris', 'member'], true)) {
            $formErrors[] = 'Pilih metode pembayaran yang valid.';
        }
        if ($paymentMethod === 'tunai') {
            $cashDigits = preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+)$/D', $postedCash) === 1
                ? str_replace('.', '', $postedCash)
                : null;
            if ($cashDigits === null) {
                $formErrors[] = 'Masukkan uang tunai dalam angka Rupiah yang valid.';
            } else {
                try {
                    $cashCents = decimalToCents($cashDigits);
                    if ($cashCents > 999999999999) {
                        $formErrors[] = 'Uang tunai melebihi batas nilai transaksi.';
                    }
                } catch (InvalidArgumentException $exception) {
                    $formErrors[] = 'Uang tunai melebihi batas nilai transaksi.';
                }
            }
        }
        if ($paymentMethod === 'qris') {
            try {
                midtransServerKey();
            } catch (RuntimeException $exception) {
                $formErrors[] = 'Pembayaran QRIS belum dikonfigurasi oleh administrator.';
            }
        }
        if (!$isValidDate($postedDate)) {
            $formErrors[] = 'Tanggal transaksi tidak valid.';
        }
        if ($_SESSION['kasir_cart'] === []) {
            $formErrors[] = 'Keranjang masih kosong.';
        }

        if ($formErrors === []) {
            $maximumTotalCents = 999999999999;
            $items = [];
            $totalCents = 0;
            $grossTotalCents = 0;
            $discountBasisPoints = 0;
            $customerBalanceCents = 0;
            $balanceUsedCents = 0;
            $transactionId = null;
            $qrisOrderId = null;
            $midtransOrderId = null;
            try {
                $pdo->beginTransaction();

                if ($customerId !== null) {
                    $customerStatement = $pdo->prepare(
                        'SELECT id_pelanggan, diskon_persen, saldo
                         FROM tbl_pelanggan
                         WHERE id_pelanggan = :id
                         FOR UPDATE'
                    );
                    $customerStatement->execute(['id' => $customerId]);
                    $selectedCustomer = $customerStatement->fetch();
                    if ($selectedCustomer === false) {
                        throw new DomainException('Pelanggan yang dipilih tidak ditemukan.');
                    }
                    $discountBasisPoints = $percentageToBasisPoints(
                        $selectedCustomer['diskon_persen']
                    );
                    $customerBalanceCents = decimalToCents($selectedCustomer['saldo']);
                }

                $cartItems = $_SESSION['kasir_cart'];
                ksort($cartItems, SORT_NUMERIC);
                $productStatement = $pdo->prepare(
                    'SELECT id_produk, nama_produk, harga, stok, satuan
                     FROM tbl_produk
                     WHERE id_produk = :id AND status_aktif = 1
                     FOR UPDATE'
                );
                $totalStatement = $pdo->prepare('SELECT HitungTotal(:harga, :jumlah)');

                foreach ($cartItems as $rawProductId => $rawQuantity) {
                    $itemProductId = $getId($rawProductId);
                    $itemQuantity = filter_var(
                        $rawQuantity,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => 1000000]]
                    );
                    if ($itemProductId === null || $itemQuantity === false) {
                        throw new DomainException('Data keranjang tidak valid. Hapus item lalu tambahkan kembali.');
                    }

                    $productStatement->execute(['id' => $itemProductId]);
                    $product = $productStatement->fetch();
                    if ($product === false) {
                        throw new DomainException('Salah satu produk tidak tersedia atau sudah nonaktif.');
                    }
                    if ((float) $product['stok'] < $itemQuantity) {
                        throw new DomainException('Stok produk "' . $product['nama_produk'] . '" tidak mencukupi.');
                    }

                    $unitCents = decimalToCents($product['harga']);
                    if ($unitCents > 0 && $itemQuantity > intdiv($maximumTotalCents, $unitCents)) {
                        throw new DomainException('Total transaksi melebihi batas nilai yang dapat disimpan.');
                    }

                    $totalStatement->execute([
                        'harga' => $product['harga'],
                        'jumlah' => $itemQuantity,
                    ]);
                    $subtotal = $totalStatement->fetchColumn();
                    $totalStatement->closeCursor();
                    $subtotalCents = decimalToCents($subtotal);
                    if ($subtotalCents > $maximumTotalCents - $grossTotalCents) {
                        throw new DomainException('Total transaksi melebihi batas nilai yang dapat disimpan.');
                    }

                    $grossTotalCents += $subtotalCents;
                    $itemDiscountCents = $discountCentsFor(
                        $subtotalCents,
                        $discountBasisPoints
                    );
                    $payableSubtotalCents = $subtotalCents - $itemDiscountCents;
                    if ($payableSubtotalCents > $maximumTotalCents - $totalCents) {
                        throw new DomainException('Total transaksi melebihi batas nilai yang dapat disimpan.');
                    }

                    $totalCents += $payableSubtotalCents;
                    $items[] = [
                        'id_produk' => $itemProductId,
                        'jumlah' => $itemQuantity,
                        'harga_satuan' => (string) $product['harga'],
                        'diskon' => centsToDecimal($itemDiscountCents),
                        'subtotal' => centsToDecimal($payableSubtotalCents),
                    ];
                }

                if ($paymentMethod === 'tunai' && $cashCents < $totalCents) {
                    throw new DomainException('Uang tunai diterima kurang dari total pembayaran.');
                }
                if ($paymentMethod === 'member') {
                    if ($customerId === null) {
                        throw new DomainException('Pilih pelanggan untuk membayar dengan saldo member.');
                    }
                    if ($customerBalanceCents < $totalCents) {
                        throw new DomainException('Saldo member tidak mencukupi untuk membayar seluruh transaksi.');
                    }
                    $balanceUsedCents = $totalCents;
                }
                if ($paymentMethod === 'qris') {
                    if ($totalCents <= 0 || $totalCents % 100 !== 0) {
                        throw new DomainException('Total QRIS harus minimal Rp1 dan tanpa pecahan Rupiah.');
                    }
                    try {
                        $midtransOrderId = 'TK-' . date('YmdHis') . '-' . bin2hex(random_bytes(6));
                    } catch (Exception $exception) {
                        error_log('Membuat ID order QRIS gagal: ' . $exception->getMessage());
                        throw new DomainException('ID pembayaran QRIS tidak dapat dibuat.');
                    }
                }

                if ($paymentMethod === 'member' && $balanceUsedCents > 0) {
                    $balanceStatement = $pdo->prepare(
                        'UPDATE tbl_pelanggan
                         SET saldo = saldo - :saldo_digunakan
                         WHERE id_pelanggan = :id AND saldo >= :saldo_minimum'
                    );
                    $balanceStatement->execute([
                        'saldo_digunakan' => centsToDecimal($balanceUsedCents),
                        'id' => $customerId,
                        'saldo_minimum' => centsToDecimal($balanceUsedCents),
                    ]);
                    if ($balanceStatement->rowCount() !== 1) {
                        throw new DomainException('Saldo pelanggan berubah saat transaksi diproses.');
                    }
                }

                if ($paymentMethod === 'qris') {
                    $qrisOrderStatement = $pdo->prepare(
                        'INSERT INTO tbl_qris_order
                            (midtrans_order_id, tanggal, id_pelanggan, total_belanja)
                         VALUES (:order_id, :tanggal, :id_pelanggan, :total)'
                    );
                    $qrisOrderStatement->bindValue(':order_id', $midtransOrderId, PDO::PARAM_STR);
                    $qrisOrderStatement->bindValue(':tanggal', $postedDate, PDO::PARAM_STR);
                    $qrisOrderStatement->bindValue(
                        ':id_pelanggan',
                        $customerId,
                        $customerId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
                    );
                    $qrisOrderStatement->bindValue(':total', centsToDecimal($totalCents), PDO::PARAM_STR);
                    $qrisOrderStatement->execute();
                    $qrisOrderId = (int) $pdo->lastInsertId();
                } else {
                    $transactionStatement = $pdo->prepare(
                        'INSERT INTO tbl_transaksi
                            (tanggal, id_pelanggan, total_belanja, uang_diterima, saldo_digunakan,
                             metode_pembayaran, status_pembayaran)
                         VALUES (:tanggal, :id_pelanggan, :total, :uang_diterima, :saldo_digunakan,
                                 :metode_pembayaran, :status_pembayaran)'
                    );
                    $transactionStatement->bindValue(':tanggal', $postedDate, PDO::PARAM_STR);
                    $transactionStatement->bindValue(
                        ':id_pelanggan',
                        $customerId,
                        $customerId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
                    );
                    $transactionStatement->bindValue(':total', centsToDecimal($totalCents), PDO::PARAM_STR);
                    $transactionStatement->bindValue(
                        ':uang_diterima',
                        $paymentMethod === 'tunai' ? centsToDecimal($cashCents) : null,
                        $paymentMethod === 'tunai' ? PDO::PARAM_STR : PDO::PARAM_NULL
                    );
                    $transactionStatement->bindValue(
                        ':saldo_digunakan',
                        centsToDecimal($balanceUsedCents),
                        PDO::PARAM_STR
                    );
                    $transactionStatement->bindValue(':metode_pembayaran', $paymentMethod, PDO::PARAM_STR);
                    $transactionStatement->bindValue(':status_pembayaran', 'paid', PDO::PARAM_STR);
                    $transactionStatement->execute();
                    $transactionId = (int) $pdo->lastInsertId();
                }

                $stockStatement = $pdo->prepare(
                    'UPDATE tbl_produk
                     SET stok = stok - :jumlah
                     WHERE id_produk = :id AND stok >= :stok_minimum'
                );
                $detailStatement = $paymentMethod === 'qris'
                    ? $pdo->prepare(
                        'INSERT INTO tbl_qris_order_detail
                            (id_qris_order, id_produk, jumlah, harga_satuan, diskon, subtotal)
                         VALUES
                            (:id_order, :id_produk, :jumlah, :harga_satuan, :diskon, :subtotal)'
                    )
                    : $pdo->prepare(
                    'INSERT INTO tbl_detail_transaksi
                        (id_transaksi, id_produk, jumlah, harga_satuan, diskon, subtotal)
                     VALUES
                        (:id_transaksi, :id_produk, :jumlah, :harga_satuan, :diskon, :subtotal)'
                    );

                foreach ($items as $item) {
                    $stockStatement->execute([
                        'jumlah' => $item['jumlah'],
                        'id' => $item['id_produk'],
                        'stok_minimum' => $item['jumlah'],
                    ]);
                    if ($stockStatement->rowCount() !== 1) {
                        throw new DomainException('Stok berubah saat transaksi diproses. Silakan coba kembali.');
                    }

                    $detailParameters = [
                        'id_produk' => $item['id_produk'],
                        'jumlah' => $item['jumlah'],
                        'harga_satuan' => $item['harga_satuan'],
                        'diskon' => $item['diskon'],
                        'subtotal' => $item['subtotal'],
                    ];
                    if ($paymentMethod === 'qris') {
                        $detailParameters['id_order'] = $qrisOrderId;
                    } else {
                        $detailParameters['id_transaksi'] = $transactionId;
                    }
                    $detailStatement->execute($detailParameters);
                }

                $pdo->commit();
            } catch (DomainException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $formErrors[] = $exception->getMessage();
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Simpan transaksi kasir gagal: ' . $exception->getMessage());
                if ($exception->getCode() === '45000'
                    || str_contains($exception->getMessage(), 'Total belanja tidak boleh negatif')) {
                    $formErrors[] = 'Transaksi ditolak karena total belanja tidak boleh negatif.';
                } else {
                    $formErrors[] = 'Transaksi gagal disimpan. Tidak ada perubahan data yang diterapkan.';
                }
            } catch (InvalidArgumentException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $formErrors[] = 'Nilai produk atau total transaksi tidak valid.';
            }

            if ($formErrors === [] && $paymentMethod === 'qris'
                && $qrisOrderId !== null && $midtransOrderId !== null) {
                try {
                    $midtransResponse = midtransCreateQrisPayment(
                        $midtransOrderId,
                        intdiv($totalCents, 100)
                    );
                    $qrCodeStatement = $pdo->prepare(
                        'UPDATE tbl_qris_order
                         SET midtrans_qr_url = :qr_url,
                             midtrans_transaction_id = :transaction_id
                         WHERE id_qris_order = :id_qris_order'
                    );
                    $qrCodeStatement->execute([
                        'qr_url' => $midtransResponse['qr_code_url'],
                        'transaction_id' => $midtransResponse['transaction_id'],
                        'id_qris_order' => $qrisOrderId,
                    ]);
                } catch (RuntimeException $exception) {
                    error_log('Membuat pembayaran QRIS gagal: ' . $exception->getMessage());
                    try {
                        midtransReleasePendingOrder($pdo, $midtransOrderId, 'failed');
                    } catch (PDOException $releaseException) {
                        error_log('Melepas stok pembayaran QRIS gagal: ' . $releaseException->getMessage());
                        $formErrors[] = 'QRIS gagal dibuat dan stok belum dapat dikembalikan. Hubungi administrator.';
                    }
                    if ($formErrors === []) {
                        try {
                            $statusStatement = $pdo->prepare(
                                'SELECT status_pembayaran
                                 FROM tbl_qris_order
                                 WHERE id_qris_order = :id_qris_order'
                            );
                            $statusStatement->execute(['id_qris_order' => $qrisOrderId]);
                            $qrisStatus = $statusStatement->fetchColumn();
                            if (!in_array($qrisStatus, ['paid', 'paid_unfulfilled'], true)) {
                                $formErrors[] = $exception->getMessage();
                            }
                        } catch (PDOException $statusException) {
                            error_log('Membaca status QRIS setelah error gagal: ' . $statusException->getMessage());
                            $formErrors[] = 'Status QRIS tidak dapat diperiksa. Hubungi administrator.';
                        }
                    }
                } catch (PDOException $exception) {
                    error_log('Menyimpan gambar QRIS gagal: ' . $exception->getMessage());
                    $formErrors[] = 'QRIS sudah dibuat tetapi gambar pembayaran gagal disimpan. Hubungi administrator.';
                }
            }

            if ($formErrors === []
                && (($paymentMethod === 'qris' && $qrisOrderId !== null)
                    || ($paymentMethod !== 'qris' && $transactionId !== null))) {
                $_SESSION['kasir_cart'] = [];
                $_SESSION['kasir_form'] = [
                    'id_pelanggan' => '',
                    'tanggal' => date('Y-m-d'),
                    'uang_diterima' => '',
                    'metode_pembayaran' => 'tunai',
                ];
                if ($paymentMethod === 'qris') {
                    header('Location: index.php?page=kasir_qris&id=' . $qrisOrderId);
                    exit;
                }

                $paymentMethodLabel = $paymentMethod === 'member' ? 'saldo member' : 'tunai';
                setFlash('success', 'Transaksi berhasil dibayar dengan ' . $paymentMethodLabel . '.');
                header('Location: index.php?page=struk&id=' . $transactionId);
                exit;
            }
        }
    } else {
        $formErrors[] = 'Aksi kasir tidak dikenal.';
    }

    $cart = $_SESSION['kasir_cart'];
}

$customers = [];
$products = [];
$categories = [];
$cartRows = [];
$cartTotalCents = 0;
$cartDiscountCents = 0;
$cartPayableCents = 0;
$selectedCustomerDiscountBasisPoints = 0;
$selectedCustomerBalanceCents = 0;
$cartBalanceUsedCents = 0;
$cartPaymentDueCents = 0;
$cartMemberBalanceAfterCents = 0;
$cartTotalExceeded = false;
$readError = null;

try {
    $customers = $pdo->query(
        'SELECT id_pelanggan, nama_pelanggan, kota, diskon_persen, saldo
         FROM tbl_pelanggan
         ORDER BY nama_pelanggan, id_pelanggan'
    )->fetchAll();
    foreach ($customers as $customer) {
        if ($formState['id_pelanggan'] === (string) $customer['id_pelanggan']) {
            $selectedCustomerDiscountBasisPoints = $percentageToBasisPoints(
                $customer['diskon_persen']
            );
            $selectedCustomerBalanceCents = decimalToCents($customer['saldo']);
            break;
        }
    }

    $products = $pdo->query(
        'SELECT p.id_produk, p.nama_produk, p.kode_produk, p.harga, p.stok, p.satuan,
                p.id_kategori, k.nama_kategori
         FROM tbl_produk p
         LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori
         WHERE p.status_aktif = 1
         ORDER BY p.nama_produk, p.id_produk'
    )->fetchAll();

    foreach ($products as $p) {
        if (!empty($p['nama_kategori']) && !in_array($p['nama_kategori'], $categories, true)) {
            $categories[] = $p['nama_kategori'];
        }
    }
    sort($categories);

    if ($cart !== []) {
        $cartProductStatement = $pdo->prepare(
            'SELECT id_produk, nama_produk, harga, stok, satuan, status_aktif
             FROM tbl_produk
             WHERE id_produk = :id'
        );
        $subtotalStatement = $pdo->prepare('SELECT HitungTotal(:harga, :jumlah)');
        foreach ($cart as $rawId => $rawQuantity) {
            $productId = $getId($rawId);
            if ($productId === null) {
                continue;
            }

            $cartProductStatement->execute(['id' => $productId]);
            $product = $cartProductStatement->fetch();
            if ($product === false) {
                $cartRows[] = [
                    'id_produk' => $productId,
                    'nama_produk' => 'Produk tidak ditemukan',
                    'jumlah' => (int) $rawQuantity,
                    'harga_satuan' => null,
                    'subtotal' => null,
                    'stok' => '0.000',
                    'satuan' => '',
                    'tersedia' => false,
                ];
                continue;
            }

            $subtotalStatement->execute([
                'harga' => $product['harga'],
                'jumlah' => (int) $rawQuantity,
            ]);
            $subtotal = $subtotalStatement->fetchColumn();
            $subtotalStatement->closeCursor();
            $subtotalCents = decimalToCents($subtotal);
            if ($subtotalCents > 999999999999 - $cartTotalCents) {
                $cartTotalExceeded = true;
            } elseif (!$cartTotalExceeded) {
                $cartTotalCents += $subtotalCents;
            }
            $cartRows[] = [
                'id_produk' => $productId,
                'nama_produk' => $product['nama_produk'],
                'jumlah' => (int) $rawQuantity,
                'harga_satuan' => $product['harga'],
                'subtotal' => $subtotal,
                'stok' => $product['stok'],
                'satuan' => $product['satuan'],
                'tersedia' => (int) $product['status_aktif'] === 1,
            ];
        }
    }

    foreach ($cartRows as $row) {
        if ($row['subtotal'] !== null) {
            $cartDiscountCents += $discountCentsFor(
                decimalToCents($row['subtotal']),
                $selectedCustomerDiscountBasisPoints
            );
        }
    }
    $cartPayableCents = max(0, $cartTotalCents - $cartDiscountCents);
    if ($formState['metode_pembayaran'] === 'member') {
        $cartBalanceUsedCents = min($selectedCustomerBalanceCents, $cartPayableCents);
    }
    $cartPaymentDueCents = $formState['metode_pembayaran'] === 'member'
        ? 0
        : $cartPayableCents;
    $cartMemberBalanceAfterCents = max(
        0,
        $selectedCustomerBalanceCents - $cartBalanceUsedCents
    );
} catch (PDOException $exception) {
    error_log('Memuat halaman kasir gagal: ' . $exception->getMessage());
    $readError = 'Data pelanggan atau produk gagal dibaca dari database.';
} catch (InvalidArgumentException $exception) {
    error_log('Menghitung total keranjang gagal: ' . $exception->getMessage());
    $readError = 'Total keranjang tidak dapat dihitung. Periksa harga produk.';
}

$hasInvalidCartItem = false;
foreach ($cartRows as $row) {
    if (!$row['tersedia'] || $row['harga_satuan'] === null) {
        $hasInvalidCartItem = true;
        break;
    }
}

?>

<?php if ($readError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($readError) ?></div>
<?php endif; ?>
<?php if ($formErrors !== []): ?>
    <div class="alert alert-error" role="alert">
        <strong>Transaksi belum diproses:</strong>
        <ul><?php foreach ($formErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" action="index.php?page=kasir" class="pos-container" id="pos-main-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="id_produk" id="pos_id_produk" value="">

    <!-- ============================================================ -->
    <!-- KOLOM KIRI: Katalog Produk, Search, & Kategori               -->
    <!-- ============================================================ -->
    <div class="pos-catalog">
        <!-- Bar Info Transaksi: Pelanggan & Tanggal -->
        <div class="pos-info-bar">
            <div>
                <label for="id_pelanggan">Pelanggan (opsional)</label>
                <select id="id_pelanggan" name="id_pelanggan">
                    <option value="">Tanpa pelanggan</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= e($customer['id_pelanggan']) ?>"
                            data-discount-basis-points="<?= e($percentageToBasisPoints($customer['diskon_persen'])) ?>"
                            data-balance-cents="<?= e(decimalToCents($customer['saldo'])) ?>"
                            <?= $formState['id_pelanggan'] === (string) $customer['id_pelanggan'] ? 'selected' : '' ?>>
                            <?= e($customer['nama_pelanggan'] ?: 'Pelanggan') ?>
                            (<?= e($customer['kota'] ?? '-') ?> · diskon <?= e(number_format((float) $customer['diskon_persen'], 2, ',', '.')) ?>% · saldo <?= e(formatRupiah($customer['saldo'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="metode_pembayaran">Metode Pembayaran</label>
                <select id="metode_pembayaran" name="metode_pembayaran">
                    <option value="tunai" <?= $formState['metode_pembayaran'] === 'tunai' ? 'selected' : '' ?>>Tunai</option>
                    <option value="qris" <?= $formState['metode_pembayaran'] === 'qris' ? 'selected' : '' ?>>QRIS (Midtrans)</option>
                    <option value="member" <?= $formState['metode_pembayaran'] === 'member' ? 'selected' : '' ?>>Saldo Member</option>
                </select>
            </div>
            <div>
                <label for="tanggal">Tanggal Transaksi</label>
                <input id="tanggal" name="tanggal" type="date" required value="<?= e($formState['tanggal']) ?>">
            </div>
            <div style="grid-column:1 / -1;">
                <label for="member-rfid-input">Pilih pelanggan dengan kartu member RFID</label>
                <div class="pos-barcode-entry">
                    <input type="text" id="member-rfid-input" name="uid_member"
                           maxlength="64" autocomplete="off" autocapitalize="characters"
                           spellcheck="false" placeholder="Arahkan kartu ke reader RFID"
                           value="<?= e($memberCardValue) ?>">
                    <button class="button button-secondary" id="member-rfid-submit"
                            type="submit" name="aksi" value="scan_member_rfid" formnovalidate>
                        Baca Kartu Member
                    </button>
                </div>
                <p class="input-hint">Pastikan UID kartu sudah didaftarkan pada data pelanggan.</p>
            </div>
        </div>

        <!-- Search Bar & Kategori Tabs -->
        <div class="pos-toolbar">
            <div class="pos-barcode-scan">
                <div class="pos-barcode-field">
                    <label for="pos-barcode-input">Scan barcode produk</label>
                    <div class="pos-barcode-entry">
                        <input type="text" id="pos-barcode-input" name="kode_barcode"
                           maxlength="128" autocomplete="off" autocapitalize="off"
                               spellcheck="false" placeholder="Arahkan scanner ke barcode"
                               value="<?= e($barcodeValue) ?>">
                        <button class="button" id="pos-barcode-submit" type="submit"
                                name="aksi" value="scan_barcode" formnovalidate>
                            Tambah
                        </button>
                    </div>
                    <p class="input-hint">Barcode akan ditambahkan otomatis setelah pemindaian selesai.</p>
                </div>
            </div>

            <div class="pos-search-wrapper">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="text" id="pos-search" class="pos-search-input"
                       placeholder="Cari nama atau kode produk..." autocomplete="off">
                <span class="pos-search-kbd">/</span>
            </div>

            <?php if ($categories !== []): ?>
                <div class="pos-category-chips" id="pos-category-chips">
                    <button type="button" class="pos-chip active" data-category="all">
                        Semua (<?= count($products) ?>)
                    </button>
                    <?php foreach ($categories as $catName): ?>
                        <button type="button" class="pos-chip" data-category="<?= e(mb_strtolower($catName, 'UTF-8')) ?>">
                            <?= e($catName) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Grid Produk POS -->
        <?php if ($products === []): ?>
            <div class="panel">
                <p class="empty-state">Tidak ada produk aktif di toko.</p>
            </div>
        <?php else: ?>
            <div class="pos-grid" id="pos-grid">
                <?php foreach ($products as $product): ?>
                    <?php
                    $stokNum = (float) $product['stok'];
                    $stokHabis = $stokNum < 1;
                    $stokMenipis = !$stokHabis && $stokNum <= 5;
                    $qtyInCart = (int) ($cart[(string) $product['id_produk']] ?? 0);
                    $catClass = mb_strtolower((string) ($product['nama_kategori'] ?? ''), 'UTF-8');
                    $searchKey = mb_strtolower(($product['nama_produk'] ?? '') . ' ' . ($product['kode_produk'] ?? ''), 'UTF-8');
                    ?>
                    <button type="submit"
                            name="aksi" value="tambah_cepat"
                            formnovalidate
                            class="pos-card<?= $stokHabis ? ' pos-card-disabled' : '' ?>"
                            data-search="<?= e($searchKey) ?>"
                            data-category="<?= e($catClass) ?>"
                            <?= $stokHabis ? 'disabled' : '' ?>
                            onclick="document.getElementById('pos_id_produk').value='<?= e($product['id_produk']) ?>';"
                            title="<?= $stokHabis ? 'Stok habis' : 'Klik untuk tambah ke keranjang' ?>">
                        
                        <?php if ($qtyInCart > 0): ?>
                            <span class="pos-card-badge">×<?= e($qtyInCart) ?></span>
                        <?php endif; ?>

                        <span class="pos-card-name"><?= e($product['nama_produk']) ?></span>
                        <?php if ($product['kode_produk'] !== null): ?>
                            <span class="pos-card-code"><?= e($product['kode_produk']) ?></span>
                        <?php endif; ?>

                        <div class="pos-card-bottom">
                            <div>
                                <span class="pos-card-price"><?= e(formatRupiah($product['harga'])) ?></span>
                            </div>
                            <span class="stock-pill <?= $stokHabis ? 'stock-empty' : ($stokMenipis ? 'stock-low' : 'stock-safe') ?>">
                                <?= $stokHabis ? 'Habis' : e(rtrim(rtrim(number_format($stokNum, 3, ',', '.'), '0'), ',')) . ' ' . e($product['satuan']) ?>
                            </span>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Form Tambah Produk Manual (Collapsible) -->
        <details class="pos-manual-details">
            <summary>Tambah Produk Manual / Jumlah Khusus</summary>
            <div class="pos-manual-content">
                <div class="add-product-fields" style="display:grid;grid-template-columns:minmax(0,2fr) minmax(100px,1fr) auto;gap:12px;align-items:end;">
                    <div>
                        <label for="id_produk_manual">Pilih Produk</label>
                        <select id="id_produk_manual" onchange="document.getElementById('pos_id_produk').value = this.value;">
                            <option value="">Pilih produk...</option>
                            <?php foreach ($products as $product): ?>
                                <?php $stokHabis = (float) $product['stok'] < 1; ?>
                                <option value="<?= e($product['id_produk']) ?>" <?= $stokHabis ? 'disabled' : '' ?>>
                                    <?= e($product['nama_produk']) ?>
                                    · <?= e(formatRupiah($product['harga'])) ?>
                                    (stok: <?= e(rtrim(rtrim(number_format((float) $product['stok'], 3, ',', '.'), '0'), ',')) ?>)
                                    <?= $stokHabis ? ' [HABIS]' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="jumlah">Jumlah</label>
                        <input id="jumlah" name="jumlah" type="number" min="1" max="1000000" step="1" value="1">
                    </div>
                    <div>
                        <button class="button button-secondary" type="submit" name="aksi" value="tambah" formnovalidate
                                onclick="var sel = document.getElementById('id_produk_manual'); if (sel) { document.getElementById('pos_id_produk').value = sel.value; }">
                            Tambah Manual
                        </button>
                    </div>
                </div>
            </div>
        </details>
    </div>

    <!-- ============================================================ -->
    <!-- KOLOM KANAN: Keranjang & Terminal Kasir (Sticky)             -->
    <!-- ============================================================ -->
    <div class="pos-cart-panel">
        <div class="pos-cart-header">
            <h2 class="pos-cart-title">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
                <span>Keranjang</span>
            </h2>
            <span class="pos-cart-count"><?= count($cartRows) ?></span>
        </div>

        <!-- Daftar Item Keranjang -->
        <?php if ($cartRows === []): ?>
            <div class="pos-cart-empty">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                <p style="font-weight:600;margin-bottom:4px;color:var(--text-heading);">Keranjang Masih Kosong</p>
                <p style="font-size:0.84rem;margin:0;color:var(--text-muted);">Klik produk di sebelah kiri untuk menambah belanjaan.</p>
            </div>
        <?php else: ?>
            <div class="pos-cart-items">
                <?php foreach ($cartRows as $item): ?>
                    <div class="pos-cart-item"
                         data-subtotal-cents="<?= e($item['subtotal'] === null ? 0 : decimalToCents($item['subtotal'])) ?>">
                        <div class="pos-item-meta">
                            <span class="pos-item-name"><?= e($item['nama_produk']) ?></span>
                            <span class="pos-item-price"><?= $item['harga_satuan'] === null ? '—' : e(formatRupiah($item['harga_satuan'])) ?> / <?= e($item['satuan']) ?></span>
                        </div>

                        <div class="qty-stepper">
                            <button class="qty-step-btn" type="submit" name="aksi"
                                    value="kurangi:<?= e($item['id_produk']) ?>"
                                    formnovalidate title="Kurangi 1">−</button>
                            <span class="qty-value-label"><?= e($item['jumlah']) ?></span>
                            <button class="qty-step-btn" type="submit" name="aksi"
                                    value="tambah_cepat"
                                    formnovalidate title="Tambah 1"
                                    onclick="document.getElementById('pos_id_produk').value='<?= e($item['id_produk']) ?>';">+</button>
                        </div>

                        <div class="pos-item-subtotal">
                            <?= $item['subtotal'] === null ? '—' : e(formatRupiah($item['subtotal'])) ?>
                        </div>

                        <button class="pos-item-remove" type="submit" name="aksi"
                                value="hapus:<?= e($item['id_produk']) ?>"
                                formnovalidate title="Hapus produk ini">
                            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Ringkasan & Kalkulator Kembalian Kasir -->
            <div class="pos-cart-footer">
                <div class="pos-total-row">
                    <span class="pos-total-label">
                        Diskon Pelanggan (<span id="pos-discount-rate"><?= e(number_format($selectedCustomerDiscountBasisPoints / 100, 2, ',', '.')) ?></span>%)
                    </span>
                    <span class="pos-total-amount" id="pos-discount-amount">
                        <?= e(formatRupiah(centsToDecimal($cartDiscountCents))) ?>
                    </span>
                </div>
                <div class="pos-total-row">
                    <span class="pos-total-label">Grand Total</span>
                    <span class="pos-total-amount" id="pos-grand-total">
                        <?= $cartTotalExceeded ? 'Melebihi Batas' : e(formatRupiah(centsToDecimal($cartPayableCents))) ?>
                    </span>
                </div>
                <div class="pos-total-row">
                    <span class="pos-total-label" id="pos-payment-balance-label">Saldo member digunakan</span>
                    <span class="pos-total-amount" id="pos-balance-used">
                        <?= e(formatRupiah(centsToDecimal($cartBalanceUsedCents))) ?>
                    </span>
                </div>
                <div class="pos-total-row">
                    <span class="pos-total-label" id="pos-payment-due-label">
                        <?= $formState['metode_pembayaran'] === 'qris'
                            ? 'Dibayar via QRIS'
                            : ($formState['metode_pembayaran'] === 'member' ? 'Saldo tersisa setelah transaksi' : 'Dibayar tunai') ?>
                    </span>
                    <span class="pos-total-amount" id="pos-payment-due">
                        <?= e(formatRupiah(centsToDecimal(
                            $formState['metode_pembayaran'] === 'member'
                                ? $cartMemberBalanceAfterCents
                                : $cartPaymentDueCents
                        ))) ?>
                    </span>
                </div>

                <!-- Kalkulator Kembalian Kasir -->
                <div class="pos-calc-box"
                     data-total-cents="<?= e($cartPayableCents) ?>"
                     data-payment-due-cents="<?= e($cartPaymentDueCents) ?>"
                     data-gross-total-cents="<?= e($cartTotalCents) ?>">
                    <div class="pos-calc-row" id="pos-cash-input-row">
                        <label for="pos-cash-input">Uang Tunai Diterima (Rp)</label>
                        <input type="text" id="pos-cash-input" name="uang_diterima" class="pos-cash-input"
                               placeholder="0" inputmode="numeric" autocomplete="off"
                               <?= $formState['metode_pembayaran'] === 'tunai' ? 'required' : '' ?>
                               value="<?= e($formState['uang_diterima']) ?>">
                    </div>
                    <div class="pos-quick-cash" id="pos-quick-cash">
                        <button type="button" class="pos-cash-chip" data-quick="exact">Uang Pas</button>
                        <button type="button" class="pos-cash-chip" data-quick="10000">+10k</button>
                        <button type="button" class="pos-cash-chip" data-quick="20000">+20k</button>
                        <button type="button" class="pos-cash-chip" data-quick="50000">+50k</button>
                        <button type="button" class="pos-cash-chip" data-quick="100000">+100k</button>
                    </div>
                    <div class="pos-change-row no-change" id="pos-change-display">
                        <span>Kembalian</span>
                        <strong id="pos-change-val">Rp 0</strong>
                    </div>
                </div>
                <p class="input-hint" id="pos-payment-note" role="status"></p>
                <div class="alert alert-error" id="pos-member-shortfall" role="alert" hidden></div>

                <div class="pos-cart-actions">
                    <button class="button button-secondary" type="submit" name="aksi" value="kosongkan"
                            formnovalidate onclick="return confirm('Kosongkan seluruh keranjang?');"
                            style="flex: 0 0 auto;" title="Kosongkan Keranjang">
                        Kosongkan
                    </button>
                    <button class="button button-full" type="submit" name="aksi" value="simpan"
                            <?= $hasInvalidCartItem ? 'disabled title="Hapus produk nonaktif terlebih dahulu"' : '' ?>
                            <?= !$hasInvalidCartItem ? 'onclick="return confirm(\'Simpan dan proses transaksi ini?\');"' : '' ?>>
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>Simpan Transaksi</span>
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</form>

<script>
(function () {
    var searchInput = document.getElementById('pos-search');
    var barcodeInput = document.getElementById('pos-barcode-input');
    var barcodeSubmit = document.getElementById('pos-barcode-submit');
    var memberInput = document.getElementById('member-rfid-input');
    var memberSubmit = document.getElementById('member-rfid-submit');
    var customerSelect = document.getElementById('id_pelanggan');
    var grid = document.getElementById('pos-grid');
    var chips = document.querySelectorAll('#pos-category-chips .pos-chip');
    var activeCategory = 'all';

    if (barcodeInput && barcodeSubmit) {
        var barcodeSubmitTimer = null;
        var barcodeSubmitStarted = false;

        function submitScannedBarcode() {
            if (!barcodeSubmitStarted && barcodeInput.value.trim() !== '') {
                barcodeSubmitStarted = true;
                barcodeSubmit.click();
            }
        }

        barcodeSubmit.addEventListener('click', function () {
            window.clearTimeout(barcodeSubmitTimer);
            barcodeSubmitStarted = true;
        });

        barcodeInput.addEventListener('input', function () {
            window.clearTimeout(barcodeSubmitTimer);
            barcodeSubmitTimer = window.setTimeout(submitScannedBarcode, 300);
        });

        barcodeInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                window.clearTimeout(barcodeSubmitTimer);
                submitScannedBarcode();
            }
        });
    }

    if (memberInput && memberSubmit) {
        memberInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                memberSubmit.click();
            }
        });
    }

    function filterProducts() {
        if (!grid) return;
        var query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        var cards = grid.querySelectorAll('.pos-card');
        for (var i = 0; i < cards.length; i++) {
            var searchMatch = !query || (cards[i].getAttribute('data-search') || '').indexOf(query) !== -1;
            var catAttr = cards[i].getAttribute('data-category') || '';
            var catMatch = (activeCategory === 'all') || (catAttr === activeCategory);
            cards[i].style.display = (searchMatch && catMatch) ? '' : 'none';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterProducts);

        // Enter key di search input = klik kartu produk pertama yang cocok
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var cards = grid ? grid.querySelectorAll('.pos-card:not([disabled])') : [];
                for (var i = 0; i < cards.length; i++) {
                    if (cards[i].style.display !== 'none') {
                        cards[i].click();
                        break;
                    }
                }
            }
        });
    }

    // Keyboard shortcut global: Tekan '/' untuk langsung fokus ke pencarian produk
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && document.activeElement !== searchInput && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
            e.preventDefault();
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });

    // Filter kategori tabs
    if (chips.length > 0) {
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                chips.forEach(function (c) { c.classList.remove('active'); });
                chip.classList.add('active');
                activeCategory = chip.getAttribute('data-category') || 'all';
                filterProducts();
            });
        });
    }

    // Kalkulator Kembalian Kasir Otomatis
    var calcBox = document.querySelector('.pos-calc-box');
    var cashInput = document.getElementById('pos-cash-input');
    var changeVal = document.getElementById('pos-change-val');
    var changeRow = document.getElementById('pos-change-display');
    var discountRate = document.getElementById('pos-discount-rate');
    var discountAmount = document.getElementById('pos-discount-amount');
    var grandTotal = document.getElementById('pos-grand-total');
    var balanceUsedDisplay = document.getElementById('pos-balance-used');
    var paymentDueLabel = document.getElementById('pos-payment-due-label');
    var paymentDueDisplay = document.getElementById('pos-payment-due');
    var paymentMethodSelect = document.getElementById('metode_pembayaran');
    var cashInputRow = document.getElementById('pos-cash-input-row');
    var quickCashRow = document.getElementById('pos-quick-cash');
    var paymentNote = document.getElementById('pos-payment-note');
    var memberShortfall = document.getElementById('pos-member-shortfall');

    if (calcBox && cashInput && changeVal && changeRow) {
        var totalCents = parseInt(calcBox.getAttribute('data-total-cents') || '0', 10);
        var grossTotalCents = parseInt(calcBox.getAttribute('data-gross-total-cents') || '0', 10);
        var customerBalanceCents = 0;
        var cashDueCents = totalCents;

        function formatRupiahJs(cents) {
            var whole = Math.floor(cents / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            var fraction = ('0' + (cents % 100)).slice(-2);
            return 'Rp ' + whole + ',' + fraction;
        }

        function updatePaymentMethod() {
            var method = paymentMethodSelect ? paymentMethodSelect.value : 'tunai';
            var balanceUsedCents = method === 'member'
                ? Math.min(customerBalanceCents, totalCents)
                : 0;
            cashDueCents = method === 'tunai' ? totalCents : 0;
            calcBox.setAttribute(
                'data-payment-due-cents',
                (method === 'member' ? 0 : totalCents).toString()
            );
            if (balanceUsedDisplay) {
                balanceUsedDisplay.textContent = formatRupiahJs(balanceUsedCents);
            }
            if (paymentDueLabel && paymentDueDisplay) {
                if (method === 'qris') {
                    paymentDueLabel.textContent = 'Dibayar via QRIS';
                    paymentDueDisplay.textContent = formatRupiahJs(totalCents);
                } else if (method === 'member') {
                    paymentDueLabel.textContent = 'Saldo tersisa setelah transaksi';
                    paymentDueDisplay.textContent = formatRupiahJs(
                        Math.max(0, customerBalanceCents - balanceUsedCents)
                    );
                } else {
                    paymentDueLabel.textContent = 'Dibayar tunai';
                    paymentDueDisplay.textContent = formatRupiahJs(totalCents);
                }
            }

            var isCash = method === 'tunai';
            if (cashInputRow) {
                cashInputRow.style.display = isCash ? '' : 'none';
            }
            if (quickCashRow) {
                quickCashRow.style.display = isCash ? '' : 'none';
            }
            changeRow.style.display = isCash ? '' : 'none';
            cashInput.required = isCash;
            cashInput.disabled = !isCash;
            if (!isCash) {
                cashInput.value = '';
            }

            if (paymentNote) {
                paymentNote.textContent = method === 'qris'
                    ? 'QRIS dibuat melalui Midtrans. Stok ditahan sampai pembayaran selesai atau kedaluwarsa.'
                    : (method === 'member'
                        ? 'Pembayaran member menggunakan seluruh saldo yang diperlukan; saldo harus mencukupi total.'
                        : 'Masukkan uang tunai yang diterima pelanggan.');
            }

            if (memberShortfall) {
                var noCustomer = !customerSelect || customerSelect.value === '';
                var insufficient = customerBalanceCents < totalCents;
                var showShortfall = method === 'member' && (noCustomer || insufficient);
                memberShortfall.hidden = !showShortfall;
                memberShortfall.style.display = showShortfall ? '' : 'none';
                memberShortfall.textContent = noCustomer
                    ? 'Pilih pelanggan untuk menggunakan saldo member.'
                    : 'Saldo member tidak mencukupi untuk membayar seluruh transaksi.';
            }
            updateChange();
        }

        function updateCustomerDiscount() {
            var selectedOption = customerSelect ? customerSelect.options[customerSelect.selectedIndex] : null;
            var basisPoints = selectedOption
                ? parseInt(selectedOption.getAttribute('data-discount-basis-points') || '0', 10)
                : 0;
            customerBalanceCents = selectedOption
                ? parseInt(selectedOption.getAttribute('data-balance-cents') || '0', 10)
                : 0;
            var totalDiscountCents = 0;
            var cartItems = document.querySelectorAll('.pos-cart-item[data-subtotal-cents]');

            for (var i = 0; i < cartItems.length; i++) {
                var lineCents = parseInt(cartItems[i].getAttribute('data-subtotal-cents') || '0', 10);
                totalDiscountCents += Math.floor(lineCents / 10000) * basisPoints
                    + Math.floor(((lineCents % 10000) * basisPoints + 5000) / 10000);
            }

            totalCents = Math.max(0, grossTotalCents - totalDiscountCents);
            calcBox.setAttribute('data-total-cents', totalCents.toString());
            if (discountRate) {
                discountRate.textContent = (basisPoints / 100).toFixed(2).replace('.', ',');
            }
            if (discountAmount) {
                discountAmount.textContent = formatRupiahJs(totalDiscountCents);
            }
            if (grandTotal) {
                grandTotal.textContent = formatRupiahJs(totalCents);
            }
            updatePaymentMethod();
        }

        function updateChange() {
            if (!paymentMethodSelect || paymentMethodSelect.value !== 'tunai') {
                return;
            }
            var rawVal = cashInput.value.replace(/[^0-9]/g, '');
            var cashCents = rawVal ? parseInt(rawVal, 10) * 100 : 0;

            if (cashCents <= 0) {
                changeRow.className = 'pos-change-row no-change';
                changeVal.textContent = 'Rp 0,00';
                return;
            }

            var diff = cashCents - cashDueCents;
            if (diff >= 0) {
                changeRow.className = 'pos-change-row has-change';
                changeRow.firstElementChild.textContent = 'Kembalian';
                changeVal.textContent = formatRupiahJs(diff);
            } else {
                changeRow.className = 'pos-change-row short-cash';
                changeRow.firstElementChild.textContent = 'Kurang';
                changeVal.textContent = formatRupiahJs(Math.abs(diff));
            }
        }

        if (customerSelect) {
            customerSelect.addEventListener('change', updateCustomerDiscount);
        }
        if (paymentMethodSelect) {
            paymentMethodSelect.addEventListener('change', updatePaymentMethod);
        }

        cashInput.addEventListener('input', function () {
            var num = this.value.replace(/[^0-9]/g, '');
            if (num) {
                this.value = num.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            } else {
                this.value = '';
            }
            updateChange();
        });

        // Tombol Quick Cash (+10k, +20k, Uang Pas)
        var quickButtons = document.querySelectorAll('.pos-cash-chip');
        quickButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var q = this.getAttribute('data-quick');
                if (q === 'exact') {
                    var exactCash = Math.ceil(cashDueCents / 100);
                    cashInput.value = exactCash.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                } else {
                    var addVal = parseFloat(q);
                    var current = parseFloat(cashInput.value.replace(/[^0-9]/g, '') || '0');
                    var next = current + addVal;
                    cashInput.value = Math.round(next).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                }
                updateChange();
            });
        });
        updateCustomerDiscount();
    }

    // Fokuskan input barcode agar kasir siap memindai produk berikutnya.
    if (barcodeInput) {
        barcodeInput.focus();
        barcodeInput.select();
    } else if (searchInput) {
        searchInput.focus();
    }
})();
</script>
