<?php
declare(strict_types=1);

function duitkuMerchantCode(): string
{
    $code = $_SERVER['DUITKU_MERCHANT_CODE']
        ?? getenv('DUITKU_MERCHANT_CODE')
        ?? '';

    if (!is_string($code)
        || trim($code) === ''
        || trim($code) === 'GANTI_DENGAN_MERCHANT_CODE') {
        throw new RuntimeException('DUITKU_MERCHANT_CODE belum dikonfigurasi.');
    }

    return trim($code);
}

function duitkuApiKey(): string
{
    $key = $_SERVER['DUITKU_API_KEY']
        ?? getenv('DUITKU_API_KEY')
        ?? '';

    if (!is_string($key)
        || trim($key) === ''
        || trim($key) === 'GANTI_DENGAN_API_KEY') {
        throw new RuntimeException('DUITKU_API_KEY belum dikonfigurasi.');
    }

    return trim($key);
}

function duitkuIsProduction(): bool
{
    $env = $_SERVER['DUITKU_ENV'] ?? getenv('DUITKU_ENV') ?? 'sandbox';
    return strtolower((string) $env) === 'production';
}

function duitkuInquiryEndpoint(): string
{
    return duitkuIsProduction()
        ? 'https://passport.duitku.com/webapi/api/merchant/v2/inquiry'
        : 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry';
}

function duitkuStatusEndpoint(): string
{
    return duitkuIsProduction()
        ? 'https://api-prod.duitku.com/api/merchant/transactionStatus'
        : 'https://api-sandbox.duitku.com/api/merchant/transactionStatus';
}

/**
 * Daftar kategori dan channel metode pembayaran Duitku.
 * Hanya QRIS dan Virtual Account (Transfer Bank).
 */
function duitkuChannelCategories(): array
{
    return [
        'qris' => [
            'label' => 'QRIS (Duitku)',
            'channels' => [
                'SP' => ['code' => 'SP', 'name' => 'QRIS (Semua E-Wallet & M-Banking)', 'category' => 'qris', 'type' => 'qris'],
            ],
        ],
        'va' => [
            'label' => 'Transfer Bank / Virtual Account (Duitku)',
            'channels' => [
                'BC' => ['code' => 'BC', 'name' => 'BCA Virtual Account', 'category' => 'transfer_bank', 'type' => 'va'],
                'M2' => ['code' => 'M2', 'name' => 'Mandiri Virtual Account', 'category' => 'transfer_bank', 'type' => 'va'],
                'I1' => ['code' => 'I1', 'name' => 'BNI Virtual Account', 'category' => 'transfer_bank', 'type' => 'va'],
                'BR' => ['code' => 'BR', 'name' => 'BRI Virtual Account', 'category' => 'transfer_bank', 'type' => 'va'],
                'BV' => ['code' => 'BV', 'name' => 'BSI Virtual Account (Bank Syariah)', 'category' => 'transfer_bank', 'type' => 'va'],
                'BT' => ['code' => 'BT', 'name' => 'Permata Virtual Account', 'category' => 'transfer_bank', 'type' => 'va'],
                'B1' => ['code' => 'B1', 'name' => 'CIMB Niaga Virtual Account', 'category' => 'transfer_bank', 'type' => 'va'],
                'VA' => ['code' => 'VA', 'name' => 'Maybank Virtual Account', 'category' => 'transfer_bank', 'type' => 'va'],
                'NC' => ['code' => 'NC', 'name' => 'BNC (Bank Neo) Virtual Account', 'category' => 'transfer_bank', 'type' => 'va'],
                'A1' => ['code' => 'A1', 'name' => 'ATM Bersama Virtual Account', 'category' => 'transfer_bank', 'type' => 'va'],
            ],
        ],
    ];
}

/**
 * Daftar flat seluruh channel Duitku yang didukung.
 */
function duitkuChannelList(): array
{
    static $list = null;
    if ($list === null) {
        $list = [];
        foreach (duitkuChannelCategories() as $group) {
            foreach ($group['channels'] as $code => $info) {
                $list[$code] = $info;
            }
        }
    }
    return $list;
}

/**
 * Dapatkan informasi channel Duitku berdasarkan kode channel (misal: 'BC', 'SP').
 */
function duitkuGetChannelInfo(string $code): ?array
{
    $list = duitkuChannelList();
    if (isset($list[$code])) {
        return $list[$code];
    }
    if ($code === 'qris') {
        return $list['SP'];
    }
    return null;
}

