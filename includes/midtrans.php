<?php
declare(strict_types=1);

function midtransServerKey(): string
{
    $serverKey = $_SERVER['MIDTRANS_SERVER_KEY'] ?? '';
    if (!is_string($serverKey)
        || trim($serverKey) === ''
        || trim($serverKey) === 'GANTI_DENGAN_SANDBOX_SERVER_KEY') {
        throw new RuntimeException('MIDTRANS_SERVER_KEY belum dikonfigurasi.');
    }

    return trim($serverKey);
}

function midtransIsProduction(): bool
{
    return strtolower((string) ($_SERVER['MIDTRANS_ENV'] ?? 'sandbox')) === 'production';
}

function midtransCreateQrisPayment(string $orderId, int $amountRupiah): array
{
    $serverKey = midtransServerKey();
    $endpoint = midtransIsProduction()
        ? 'https://api.midtrans.com/v2/charge'
        : 'https://api.sandbox.midtrans.com/v2/charge';
    $payload = json_encode([
        'payment_type' => 'qris',
        'transaction_details' => [
            'order_id' => $orderId,
            'gross_amount' => $amountRupiah,
        ],
    ], JSON_THROW_ON_ERROR);

    $handle = curl_init($endpoint);
    if ($handle === false) {
        throw new RuntimeException('Koneksi ke layanan pembayaran tidak dapat dimulai.');
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($serverKey . ':'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $responseBody = curl_exec($handle);
    $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if (!is_string($responseBody)) {
        error_log('Permintaan Core API QRIS Midtrans gagal: ' . $curlError);
        throw new RuntimeException('Layanan QRIS tidak dapat dihubungi. Coba kembali.');
    }

    try {
        $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('Respons Core API QRIS Midtrans bukan JSON yang valid.');
        throw new RuntimeException('Respons layanan QRIS tidak valid.');
    }

    $expectedHost = midtransIsProduction()
        ? 'api.midtrans.com'
        : 'api.sandbox.midtrans.com';
    $qrCodeUrl = null;
    if (is_array($response) && isset($response['actions']) && is_array($response['actions'])) {
        foreach ($response['actions'] as $action) {
            if (!is_array($action)
                || ($action['name'] ?? null) !== 'generate-qr-code'
                || ($action['method'] ?? null) !== 'GET'
                || !isset($action['url'])
                || !is_string($action['url'])) {
                continue;
            }

            $actionUrl = $action['url'];
            $actionPath = isset($response['transaction_id']) && is_string($response['transaction_id'])
                ? '/v2/qris/' . rawurlencode($response['transaction_id']) . '/qr-code'
                : null;
            if (filter_var($actionUrl, FILTER_VALIDATE_URL) !== false
                && strtolower((string) parse_url($actionUrl, PHP_URL_SCHEME)) === 'https'
                && strtolower((string) parse_url($actionUrl, PHP_URL_HOST)) === $expectedHost
                && $actionPath !== null
                && parse_url($actionUrl, PHP_URL_PATH) === $actionPath) {
                $qrCodeUrl = $actionUrl;
                break;
            }
        }
    }

    $responseAmountMatches = false;
    if (is_array($response)
        && isset($response['gross_amount'])
        && is_string($response['gross_amount'])) {
        try {
            $responseAmountMatches = decimalToCents($response['gross_amount']) === $amountRupiah * 100;
        } catch (InvalidArgumentException $exception) {
            $responseAmountMatches = false;
        }
    }

    if ($httpStatus < 200 || $httpStatus >= 300
        || !is_array($response)
        || ($response['order_id'] ?? null) !== $orderId
        || ($response['payment_type'] ?? null) !== 'qris'
        || ($response['transaction_status'] ?? null) !== 'pending'
        || !isset($response['transaction_id'])
        || !is_string($response['transaction_id'])
        || trim($response['transaction_id']) === ''
        || ($response['currency'] ?? null) !== 'IDR'
        || !$responseAmountMatches
        || $qrCodeUrl === null) {
        $statusMessage = is_array($response) && isset($response['status_message'])
            && is_string($response['status_message'])
            ? $response['status_message']
            : 'respons tidak sesuai';
        error_log('Core API QRIS Midtrans menolak pembuatan pembayaran: HTTP ' . $httpStatus . ' ' . $statusMessage);
        throw new RuntimeException('QRIS gagal dibuat. Periksa konfigurasi Midtrans dan coba kembali.');
    }

    $response['qr_code_url'] = $qrCodeUrl;
    return $response;
}

function midtransSignatureIsValid(array $notification): bool
{
    foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $field) {
        if (!isset($notification[$field]) || !is_string($notification[$field])) {
            return false;
        }
    }

    $expectedSignature = hash(
        'sha512',
        $notification['order_id']
            . $notification['status_code']
            . $notification['gross_amount']
            . midtransServerKey()
    );

    return hash_equals($expectedSignature, $notification['signature_key']);
}

function midtransReleasePendingOrder(PDO $pdo, string $orderId, string $status): void
{
    if (!in_array($status, ['failed', 'expired'], true)) {
        throw new InvalidArgumentException('Status pelepasan stok tidak valid.');
    }

    $pdo->beginTransaction();
    try {
        $transactionStatement = $pdo->prepare(
            'SELECT id_qris_order, status_pembayaran
             FROM tbl_qris_order
             WHERE midtrans_order_id = :order_id
             FOR UPDATE'
        );
        $transactionStatement->execute(['order_id' => $orderId]);
        $transaction = $transactionStatement->fetch();
        if ($transaction === false || $transaction['status_pembayaran'] !== 'pending') {
            $pdo->commit();
            return;
        }

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

        $updateStatement = $pdo->prepare(
            'UPDATE tbl_qris_order
             SET status_pembayaran = :status, midtrans_status = :midtrans_status
             WHERE id_qris_order = :id_qris_order'
        );
        $updateStatement->execute([
            'status' => $status,
            'midtrans_status' => $status === 'expired' ? 'expire' : 'failure',
            'id_qris_order' => $transaction['id_qris_order'],
        ]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
