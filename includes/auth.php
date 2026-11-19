<?php
declare(strict_types=1);

function isAuthenticated(): bool
{
    return isset(
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['user_name'],
        $_SESSION['user_role']
    );
}

function canAccessPage(string $role, string $page): bool
{
    if ($role === 'admin') {
        return in_array($page, [
            'pelanggan',
            'kategori',
            'produk',
            'kasir',
            'struk',
            'kasir_qris',
            'riwayat_transaksi',
            'detail_transaksi',
            'laporan',
        ], true);
    }

    if ($role === 'kasir') {
        return in_array($page, [
            'kasir',
            'struk',
            'kasir_qris',
            'riwayat_transaksi',
            'detail_transaksi',
        ], true);
    }

    if ($role === 'pelanggan') {
        return $page === 'dashboard_pelanggan';
    }

    return false;
}

function roleHomePage(string $role): string
{
    if ($role === 'admin') {
        return 'laporan';
    }
    if ($role === 'pelanggan') {
        return 'dashboard_pelanggan';
    }
    return 'kasir';
}