/**
 * Buat transaksi pembayaran Duitku (QRIS / Virtual Account) via API v2 Inquiry.
 *
 * @param string $orderId ID pesanan unik (merchantOrderId)
 * @param int $amountRupiah Nominal transaksi bulat (IDR)
 * @param string $paymentMethodCode Kode channel Duitku (SP, NQ, BC, M2, I1, BR, BV, BT, B1, VA, NC, A1)
 * @param string|null $customerName Nama pelanggan (opsional)
 * @param string|null $customerEmail Email pelanggan (opsional)
 * @param string|null $customerPhone Nomor HP pelanggan (opsional)
 * @return array
 */
function duitkuCreatePayment(
    string $orderId,
    int $amountRupiah,
    string $paymentMethodCode = 'SP',
    ?string $customerName = null,
    ?string $customerEmail = null,
    ?string $customerPhone = null
): array {
    $merchantCode = duitkuMerchantCode();
    $apiKey = duitkuApiKey();
    $endpoint = duitkuInquiryEndpoint();

    if ($paymentMethodCode === 'qris') {
        $paymentMethodCode = 'SP';
    }

    $channelInfo = duitkuGetChannelInfo($paymentMethodCode);
    if ($channelInfo === null) {
        throw new InvalidArgumentException(
            'Metode pembayaran tidak didukung. Hanya QRIS dan Virtual Account yang tersedia.'
        );
    }
    $paymentName = $channelInfo['name'] ?? 'Pembayaran Duitku';

    // Hitung signature inquiry Duitku: md5(merchantCode + merchantOrderId + paymentAmount + apiKey)
    $signature = md5($merchantCode . $orderId . (string) $amountRupiah . $apiKey);

    // Tentukan URL callback & return secara otomatis (mendukung reverse proxy / ngrok)
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $baseUrl = ($isHttps ? 'https://' : 'http://') . $host . $scriptDir;

    $callbackUrl = $baseUrl . '/callback_duitku.php';
    $returnUrl = $baseUrl . '/index.php?page=kasir_qris';

    $payloadArray = [
        'merchantCode' => $merchantCode,
        'paymentAmount' => $amountRupiah,
        'paymentMethod' => $paymentMethodCode,
        'merchantOrderId' => $orderId,
        'productDetails' => 'Toko Kita - ' . $paymentName . ' - ' . $orderId,
        'email' => $customerEmail ?: 'kasir@tokokita.local',
        'phoneNumber' => $customerPhone ?: '081234567890',
        'additionalParam' => '',
        'merchantUserInfo' => '',
        'customerVaName' => $customerName ?: 'Pelanggan Toko Kita',
        'callbackUrl' => $callbackUrl,
        'returnUrl' => $returnUrl,
        'expiryPeriod' => 60,
        'signature' => $signature,
    ];

    $payloadJson = json_encode($payloadArray, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $handle = curl_init($endpoint);
    if ($handle === false) {
        throw new RuntimeException('Koneksi ke layanan Duitku tidak dapat dimulai.');
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payloadJson),
            'X-duitku-signature: ' . $signature,
            'X-duitku-merchantcode: ' . $merchantCode,
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
        error_log('Permintaan API Duitku gagal: ' . $curlError);
        throw new RuntimeException('Layanan Duitku tidak dapat dihubungi. Coba kembali.');
    }

    try {
        $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('Respons API Duitku bukan JSON valid: ' . substr($responseBody, 0, 300));
        throw new RuntimeException('Respons layanan Duitku tidak valid.');
    }

    if (!is_array($response) || ($response['statusCode'] ?? null) !== '00') {
        $msg = $response['statusMessage'] ?? 'Terjadi kesalahan pada layanan Duitku.';
        error_log('Duitku menolak pembuatan pembayaran: HTTP ' . $httpStatus . ' - ' . $msg);
        throw new RuntimeException('Duitku gagal membuat invoice ' . $paymentName . ': ' . $msg);
    }

    $reference = (string) ($response['reference'] ?? '');
    $paymentUrl = (string) ($response['paymentUrl'] ?? '');
    $vaNumber = isset($response['vaNumber']) && is_string($response['vaNumber']) && trim($response['vaNumber']) !== ''
        ? trim($response['vaNumber'])
        : null;
    $paymentCode = isset($response['paymentCode']) && is_string($response['paymentCode']) && trim($response['paymentCode']) !== ''
        ? trim($response['paymentCode'])
        : null;
    $qrString = isset($response['qrString']) && is_string($response['qrString']) && $response['qrString'] !== ''
        ? $response['qrString']
        : null;

    // Jika qrString diberikan (EMVCo QRIS string), render URL gambar QR
    $qrCodeUrl = null;
    if ($qrString !== null) {
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . rawurlencode($qrString);
    } elseif ($paymentUrl !== '' && ($channelInfo['type'] ?? '') === 'qris') {
        $qrCodeUrl = $paymentUrl;
    }

    return [
        'status_code' => $response['statusCode'],
        'status_message' => $response['statusMessage'] ?? 'SUCCESS',
        'channel_code' => $paymentMethodCode,
        'payment_name' => $paymentName,
        'category' => $channelInfo['category'] ?? 'duitku',
        'type' => $channelInfo['type'] ?? 'va',
        'reference' => $reference,
        'payment_url' => $paymentUrl,
        'va_number' => $vaNumber,
        'payment_code' => $paymentCode,
        'qr_string' => $qrString,
        'qr_code_url' => $qrCodeUrl,
    ];
}

