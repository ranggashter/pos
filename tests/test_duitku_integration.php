<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/duitku.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== MEMULAI TEST INTEGRASI DUITKU & DATABASE ===\n";

// 1. Cek Kolom Database
echo "[1] Memeriksa struktur tabel database 'toko'...\n";
$stmt = $pdo->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'duitku_%'");
$transaksiCols = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "   Kolom tbl_transaksi: " . implode(', ', $transaksiCols) . "\n";
assert(in_array('duitku_order_id', $transaksiCols, true), "duitku_order_id missing in tbl_transaksi");
assert(in_array('duitku_reference', $transaksiCols, true), "duitku_reference missing in tbl_transaksi");
assert(in_array('duitku_status', $transaksiCols, true), "duitku_status missing in tbl_transaksi");
assert(in_array('duitku_channel', $transaksiCols, true), "duitku_channel missing in tbl_transaksi");
assert(in_array('duitku_payment_code', $transaksiCols, true), "duitku_payment_code missing in tbl_transaksi");

$stmt = $pdo->query("SHOW COLUMNS FROM tbl_qris_order LIKE 'duitku_%'");
$qrisCols = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "   Kolom tbl_qris_order: " . implode(', ', $qrisCols) . "\n";
assert(in_array('duitku_order_id', $qrisCols, true), "duitku_order_id missing in tbl_qris_order");
assert(in_array('duitku_reference', $qrisCols, true), "duitku_reference missing in tbl_qris_order");
assert(in_array('duitku_qr_url', $qrisCols, true), "duitku_qr_url missing in tbl_qris_order");
assert(in_array('duitku_channel', $qrisCols, true), "duitku_channel missing in tbl_qris_order");
assert(in_array('duitku_va_number', $qrisCols, true), "duitku_va_number missing in tbl_qris_order");
assert(in_array('duitku_payment_code', $qrisCols, true), "duitku_payment_code missing in tbl_qris_order");
echo "   -> Struktur kolom Duitku: OK!\n";

// 2. Cek Konfigurasi Duitku & Signature Helper
echo "[2] Memeriksa helper signature Duitku...\n";
putenv('DUITKU_ENV=sandbox');
putenv('DUITKU_MERCHANT_CODE=DS38194');
putenv('DUITKU_API_KEY=ee752819ff55908e4f32654713bbe4a7');

$merchantCode = duitkuMerchantCode();
$apiKey = duitkuApiKey();
$testOrderId = 'TK-TEST-' . time();
$amountStr = '25000';

// Formula callback: md5(merchantCode + amount + merchantOrderId + apiKey)
$validSignature = md5($merchantCode . $amountStr . $testOrderId . $apiKey);

$payload = [
    'merchantCode' => $merchantCode,
    'amount' => $amountStr,
    'merchantOrderId' => $testOrderId,
    'signature' => $validSignature,
    'resultCode' => '00',
    'reference' => 'DREF-TEST-999',
];

assert(duitkuCallbackSignatureIsValid($payload) === true, "Valid signature verification failed");

$invalidPayload = $payload;
$invalidPayload['signature'] = 'wrongsignature';
assert(duitkuCallbackSignatureIsValid($invalidPayload) === false, "Invalid signature check failed to reject");
echo "   -> Validasi signature Duitku: OK!\n";

