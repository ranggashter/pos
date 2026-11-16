<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$navigation = $_SESSION['user_role'] === 'admin'
    ? [
        'pelanggan' => [
            'label' => 'Master Pelanggan',
            'icon' => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>'
        ],
        'kategori' => [
            'label' => 'Master Kategori',
            'icon' => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>'
        ],
        'produk' => [
            'label' => 'Master Produk',
            'icon' => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>'
        ],
        'kasir' => [
            'label' => 'Kasir (POS)',
            'icon' => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" /></svg>'
        ],
        'riwayat_transaksi' => [
            'label' => 'Riwayat Transaksi',
            'icon' => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
        ],
        'laporan' => [
            'label' => 'Laporan Penjualan',
            'icon' => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>'
        ],
    ]
    : [
        'kasir' => [
            'label' => 'Kasir (POS)',
            'icon' => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" /></svg>'
        ],
        'riwayat_transaksi' => [
            'label' => 'Riwayat Transaksi',
            'icon' => '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
        ],
    ];

$userName = (string) ($_SESSION['user_name'] ?? 'Pengguna');
$userRole = (string) ($_SESSION['user_role'] ?? 'kasir');
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1, 'UTF-8'), 'UTF-8');
?>

<div id="sidebar-backdrop" class="sidebar-backdrop"></div>

<aside id="app-sidebar" class="sidebar">
    <a class="brand-wrap" href="index.php" title="Halaman Utama Toko Kita">
        <div class="brand-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <path d="M16 10a4 4 0 0 1-8 0"></path>
            </svg>
        </div>
        <div class="brand-text">
            <h2>Toko Kita</h2>
            <span>Point of Sale</span>
        </div>
    </a>

    <nav aria-label="Navigasi Utama">
        <div class="nav-section-title">Menu Navigasi</div>
        <?php foreach ($navigation as $route => $item): ?>
            <?php
            $isActive = $page === $route
                || ($route === 'riwayat_transaksi' && ($page === 'detail_transaksi' || $page === 'struk'));
            ?>
            <a
                class="nav-link<?= $isActive ? ' active' : '' ?>"
                href="index.php?page=<?= e($route) ?>"
                <?= $isActive ? 'aria-current="page"' : '' ?>
            >
                <?= $item['icon'] ?>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-user">
        <div class="user-card">
            <div class="user-avatar" title="<?= e($userName) ?>">
                <?= e($userInitial) ?>
            </div>
            <div class="user-info">
                <span class="user-name" title="<?= e($userName) ?>"><?= e($userName) ?></span>
                <span class="user-role-badge"><?= e($userRole) ?></span>
            </div>
            <form method="post" action="index.php?page=logout" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <button class="sidebar-logout-btn" type="submit" title="Keluar dari akun">
                    <svg width="17" height="17" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</aside>
