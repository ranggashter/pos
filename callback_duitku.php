<?php
declare(strict_types=1);

require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/duitku.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['status' => $message], JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, 'method_not_allowed');
}

// Duitku biasanya mengirimkan x-www-form-urlencoded, tetapi bisa juga JSON
$notification = $_POST;
if ($notification === []) {
    $rawInput = file_get_contents('php://input');
    if (is_string($rawInput) && trim($rawInput) !== '') {
        try {
            $decoded = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $notification = $decoded;
            }
        } catch (JsonException $e) {
            $respond(400, 'invalid_json');
        }
    }
}

if (!is_array($notification) || !isset($notification['merchantOrderId'])) {
    $respond(400, 'invalid_notification');
}

try {
    if (!duitkuCallbackSignatureIsValid($notification)) {
        $respond(403, 'invalid_signature');
    }

    $pdo = require __DIR__ . '/config/database.php';
    $pdo->beginTransaction();

    $orderId = (string) $notification['merchantOrderId'];
    $statement = $pdo->prepare(
        'SELECT id_qris_order, total_belanja, status_pembayaran,
                id_transaksi, tanggal, id_pelanggan,
                duitku_channel, duitku_va_number, duitku_payment_name, duitku_payment_code
         FROM tbl_qris_order
         WHERE duitku_order_id = :order_id
         FOR UPDATE'
    );
    $statement->execute(['order_id' => $orderId]);
    $transaction = $statement->fetch();
    if ($transaction === false) {
        $pdo->rollBack();
        $respond(404, 'order_not_found');
    }

    // Validasi kesesuaian nominal
    try {
        $expectedAmount = decimalToCents($transaction['total_belanja']);
        $notifiedAmount = decimalToCents((string) $notification['amount']);
    } catch (InvalidArgumentException $exception) {
        $pdo->rollBack();
        $respond(400, 'invalid_amount');
    }

    if ($expectedAmount !== $notifiedAmount) {
        $pdo->rollBack();
        $respond(400, 'amount_mismatch');
    }

    $resultCode = (string) ($notification['resultCode'] ?? '');
    $reference = isset($notification['reference']) && is_string($notification['reference'])
        ? $notification['reference']
        : null;

    $localStatus = $transaction['status_pembayaran'];
    $nextStatus = $localStatus;
    $releaseReservedStock = false;
    $createPaidTransaction = false;

    // Duitku: '00' = Sukses / Paid
    if ($resultCode === '00') {
        if ($localStatus === 'pending') {
            $nextStatus = 'paid';
            $createPaidTransaction = true;
        } elseif (in_array($localStatus, ['failed', 'expired'], true)) {
            $nextStatus = 'paid_unfulfilled';
        }
    } else {
        // Gagal / Expired / Dibatalkan
        if ($localStatus === 'pending') {
            $nextStatus = 'failed';
            $releaseReservedStock = true;
        }
    }

    // Kembalikan stok jika gagal
    if ($releaseReservedStock) {
        $detailStatement = $pdo->prepare(
            'SELECT id_produk, jumlah
             FROM tbl_qris_order_detail
             WHERE id_qris_order = :id_qris_order'
        );
        $detailStatement->execute(['id_qris_order' => $transaction['id_qris_order']]);
        $restockStatement = $pdo->prepare(
            'UPDATE tbl_produk SET stok = stok + :jumlah WHERE id_produk = :id_produk'
        );
        foreach ($detailStatement->fetchAll() as $item) {
            $restockStatement->execute([
                'jumlah' => $item['jumlah'],
                'id_produk' => $item['id_produk'],
            ]);
        }
    }

    $localTransactionId = $transaction['id_transaksi'];
    if ($createPaidTransaction) {
        $channelInfo = duitkuGetChannelInfo((string) ($transaction['duitku_channel'] ?? ''));
        $paymentMethodLabel = !empty($transaction['duitku_payment_name'])
            ? (string) $transaction['duitku_payment_name']
            : ((($channelInfo['type'] ?? '') === 'qris' || empty($transaction['duitku_channel']))
                ? 'qris'
                : ($channelInfo['name'] ?? (string) $transaction['duitku_channel']));

        $insertTransaction = $pdo->prepare(
            'INSERT INTO tbl_transaksi
                (tanggal, id_pelanggan, total_belanja, uang_diterima, saldo_digunakan,
                 metode_pembayaran, status_pembayaran, duitku_order_id,
                 duitku_reference, duitku_status, duitku_channel, duitku_va_number,
                 duitku_payment_name, duitku_payment_code)
             VALUES
                (:tanggal, :id_pelanggan, :total, NULL, 0.00,
                 :metode_pembayaran, :status_pembayaran, :order_id,
                 :duitku_reference, :duitku_status, :duitku_channel, :duitku_va_number,
                 :duitku_payment_name, :duitku_payment_code)'
        );
        $insertTransaction->execute([
            'tanggal' => $transaction['tanggal'],
            'id_pelanggan' => $transaction['id_pelanggan'],
            'total' => $transaction['total_belanja'],
            'metode_pembayaran' => $paymentMethodLabel,
            'status_pembayaran' => 'paid',
            'order_id' => $orderId,
            'duitku_reference' => $reference,
            'duitku_status' => 'SUCCESS',
            'duitku_channel' => $transaction['duitku_channel'] ?? null,
            'duitku_va_number' => $transaction['duitku_va_number'] ?? null,
            'duitku_payment_name' => $transaction['duitku_payment_name'] ?? null,
            'duitku_payment_code' => $transaction['duitku_payment_code'] ?? null,
        ]);
        $localTransactionId = (int) $pdo->lastInsertId();

        $copyDetails = $pdo->prepare(
            'INSERT INTO tbl_detail_transaksi
                (id_transaksi, id_produk, jumlah, harga_satuan, diskon, subtotal)
             SELECT :id_transaksi, id_produk, jumlah, harga_satuan, diskon, subtotal
             FROM tbl_qris_order_detail
             WHERE id_qris_order = :id_qris_order'
        );
        $copyDetails->execute([
            'id_transaksi' => $localTransactionId,
            'id_qris_order' => $transaction['id_qris_order'],
        ]);
    }

    // Perbarui status tbl_qris_order
    $update = $pdo->prepare(
        'UPDATE tbl_qris_order
         SET status_pembayaran = :status_pembayaran,
             duitku_status = :duitku_status,
             duitku_reference = :duitku_reference,
             id_transaksi = :id_transaksi
         WHERE id_qris_order = :id_qris_order'
    );
    $update->execute([
        'status_pembayaran' => $nextStatus,
        'duitku_status' => $resultCode === '00' ? 'SUCCESS' : 'FAILED',
        'duitku_reference' => $reference,
        'id_transaksi' => $localTransactionId,
        'id_qris_order' => $transaction['id_qris_order'],
    ]);

    $pdo->commit();
    $respond(200, 'ok');
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Konfigurasi callback Duitku gagal: ' . $exception->getMessage());
    $respond(503, 'callback_unavailable');
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Callback Duitku gagal: ' . $exception->getMessage());
    $respond(500, 'callback_failed');
}