/**
 * Buat transaksi pembayaran QRIS via Duitku API v2 Inquiry (kompatibilitas mundur).
 */
function duitkuCreateQrisPayment(
    string $orderId,
    int $amountRupiah,
    ?string $customerName = null,
    ?string $customerEmail = null,
    ?string $customerPhone = null
): array {
    return duitkuCreatePayment(
        $orderId,
        $amountRupiah,
        'SP',
        $customerName,
        $customerEmail,
        $customerPhone
    );
}

/**
 * Validasi keaslian signature notifikasi callback dari Duitku.
 * Formula Duitku: md5(merchantCode + amount + merchantOrderId + apiKey)
 */
function duitkuCallbackSignatureIsValid(array $notification): bool
{
    foreach (['merchantCode', 'amount', 'merchantOrderId', 'signature'] as $field) {
        if (!isset($notification[$field]) || !is_string($notification[$field])) {
            return false;
        }
    }

    $merchantCode = duitkuMerchantCode();
    $apiKey = duitkuApiKey();

    if ($notification['merchantCode'] !== $merchantCode) {
        return false;
    }

    $expectedSignature = md5(
        $merchantCode
        . $notification['amount']
        . $notification['merchantOrderId']
        . $apiKey
    );

    return hash_equals($expectedSignature, $notification['signature']);
}

/**
 * Cek status transaksi langsung ke server Duitku via API transactionStatus.
 */
function duitkuCheckTransactionStatus(string $orderId): ?array
{
    try {
        $merchantCode = duitkuMerchantCode();
        $apiKey = duitkuApiKey();
        $endpoint = duitkuStatusEndpoint();
    } catch (RuntimeException $e) {
        return null;
    }

    $signature = md5($merchantCode . $orderId . $apiKey);

    $payload = json_encode([
        'merchantCode' => $merchantCode,
        'merchantOrderId' => $orderId,
        'signature' => $signature,
    ]);

    $handle = curl_init($endpoint);
    if ($handle === false) {
        return null;
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen((string) $payload),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);

    $responseBody = curl_exec($handle);
    curl_close($handle);

    if (!is_string($responseBody)) {
        return null;
    }

    try {
        $data = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : null;
    } catch (JsonException $e) {
        return null;
    }
}

/**
 * Lepas stok yang ditahan bila transaksi QRIS Duitku gagal atau kedaluwarsa.
 */
function duitkuReleasePendingOrder(PDO $pdo, string $orderId, string $status): void
{
    if (!in_array($status, ['failed', 'expired'], true)) {
        throw new InvalidArgumentException('Status pelepasan stok tidak valid.');
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT id_qris_order, status_pembayaran
             FROM tbl_qris_order
             WHERE duitku_order_id = :order_id
             FOR UPDATE'
        );
        $statement->execute(['order_id' => $orderId]);
        $transaction = $statement->fetch();
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
             SET status_pembayaran = :status, duitku_status = :status_desc
             WHERE id_qris_order = :id_qris_order'
        );
        $updateStatement->execute([
            'status' => $status,
            'status_desc' => $status === 'expired' ? 'EXPIRED' : 'FAILED',
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