-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 19, 2026 at 02:42 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `toko`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `CariPelanggan` (IN `p_id` INT)   BEGIN
    SELECT * 
    FROM tbl_pelanggan 
    WHERE id_pelanggan = p_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `CekTransaksi` (IN `p_id` INT)   BEGIN
    DECLARE jumlah INT;

    SELECT COUNT(*)
    INTO jumlah
    FROM tbl_transaksi
    WHERE id_pelanggan = p_id
      AND status_pembayaran = 'paid';

    IF jumlah > 0 THEN
        SELECT 'Pelanggan Memiliki Transaksi' AS keterangan;
    ELSE
        SELECT 'Belum Memiliki Transaksi' AS keterangan;
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `HitungPelanggan` (OUT `jumlah` INT)   BEGIN
    SELECT COUNT(*) 
    INTO jumlah 
    FROM tbl_pelanggan;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `LaporanTransaksi` (IN `p_id_pelanggan` INT)   BEGIN
    SELECT t.id_transaksi, t.tanggal, p.nama_pelanggan, p.kota, t.total_belanja
    FROM tbl_transaksi t
    JOIN tbl_pelanggan p ON t.id_pelanggan = p.id_pelanggan
    WHERE p.id_pelanggan = p_id_pelanggan
      AND t.status_pembayaran = 'paid';
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `TambahPelanggan` (IN `p_nama` VARCHAR(100), IN `p_kota` VARCHAR(50))   BEGIN
    INSERT INTO tbl_pelanggan (nama_pelanggan, kota)
    VALUES (p_nama, p_kota);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `TampilPelanggan` ()   BEGIN
    SELECT * FROM tbl_pelanggan;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `HitungDiskon` (`harga` DECIMAL(12,2), `persen_diskon` DECIMAL(5,2)) RETURNS DECIMAL(12,2) DETERMINISTIC BEGIN
    RETURN harga * (persen_diskon / 100);
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `HitungPajak` (`harga` DECIMAL(12,2)) RETURNS DECIMAL(12,2) DETERMINISTIC BEGIN
    RETURN harga * 0.11;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `HitungTotal` (`harga` DECIMAL(12,2), `jumlah` INT) RETURNS DECIMAL(12,2) DETERMINISTIC BEGIN
    RETURN harga * jumlah;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `KategoriBelanja` (`total` DECIMAL(12,2)) RETURNS VARCHAR(30) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC BEGIN
    RETURN CASE
        WHEN total >= 1000000 THEN 'Pelanggan Gold'
        WHEN total >= 500000 THEN 'Pelanggan Silver'
        ELSE 'Pelanggan Reguler'
    END;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `KonversiNilai` (`nilai` INT) RETURNS VARCHAR(5) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC BEGIN
    RETURN CASE
        WHEN nilai >= 85 THEN 'A'
        WHEN nilai >= 75 THEN 'B'
        WHEN nilai >= 60 THEN 'C'
        WHEN nilai >= 50 THEN 'D'
        ELSE 'E'
    END;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `log_pelanggan`
--

