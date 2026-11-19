<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrfToken(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function takeFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function redirectToMaster(string $page = 'pelanggan'): never
{
    if (!in_array($page, [
        'dashboard_pelanggan',
        'pelanggan',
        'kategori',
        'produk',
        'kasir',
        'struk',
        'riwayat_transaksi',
        'detail_transaksi',
        'laporan',
    ], true)) {
        throw new InvalidArgumentException('Halaman master tidak dikenal.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Location: index.php?page=' . rawurlencode($page));
    exit;
}

function decimalToCents(mixed $value): int
{
    $amount = (string) $value;
    if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $amount, $matches) !== 1) {
        throw new InvalidArgumentException('Nilai uang tidak valid.');
    }

    $fraction = str_pad($matches[2] ?? '', 2, '0');
    return ((int) $matches[1] * 100) + (int) $fraction;
}

function centsToDecimal(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function formatRupiah(mixed $value): string
{
    $amount = (string) $value;
    if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $amount, $matches) !== 1) {
        throw new InvalidArgumentException('Nilai Rupiah tidak valid.');
    }

    $whole = ltrim($matches[1], '0');
    $whole = $whole === '' ? '0' : $whole;
    $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole) ?? $whole;
    $fraction = str_pad($matches[2] ?? '', 2, '0');

    return 'Rp ' . $whole . ',' . $fraction;
}
