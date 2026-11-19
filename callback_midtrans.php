<?php
declare(strict_types=1);

require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/midtrans.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['status' => $message], JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, 'method_not_allowed');
}

$body = file_get_contents('php://input');
try {
    $notification = is_string($body)
        ? json_decode($body, true, 512, JSON_THROW_ON_ERROR)
        : null;
} catch (JsonException $exception) {
    $respond(400, 'invalid_json');
}

if (!is_array($notification)) {
    $respond(400, 'invalid_notification');
}

try {
    if (!midtransSignatureIsValid($notification)) {
        $respond(403, 'invalid_signature');
    }

    $pdo = require __DIR__ . '/config/database.php';
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'SELECT id_qris_order, total_belanja, status_pembayaran,
                id_transaksi, tanggal, id_pelanggan
         FROM tbl_qris_order
         WHERE midtrans_order_id = :order_id
         FOR UPDATE'
    );
    $statement->execute(['order_id' => $notification['order_id']]);
    $transaction = $statement->fetch();
    if ($transaction === false) {
        $pdo->rollBack();
        $respond(404, 'order_not_found');
    }

    if (!isset($notification['payment_type'])
        || $notification['payment_type'] !== 'qris') {
        $pdo->rollBack();
        $respond(400, 'payment_method_mismatch');
    }
    if (isset($notification['currency']) && $notification['currency'] !== 'IDR') {
        $pdo->rollBack();
        $respond(400, 'currency_mismatch');
    }

    try {
        $expectedAmount = decimalToCents($transaction['total_belanja']);
        $notifiedAmount = decimalToCents($notification['gross_amount']);
    } catch (InvalidArgumentException $exception) {
        $pdo->rollBack();
        $respond(400, 'invalid_amount');
    }
    if ($expectedAmount !== $notifiedAmount) {
        $pdo->rollBack();
        $respond(400, 'amount_mismatch');
    }

    $remoteStatus = isset($notification['transaction_status'])
        && is_string($notification['transaction_status'])
        ? strtolower($notification['transaction_status'])
        : '';
    $midtransTransactionId = isset($notification['transaction_id'])
        && is_string($notification['transaction_id'])
        ? $notification['transaction_id']
        : null;
    $localStatus = $transaction['status_pembayaran'];
    $nextStatus = $localStatus;
    $releaseReservedStock = false;
    $createPaidTransaction = false;

    if ($remoteStatus === 'settlement'
        || ($remoteStatus === 'capture'
            && ($notification['fraud_status'] ?? null) === 'accept')) {
        if ($localStatus === 'pending') {
            $nextStatus = 'paid';
            $createPaidTransaction = true;
        } elseif (in_array($localStatus, ['failed', 'expired'], true)) {
            $nextStatus = 'paid_unfulfilled';
        }
    } elseif (in_array($remoteStatus, ['expire', 'cancel', 'deny', 'failure'], true)) {
        if ($localStatus === 'pending') {
            $nextStatus = $remoteStatus === 'expire' ? 'expired' : 'failed';
            $releaseReservedStock = true;
        } elseif ($localStatus === 'paid') {
            $nextStatus = 'reversed';
        }
    } elseif (in_array(
        $remoteStatus,
        ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'],
        true
    ) && $localStatus === 'paid') {
        $nextStatus = 'reversed';
    }

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
        $insertTransaction = $pdo->prepare(
            'INSERT INTO tbl_transaksi
                (tanggal, id_pelanggan, total_belanja, uang_diterima, saldo_digunakan,
                 metode_pembayaran, status_pembayaran, midtrans_order_id,
                 midtrans_transaction_id, midtrans_status)
             VALUES
                (:tanggal, :id_pelanggan, :total, NULL, 0.00,
                 :metode_pembayaran, :status_pembayaran, :order_id,
                 :midtrans_transaction_id, :midtrans_status)'
        );
        $insertTransaction->execute([
            'tanggal' => $transaction['tanggal'],
            'id_pelanggan' => $transaction['id_pelanggan'],
            'total' => $transaction['total_belanja'],
            'metode_pembayaran' => 'qris',
            'status_pembayaran' => 'paid',
            'order_id' => $notification['order_id'],
            'midtrans_transaction_id' => $midtransTransactionId,
            'midtrans_status' => $remoteStatus,
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

    $update = $pdo->prepare(
        'UPDATE tbl_qris_order
         SET status_pembayaran = :status_pembayaran,
             midtrans_status = :midtrans_status,
             midtrans_transaction_id = :midtrans_transaction_id,
             id_transaksi = :id_transaksi
         WHERE id_qris_order = :id_qris_order'
    );
    $update->execute([
        'status_pembayaran' => $nextStatus,
        'midtrans_status' => $remoteStatus !== '' ? $remoteStatus : null,
        'midtrans_transaction_id' => $midtransTransactionId,
        'id_transaksi' => $localTransactionId,
        'id_qris_order' => $transaction['id_qris_order'],
    ]);

    if ($nextStatus === 'reversed' && $localTransactionId !== null) {
        $updateTransaction = $pdo->prepare(
            'UPDATE tbl_transaksi
             SET status_pembayaran = :status_pembayaran, midtrans_status = :midtrans_status
             WHERE id_transaksi = :id_transaksi'
        );
        $updateTransaction->execute([
            'status_pembayaran' => 'reversed',
            'midtrans_status' => $remoteStatus,
            'id_transaksi' => $localTransactionId,
        ]);
    }
    $pdo->commit();
    $respond(200, 'ok');
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Konfigurasi callback Midtrans gagal: ' . $exception->getMessage());
    $respond(503, 'callback_unavailable');
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Callback Midtrans gagal: ' . $exception->getMessage());
    $respond(500, 'callback_failed');
}