CREATE TABLE `log_pelanggan` (
  `id_log` int(11) NOT NULL,
  `aktivitas` varchar(50) DEFAULT NULL,
  `id_pelanggan` int(11) DEFAULT NULL,
  `waktu` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `log_pelanggan`
--

INSERT INTO `log_pelanggan` (`id_log`, `aktivitas`, `id_pelanggan`, `waktu`) VALUES
(1, 'INSERT PELANGGAN', 6, '2026-11-16 01:29:54'),
(2, 'INSERT PELANGGAN', 7, '2026-11-16 01:37:49'),
(3, 'INSERT PELANGGAN', 8, '2026-11-16 01:39:49');

-- --------------------------------------------------------

--
-- Table structure for table `log_perubahan_pelanggan`
--

CREATE TABLE `log_perubahan_pelanggan` (
  `id_log` int(11) NOT NULL,
  `id_pelanggan` int(11) DEFAULT NULL,
  `nama_lama` varchar(100) DEFAULT NULL,
  `nama_baru` varchar(100) DEFAULT NULL,
  `waktu` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `log_perubahan_pelanggan`
--

INSERT INTO `log_perubahan_pelanggan` (`id_log`, `id_pelanggan`, `nama_lama`, `nama_baru`, `waktu`) VALUES
(1, 4, 'Dewi', 'Rina Wijaya', '2026-11-16 01:30:25'),
(2, 4, 'Rina Wijaya', 'Dewi Lestari', '2026-11-16 01:39:53');

-- --------------------------------------------------------

--
-- Table structure for table `log_transaksi`
--

CREATE TABLE `log_transaksi` (
  `id_log` int(11) NOT NULL,
  `id_transaksi` int(11) DEFAULT NULL,
  `id_detail` int(11) DEFAULT NULL,
  `aktivitas` varchar(50) NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `waktu` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_cabang`
--

CREATE TABLE `tbl_cabang` (
  `id_cabang` int(11) NOT NULL,
  `nama_cabang` varchar(100) DEFAULT NULL,
  `kota` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_cabang`
--

INSERT INTO `tbl_cabang` (`id_cabang`, `nama_cabang`, `kota`) VALUES
(1, 'Cabang Utama', 'Ponorogo'),
(2, 'Cabang Timur', 'Madiun'),
(3, 'Cabang Selatan', 'Pacitan');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_detail_transaksi`
--

CREATE TABLE `tbl_detail_transaksi` (
  `id_detail` int(11) NOT NULL,
  `id_transaksi` int(11) NOT NULL,
  `id_produk` int(11) NOT NULL,
  `jumlah` decimal(12,3) NOT NULL,
  `harga_satuan` decimal(12,2) NOT NULL,
  `diskon` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL
) ;

--
-- Triggers `tbl_detail_transaksi`
--
DELIMITER $$
CREATE TRIGGER `after_delete_tbl_detail_transaksi` AFTER DELETE ON `tbl_detail_transaksi` FOR EACH ROW BEGIN
  INSERT INTO `log_transaksi`
    (`id_transaksi`, `id_detail`, `aktivitas`, `keterangan`)
  VALUES
    (OLD.`id_transaksi`, OLD.`id_detail`, 'DETAIL_DIHAPUS',
     CONCAT('Produk ID=', OLD.`id_produk`, ', jumlah=', OLD.`jumlah`,
            ', subtotal=', OLD.`subtotal`));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_insert_tbl_detail_transaksi` AFTER INSERT ON `tbl_detail_transaksi` FOR EACH ROW BEGIN
  INSERT INTO `log_transaksi`
    (`id_transaksi`, `id_detail`, `aktivitas`, `keterangan`)
  VALUES
    (NEW.`id_transaksi`, NEW.`id_detail`, 'DETAIL_DITAMBAHKAN',
     CONCAT('Produk ID=', NEW.`id_produk`, ', jumlah=', NEW.`jumlah`,
            ', subtotal=', NEW.`subtotal`));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_update_tbl_detail_transaksi` AFTER UPDATE ON `tbl_detail_transaksi` FOR EACH ROW BEGIN
  INSERT INTO `log_transaksi`
    (`id_transaksi`, `id_detail`, `aktivitas`, `keterangan`)
  VALUES
    (NEW.`id_transaksi`, NEW.`id_detail`, 'DETAIL_DIUBAH',
     CONCAT('Produk ID ', OLD.`id_produk`, ' menjadi ', NEW.`id_produk`,
            '; jumlah ', OLD.`jumlah`, ' menjadi ', NEW.`jumlah`));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_kategori`
--

CREATE TABLE `tbl_kategori` (
  `id_kategori` int(11) NOT NULL,
  `nama_kategori` varchar(100) NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `status_aktif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_kategori`
--

INSERT INTO `tbl_kategori` (`id_kategori`, `nama_kategori`, `keterangan`, `status_aktif`, `created_at`) VALUES
(1, 'ef', 'e', 1, '2026-11-19 01:41:01');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_pelanggan`
--

CREATE TABLE `tbl_pelanggan` (
  `id_pelanggan` int(11) NOT NULL,
  `nama_pelanggan` varchar(100) DEFAULT NULL,
  `kota` varchar(50) DEFAULT NULL,
  `rfid_uid` varchar(64) DEFAULT NULL,
  `diskon_persen` decimal(5,2) NOT NULL DEFAULT 0.00,
  `saldo` decimal(12,2) NOT NULL DEFAULT 0.00
) ;

--
-- Dumping data for table `tbl_pelanggan`
--

INSERT INTO `tbl_pelanggan` (`id_pelanggan`, `nama_pelanggan`, `kota`, `rfid_uid`, `diskon_persen`, `saldo`) VALUES
(1, 'Andi', 'Ponorogo', NULL, 0.00, 0.00),
(2, 'Budi', 'Madiun', NULL, 0.00, 0.00),
(3, 'Citra', 'Surabaya', NULL, 0.00, 0.00),
(4, 'Dewi Lestari', 'Ponorogo', NULL, 0.00, 0.00),
(5, 'Dewi', 'Ponorogo', NULL, 0.00, 0.00),
(6, 'Rina', 'Ponorogo', NULL, 0.00, 0.00),
(7, 'Siti', 'Ponorogo', NULL, 0.00, 0.00),
(8, 'Sinta', 'Madiun', NULL, 0.00, 0.00);

--
-- Triggers `tbl_pelanggan`
--
DELIMITER $$
CREATE TRIGGER `after_insert_pelanggan` AFTER INSERT ON `tbl_pelanggan` FOR EACH ROW BEGIN
    INSERT INTO log_pelanggan (aktivitas, id_pelanggan)
    VALUES ('INSERT PELANGGAN', NEW.id_pelanggan);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_update_pelanggan` AFTER UPDATE ON `tbl_pelanggan` FOR EACH ROW BEGIN
    INSERT INTO log_perubahan_pelanggan (id_pelanggan, nama_lama, nama_baru)
    VALUES (OLD.id_pelanggan, OLD.nama_pelanggan, NEW.nama_pelanggan);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_pengguna`
--

CREATE TABLE `tbl_pengguna` (
  `id_pengguna` int(11) NOT NULL,
  `nama_pengguna` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `peran` enum('admin','kasir') NOT NULL DEFAULT 'kasir',
  `status_aktif` tinyint(1) NOT NULL DEFAULT 1,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_pengguna`
--

INSERT INTO `tbl_pengguna` (`id_pengguna`, `nama_pengguna`, `username`, `password_hash`, `peran`, `status_aktif`, `dibuat_pada`) VALUES
(1, 'Administrator', 'admin', '$2y$10$oUrf7ViL9BHw9NqFWWrP6u7Vr9TTPmUXFrKOJUuzlQGkn/r6YQYaK', 'admin', 1, '2026-11-19 01:34:43'),
(2, 'Kasir Toko', 'kasir', '$2y$10$j4/kBa1L3eQ6v/h1Ou0XxeRaK/Qiq1mvBmVyNCriWicdA.kfZuybK', 'kasir', 1, '2026-11-19 01:34:43');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_produk`
--

CREATE TABLE `tbl_produk` (
  `id_produk` int(11) NOT NULL,
  `id_kategori` int(11) NOT NULL,
  `kode_produk` varchar(50) DEFAULT NULL,
  `nama_produk` varchar(150) NOT NULL,
  `harga` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stok` decimal(12,3) NOT NULL DEFAULT 0.000,
  `satuan` varchar(30) NOT NULL DEFAULT 'pcs',
  `keterangan` varchar(255) DEFAULT NULL,
  `status_aktif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `tbl_produk`
--

INSERT INTO `tbl_produk` (`id_produk`, `id_kategori`, `kode_produk`, `nama_produk`, `harga`, `stok`, `satuan`, `keterangan`, `status_aktif`, `created_at`) VALUES
(1, 1, NULL, '3e', 122345.00, 64.000, 'pcs', 'QW', 1, '2026-11-19 01:41:18');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_qris_order`
--

CREATE TABLE `tbl_qris_order` (
  `id_qris_order` int(11) NOT NULL,
  `duitku_order_id` varchar(50) DEFAULT NULL,
  `midtrans_order_id` varchar(50) DEFAULT NULL,
  `tanggal` date NOT NULL,
  `id_pelanggan` int(11) DEFAULT NULL,
  `total_belanja` decimal(12,2) NOT NULL,
  `status_pembayaran` varchar(30) NOT NULL DEFAULT 'pending',
  `duitku_reference` varchar(100) DEFAULT NULL,
  `duitku_payment_url` varchar(1000) DEFAULT NULL,
  `duitku_qr_string` text DEFAULT NULL,
  `duitku_qr_url` varchar(1000) DEFAULT NULL,
  `duitku_status` varchar(30) DEFAULT NULL,
  `duitku_channel` varchar(20) DEFAULT NULL,
  `duitku_va_number` varchar(100) DEFAULT NULL,
  `duitku_payment_name` varchar(100) DEFAULT NULL,
  `duitku_payment_code` varchar(100) DEFAULT NULL,
  `midtrans_redirect_url` varchar(1000) DEFAULT NULL,
  `midtrans_qr_url` varchar(1000) DEFAULT NULL,
  `midtrans_transaction_id` varchar(100) DEFAULT NULL,
  `midtrans_status` varchar(30) DEFAULT NULL,
  `id_transaksi` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `tbl_qris_order`
--

INSERT INTO `tbl_qris_order` (`id_qris_order`, `midtrans_order_id`, `tanggal`, `id_pelanggan`, `total_belanja`, `status_pembayaran`, `midtrans_redirect_url`, `midtrans_qr_url`, `midtrans_transaction_id`, `midtrans_status`, `id_transaksi`, `created_at`) VALUES
(1, 'TK-20261119024133-bba953090e81', '2026-11-19', NULL, 122345.00, 'pending', NULL, 'https://api.sandbox.midtrans.com/v2/qris/d71d5ed5-9b71-41fd-99d4-e828ff328985/qr-code', 'd71d5ed5-9b71-41fd-99d4-e828ff328985', NULL, NULL, '2026-11-19 01:41:33');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_qris_order_detail`
--

CREATE TABLE `tbl_qris_order_detail` (
  `id_qris_detail` int(11) NOT NULL,
  `id_qris_order` int(11) NOT NULL,
  `id_produk` int(11) NOT NULL,
  `jumlah` decimal(12,3) NOT NULL,
  `harga_satuan` decimal(12,2) NOT NULL,
  `diskon` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL
) ;

--
-- Dumping data for table `tbl_qris_order_detail`
--

INSERT INTO `tbl_qris_order_detail` (`id_qris_detail`, `id_qris_order`, `id_produk`, `jumlah`, `harga_satuan`, `diskon`, `subtotal`) VALUES
(1, 1, 1, 1.000, 122345.00, 0.00, 122345.00);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_transaksi`
--

CREATE TABLE `tbl_transaksi` (
  `id_transaksi` int(11) NOT NULL,
  `tanggal` date DEFAULT NULL,
  `id_pelanggan` int(11) DEFAULT NULL,
  `total_belanja` decimal(12,2) DEFAULT NULL,
  `uang_diterima` decimal(12,2) DEFAULT NULL,
  `saldo_digunakan` decimal(12,2) NOT NULL DEFAULT 0.00,
  `metode_pembayaran` varchar(50) NOT NULL DEFAULT 'tunai',
  `status_pembayaran` varchar(30) NOT NULL DEFAULT 'paid',
  `duitku_order_id` varchar(50) DEFAULT NULL,
  `duitku_reference` varchar(100) DEFAULT NULL,
  `duitku_status` varchar(30) DEFAULT NULL,
  `duitku_channel` varchar(20) DEFAULT NULL,
  `duitku_va_number` varchar(100) DEFAULT NULL,
  `duitku_payment_name` varchar(100) DEFAULT NULL,
  `duitku_payment_code` varchar(100) DEFAULT NULL,
  `midtrans_order_id` varchar(50) DEFAULT NULL,
  `midtrans_transaction_id` varchar(100) DEFAULT NULL,
  `midtrans_status` varchar(30) DEFAULT NULL
) ;

--
-- Dumping data for table `tbl_transaksi`
--

INSERT INTO `tbl_transaksi` (`id_transaksi`, `tanggal`, `id_pelanggan`, `total_belanja`, `uang_diterima`, `saldo_digunakan`, `metode_pembayaran`, `status_pembayaran`, `midtrans_order_id`, `midtrans_transaction_id`, `midtrans_status`) VALUES
(1, '2026-10-01', 1, 250000.00, NULL, 0.00, 'tunai', 'paid', NULL, NULL, NULL),
(2, '2026-10-02', 2, 350000.00, NULL, 0.00, 'tunai', 'paid', NULL, NULL, NULL),
(3, '2026-10-03', 1, 150000.00, NULL, 0.00, 'tunai', 'paid', NULL, NULL, NULL),
(4, '2026-10-04', 3, 500000.00, NULL, 0.00, 'tunai', 'paid', NULL, NULL, NULL);

--
-- Triggers `tbl_transaksi`
--
DELIMITER $$
CREATE TRIGGER `before_insert_transaksi` BEFORE INSERT ON `tbl_transaksi` FOR EACH ROW BEGIN
    IF NEW.total_belanja < 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Total belanja tidak boleh negatif';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_pelanggan`
-- (See below for the actual view)
--
CREATE TABLE `view_pelanggan` (
`id_pelanggan` int(11)
,`nama_pelanggan` varchar(100)
,`kota` varchar(50)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_total_belanja`
-- (See below for the actual view)
--
CREATE TABLE `view_total_belanja` (
`id_pelanggan` int(11)
,`nama_pelanggan` varchar(100)
,`total_transaksi` bigint(21)
,`total_belanja` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_transaksi_pelanggan`
-- (See below for the actual view)
--
CREATE TABLE `view_transaksi_pelanggan` (
`id_transaksi` int(11)
,`tanggal` date
,`nama_pelanggan` varchar(100)
,`kota` varchar(50)
,`total_belanja` decimal(12,2)
);

-- --------------------------------------------------------

--
-- Structure for view `view_pelanggan`
--
DROP TABLE IF EXISTS `view_pelanggan`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_pelanggan`  AS SELECT `tbl_pelanggan`.`id_pelanggan` AS `id_pelanggan`, `tbl_pelanggan`.`nama_pelanggan` AS `nama_pelanggan`, `tbl_pelanggan`.`kota` AS `kota` FROM `tbl_pelanggan` ;

-- --------------------------------------------------------

--
-- Structure for view `view_total_belanja`
--
DROP TABLE IF EXISTS `view_total_belanja`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_total_belanja`  AS SELECT `p`.`id_pelanggan` AS `id_pelanggan`, `p`.`nama_pelanggan` AS `nama_pelanggan`, count(`t`.`id_transaksi`) AS `total_transaksi`, coalesce(sum(`t`.`total_belanja`),0) AS `total_belanja` FROM (`tbl_pelanggan` `p` left join `tbl_transaksi` `t` on(`p`.`id_pelanggan` = `t`.`id_pelanggan` and `t`.`status_pembayaran` = 'paid')) GROUP BY `p`.`id_pelanggan`, `p`.`nama_pelanggan` ;

-- --------------------------------------------------------

--
-- Structure for view `view_transaksi_pelanggan`
--
DROP TABLE IF EXISTS `view_transaksi_pelanggan`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_transaksi_pelanggan`  AS SELECT `t`.`id_transaksi` AS `id_transaksi`, `t`.`tanggal` AS `tanggal`, `p`.`nama_pelanggan` AS `nama_pelanggan`, `p`.`kota` AS `kota`, `t`.`total_belanja` AS `total_belanja` FROM (`tbl_transaksi` `t` join `tbl_pelanggan` `p` on(`t`.`id_pelanggan` = `p`.`id_pelanggan`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `log_pelanggan`
--
ALTER TABLE `log_pelanggan`
  ADD PRIMARY KEY (`id_log`);

--
-- Indexes for table `log_perubahan_pelanggan`
--
ALTER TABLE `log_perubahan_pelanggan`
  ADD PRIMARY KEY (`id_log`);

--
-- Indexes for table `log_transaksi`
--
ALTER TABLE `log_transaksi`
  ADD PRIMARY KEY (`id_log`),
  ADD KEY `idx_log_transaksi_id_waktu` (`id_transaksi`,`waktu`),
  ADD KEY `idx_log_transaksi_id_detail` (`id_detail`);

--
-- Indexes for table `tbl_cabang`
--
ALTER TABLE `tbl_cabang`
  ADD PRIMARY KEY (`id_cabang`);

--
-- Indexes for table `tbl_detail_transaksi`
--
ALTER TABLE `tbl_detail_transaksi`
  ADD PRIMARY KEY (`id_detail`),
  ADD KEY `idx_tbl_detail_transaksi_id_transaksi` (`id_transaksi`),
  ADD KEY `idx_tbl_detail_transaksi_id_produk` (`id_produk`);

--
-- Indexes for table `tbl_kategori`
--
ALTER TABLE `tbl_kategori`
  ADD PRIMARY KEY (`id_kategori`),
  ADD KEY `idx_tbl_kategori_nama` (`nama_kategori`);

--
-- Indexes for table `tbl_pelanggan`
--
ALTER TABLE `tbl_pelanggan`
  ADD PRIMARY KEY (`id_pelanggan`),
  ADD UNIQUE KEY `uk_tbl_pelanggan_rfid_uid` (`rfid_uid`);

--
-- Indexes for table `tbl_pengguna`
--
ALTER TABLE `tbl_pengguna`
  ADD PRIMARY KEY (`id_pengguna`),
  ADD UNIQUE KEY `uk_tbl_pengguna_username` (`username`),
  ADD KEY `idx_tbl_pengguna_peran_status` (`peran`,`status_aktif`);

--
-- Indexes for table `tbl_produk`
--
ALTER TABLE `tbl_produk`
  ADD PRIMARY KEY (`id_produk`),
  ADD UNIQUE KEY `uk_tbl_produk_kode_produk` (`kode_produk`),
  ADD KEY `idx_tbl_produk_id_kategori` (`id_kategori`),
  ADD KEY `idx_tbl_produk_nama` (`nama_produk`);

--
-- Indexes for table `tbl_qris_order`
--
ALTER TABLE `tbl_qris_order`
  ADD PRIMARY KEY (`id_qris_order`),
  ADD UNIQUE KEY `uk_tbl_qris_order_duitku_order_id` (`duitku_order_id`),
  ADD UNIQUE KEY `uk_tbl_qris_order_midtrans_order_id` (`midtrans_order_id`),
  ADD KEY `idx_tbl_qris_order_status_created` (`status_pembayaran`,`created_at`),
  ADD KEY `fk_tbl_qris_order_pelanggan` (`id_pelanggan`),
  ADD KEY `fk_tbl_qris_order_transaksi` (`id_transaksi`);

--
-- Indexes for table `tbl_qris_order_detail`
--
ALTER TABLE `tbl_qris_order_detail`
  ADD PRIMARY KEY (`id_qris_detail`),
  ADD KEY `idx_tbl_qris_order_detail_order` (`id_qris_order`),
  ADD KEY `fk_tbl_qris_order_detail_produk` (`id_produk`);

--
-- Indexes for table `tbl_transaksi`
--
ALTER TABLE `tbl_transaksi`
  ADD PRIMARY KEY (`id_transaksi`),
  ADD UNIQUE KEY `uk_tbl_transaksi_duitku_order_id` (`duitku_order_id`),
  ADD UNIQUE KEY `uk_tbl_transaksi_midtrans_order_id` (`midtrans_order_id`),
  ADD KEY `id_pelanggan` (`id_pelanggan`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `log_pelanggan`
--
ALTER TABLE `log_pelanggan`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `log_perubahan_pelanggan`
--
ALTER TABLE `log_perubahan_pelanggan`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `log_transaksi`
--
ALTER TABLE `log_transaksi`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_cabang`
--
ALTER TABLE `tbl_cabang`
  MODIFY `id_cabang` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_detail_transaksi`
--
ALTER TABLE `tbl_detail_transaksi`
  MODIFY `id_detail` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_kategori`
--
ALTER TABLE `tbl_kategori`
  MODIFY `id_kategori` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_pelanggan`
--
ALTER TABLE `tbl_pelanggan`
  MODIFY `id_pelanggan` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_pengguna`
--
ALTER TABLE `tbl_pengguna`
  MODIFY `id_pengguna` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tbl_produk`
--
ALTER TABLE `tbl_produk`
  MODIFY `id_produk` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_qris_order`
--
ALTER TABLE `tbl_qris_order`
  MODIFY `id_qris_order` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_qris_order_detail`
--
ALTER TABLE `tbl_qris_order_detail`
  MODIFY `id_qris_detail` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_transaksi`
--
ALTER TABLE `tbl_transaksi`
  MODIFY `id_transaksi` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `log_transaksi`
--
ALTER TABLE `log_transaksi`
  ADD CONSTRAINT `fk_log_transaksi_tbl_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `tbl_transaksi` (`id_transaksi`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tbl_detail_transaksi`
--
ALTER TABLE `tbl_detail_transaksi`
  ADD CONSTRAINT `fk_tbl_detail_transaksi_tbl_produk` FOREIGN KEY (`id_produk`) REFERENCES `tbl_produk` (`id_produk`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tbl_detail_transaksi_tbl_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `tbl_transaksi` (`id_transaksi`) ON UPDATE CASCADE;

--
-- Constraints for table `tbl_produk`
--
ALTER TABLE `tbl_produk`
  ADD CONSTRAINT `fk_tbl_produk_tbl_kategori` FOREIGN KEY (`id_kategori`) REFERENCES `tbl_kategori` (`id_kategori`) ON UPDATE CASCADE;

--
-- Constraints for table `tbl_qris_order`
--
ALTER TABLE `tbl_qris_order`
  ADD CONSTRAINT `fk_tbl_qris_order_pelanggan` FOREIGN KEY (`id_pelanggan`) REFERENCES `tbl_pelanggan` (`id_pelanggan`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tbl_qris_order_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `tbl_transaksi` (`id_transaksi`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tbl_qris_order_detail`
--
ALTER TABLE `tbl_qris_order_detail`
  ADD CONSTRAINT `fk_tbl_qris_order_detail_order` FOREIGN KEY (`id_qris_order`) REFERENCES `tbl_qris_order` (`id_qris_order`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tbl_qris_order_detail_produk` FOREIGN KEY (`id_produk`) REFERENCES `tbl_produk` (`id_produk`) ON UPDATE CASCADE;

--
-- Constraints for table `tbl_transaksi`
--
ALTER TABLE `tbl_transaksi`
  ADD CONSTRAINT `tbl_transaksi_ibfk_1` FOREIGN KEY (`id_pelanggan`) REFERENCES `tbl_pelanggan` (`id_pelanggan`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