// 3. Test Lifecycle Pending Order & Stock Release
echo "[3] Memeriksa lifecycle order QRIS & pelepasan stok...\n";
// Ambil produk acak untuk uji coba
$prod = $pdo->query("SELECT id_produk, stok, harga FROM tbl_produk WHERE status_aktif = 1 LIMIT 1")->fetch();
if ($prod) {
    $initialStock = (int) $prod['stok'];
    $prodId = (int) $prod['id_produk'];
    $unitPrice = (string) $prod['harga'];

    // Kurangi stok 1 untuk simulasi pending
    $pdo->prepare("UPDATE tbl_produk SET stok = stok - 1 WHERE id_produk = :id")->execute(['id' => $prodId]);

    // Buat order dummy
    $dummyOrderId = 'TK-UNIT-' . bin2hex(random_bytes(6));
    $stmt = $pdo->prepare("INSERT INTO tbl_qris_order (duitku_order_id, tanggal, total_belanja, status_pembayaran) VALUES (:oid, CURRENT_DATE(), 25000, 'pending')");
    $stmt->execute(['oid' => $dummyOrderId]);
    $qrisId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO tbl_qris_order_detail (id_qris_order, id_produk, jumlah, harga_satuan, subtotal) VALUES (:qid, :pid, 1, :harga, :subtotal)");
    $stmt->execute(['qid' => $qrisId, 'pid' => $prodId, 'harga' => $unitPrice, 'subtotal' => $unitPrice]);

    // Uji release stock function
    duitkuReleasePendingOrder($pdo, $dummyOrderId, 'failed');

    // Verifikasi stok kembali
    $stmt = $pdo->prepare("SELECT stok FROM tbl_produk WHERE id_produk = :id");
    $stmt->execute(['id' => $prodId]);
    $restoredStock = (int) $stmt->fetchColumn();
    assert($restoredStock === $initialStock, "Stok tidak kembali setelah release! Awal: $initialStock, Sekarang: $restoredStock");

    // Verifikasi status order
    $stmt = $pdo->prepare("SELECT status_pembayaran FROM tbl_qris_order WHERE id_qris_order = :id");
    $stmt->execute(['id' => $qrisId]);
    $finalStatus = $stmt->fetchColumn();
    assert($finalStatus === 'failed', "Status pembayaran bukan 'failed'!");

    // Bersihkan dummy order
    $pdo->prepare("DELETE FROM tbl_qris_order_detail WHERE id_qris_order = :id")->execute(['id' => $qrisId]);
    $pdo->prepare("DELETE FROM tbl_qris_order WHERE id_qris_order = :id")->execute(['id' => $qrisId]);
    echo "   -> Siklus penahanan & pelepasan stok: OK!\n";

    // 4. Test Simulasi Callback Sukses (Paid)
    echo "[4] Memeriksa simulasi callback pembayaran sukses (Paid)...\n";
    $paidOrderId = 'TK-PAID-' . bin2hex(random_bytes(6));
    $stmt = $pdo->prepare("INSERT INTO tbl_qris_order (duitku_order_id, tanggal, total_belanja, status_pembayaran) VALUES (:oid, CURRENT_DATE(), 50000, 'pending')");
    $stmt->execute(['oid' => $paidOrderId]);
    $paidQrisId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO tbl_qris_order_detail (id_qris_order, id_produk, jumlah, harga_satuan, subtotal) VALUES (:qid, :pid, 2, 25000, 50000)");
    $stmt->execute(['qid' => $paidQrisId, 'pid' => $prodId]);

    // Ambil merchant code dan api key yang sama dengan yang dibaca oleh Apache (.htaccess)
    $serverMerchantCode = 'DS38194';
    $serverApiKey = 'ee752819ff55908e4f32654713bbe4a7';

    $callbackPayload = [
        'merchantCode' => $serverMerchantCode,
        'amount' => '50000',
        'merchantOrderId' => $paidOrderId,
        'signature' => md5($serverMerchantCode . '50000' . $paidOrderId . $serverApiKey),
        'resultCode' => '00',
        'reference' => 'REF-SIMULATION-123',
    ];

    $ch = curl_init('http://localhost/tugas_pak_hendrik/toko/callback_duitku.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($callbackPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "   Callback response ($httpCode): $response\n";
    assert($httpCode === 200, "Callback HTTP code is not 200! Received: $httpCode");

    // Verifikasi tbl_qris_order berubah jadi paid
    $stmt = $pdo->prepare("SELECT status_pembayaran, id_transaksi FROM tbl_qris_order WHERE id_qris_order = :id");
    $stmt->execute(['id' => $paidQrisId]);
    $orderRow = $stmt->fetch();
    assert($orderRow['status_pembayaran'] === 'paid', "Status order bukan paid!");
    assert(!empty($orderRow['id_transaksi']), "id_transaksi kosong!");
    $createdTxId = (int) $orderRow['id_transaksi'];

    // Verifikasi tbl_transaksi
    $stmt = $pdo->prepare("SELECT * FROM tbl_transaksi WHERE id_transaksi = :id");
    $stmt->execute(['id' => $createdTxId]);
    $txRow = $stmt->fetch();
    assert($txRow['status_pembayaran'] === 'paid', "tbl_transaksi status_pembayaran bukan paid!");
    assert($txRow['metode_pembayaran'] === 'qris', "tbl_transaksi metode_pembayaran bukan qris!");
    assert($txRow['duitku_order_id'] === $paidOrderId, "duitku_order_id tidak cocok!");

    // Verifikasi detail transaksi terbuat
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_detail_transaksi WHERE id_transaksi = :id");
    $stmt->execute(['id' => $createdTxId]);
    $detailCount = (int) $stmt->fetchColumn();
    assert($detailCount === 1, "Detail transaksi tidak terbuat!");

    // Bersihkan test data
    $pdo->prepare("DELETE FROM tbl_detail_transaksi WHERE id_transaksi = :id")->execute(['id' => $createdTxId]);
    $pdo->prepare("DELETE FROM tbl_transaksi WHERE id_transaksi = :id")->execute(['id' => $createdTxId]);
    $pdo->prepare("DELETE FROM tbl_qris_order_detail WHERE id_qris_order = :id")->execute(['id' => $paidQrisId]);
    $pdo->prepare("DELETE FROM tbl_qris_order WHERE id_qris_order = :id")->execute(['id' => $paidQrisId]);
    echo "   -> Callback Duitku Webhook ke DB: OK!\n";
}

echo "\n=== SEMUA TES BERHASIL 100% ===\n";
