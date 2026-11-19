-- Tambahkan metode pembayaran tunai/member/QRIS dan status Midtrans.
-- Aman dijalankan ulang pada MariaDB/MySQL.

USE `db_tokokita`;

SET @payment_method_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'metode_pembayaran'
);
SET @payment_method_ddl = IF(
  @payment_method_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `metode_pembayaran` VARCHAR(20) NOT NULL DEFAULT ''tunai''',
  'SELECT 1'
);
PREPARE payment_method_statement FROM @payment_method_ddl;
EXECUTE payment_method_statement;
DEALLOCATE PREPARE payment_method_statement;

SET @payment_status_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'status_pembayaran'
);
SET @payment_status_ddl = IF(
  @payment_status_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `status_pembayaran` VARCHAR(30) NOT NULL DEFAULT ''paid''',
  'SELECT 1'
);
PREPARE payment_status_statement FROM @payment_status_ddl;
EXECUTE payment_status_statement;
DEALLOCATE PREPARE payment_status_statement;

SET @midtrans_order_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'midtrans_order_id'
);
SET @midtrans_order_ddl = IF(
  @midtrans_order_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `midtrans_order_id` VARCHAR(50) DEFAULT NULL',
  'SELECT 1'
);
PREPARE midtrans_order_statement FROM @midtrans_order_ddl;
EXECUTE midtrans_order_statement;
DEALLOCATE PREPARE midtrans_order_statement;

SET @midtrans_txn_id_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'midtrans_transaction_id'
);
SET @midtrans_txn_id_ddl = IF(
  @midtrans_txn_id_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `midtrans_transaction_id` VARCHAR(100) DEFAULT NULL',
  'SELECT 1'
);
PREPARE midtrans_txn_id_statement FROM @midtrans_txn_id_ddl;
EXECUTE midtrans_txn_id_statement;
DEALLOCATE PREPARE midtrans_txn_id_statement;

SET @midtrans_status_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'midtrans_status'
);
SET @midtrans_status_ddl = IF(
  @midtrans_status_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `midtrans_status` VARCHAR(30) DEFAULT NULL',
  'SELECT 1'
);
PREPARE midtrans_status_statement FROM @midtrans_status_ddl;
EXECUTE midtrans_status_statement;
DEALLOCATE PREPARE midtrans_status_statement;

SET @midtrans_order_key_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND INDEX_NAME = 'uk_tbl_transaksi_midtrans_order_id'
);
SET @midtrans_order_key_ddl = IF(
  @midtrans_order_key_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD UNIQUE KEY `uk_tbl_transaksi_midtrans_order_id` (`midtrans_order_id`)',
  'SELECT 1'
);
PREPARE midtrans_order_key_statement FROM @midtrans_order_key_ddl;
EXECUTE midtrans_order_key_statement;
DEALLOCATE PREPARE midtrans_order_key_statement;

CREATE TABLE IF NOT EXISTS `tbl_qris_order` (
  `id_qris_order` int(11) NOT NULL AUTO_INCREMENT,
  `midtrans_order_id` varchar(50) NOT NULL,
  `tanggal` date NOT NULL,
  `id_pelanggan` int(11) DEFAULT NULL,
  `total_belanja` decimal(12,2) NOT NULL,
  `status_pembayaran` varchar(30) NOT NULL DEFAULT 'pending',
  `midtrans_redirect_url` varchar(1000) DEFAULT NULL,
  `midtrans_qr_url` varchar(1000) DEFAULT NULL,
  `midtrans_transaction_id` varchar(100) DEFAULT NULL,
  `midtrans_status` varchar(30) DEFAULT NULL,
  `id_transaksi` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_qris_order`),
  UNIQUE KEY `uk_tbl_qris_order_midtrans_order_id` (`midtrans_order_id`),
  KEY `idx_tbl_qris_order_status_created` (`status_pembayaran`, `created_at`),
  CONSTRAINT `fk_tbl_qris_order_pelanggan`
    FOREIGN KEY (`id_pelanggan`) REFERENCES `tbl_pelanggan` (`id_pelanggan`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_tbl_qris_order_transaksi`
    FOREIGN KEY (`id_transaksi`) REFERENCES `tbl_transaksi` (`id_transaksi`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `chk_tbl_qris_order_total` CHECK (`total_belanja` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @midtrans_qr_url_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_qris_order'
    AND COLUMN_NAME = 'midtrans_qr_url'
);
SET @midtrans_qr_url_ddl = IF(
  @midtrans_qr_url_exists = 0,
  'ALTER TABLE `tbl_qris_order` ADD COLUMN `midtrans_qr_url` VARCHAR(1000) DEFAULT NULL AFTER `midtrans_redirect_url`',
  'SELECT 1'
);
PREPARE midtrans_qr_url_statement FROM @midtrans_qr_url_ddl;
EXECUTE midtrans_qr_url_statement;
DEALLOCATE PREPARE midtrans_qr_url_statement;

CREATE TABLE IF NOT EXISTS `tbl_qris_order_detail` (
  `id_qris_detail` int(11) NOT NULL AUTO_INCREMENT,
  `id_qris_order` int(11) NOT NULL,
  `id_produk` int(11) NOT NULL,
  `jumlah` decimal(12,3) NOT NULL,
  `harga_satuan` decimal(12,2) NOT NULL,
  `diskon` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id_qris_detail`),
  KEY `idx_tbl_qris_order_detail_order` (`id_qris_order`),
  CONSTRAINT `fk_tbl_qris_order_detail_order`
    FOREIGN KEY (`id_qris_order`) REFERENCES `tbl_qris_order` (`id_qris_order`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_tbl_qris_order_detail_produk`
    FOREIGN KEY (`id_produk`) REFERENCES `tbl_produk` (`id_produk`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `chk_tbl_qris_order_detail_jumlah` CHECK (`jumlah` > 0),
  CONSTRAINT `chk_tbl_qris_order_detail_harga` CHECK (`harga_satuan` >= 0),
  CONSTRAINT `chk_tbl_qris_order_detail_diskon` CHECK (`diskon` >= 0),
  CONSTRAINT `chk_tbl_qris_order_detail_subtotal` CHECK (`subtotal` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE OR REPLACE VIEW `view_total_belanja` AS
SELECT p.id_pelanggan, p.nama_pelanggan,
       COUNT(t.id_transaksi) AS total_transaksi,
       COALESCE(SUM(t.total_belanja), 0) AS total_belanja
FROM tbl_pelanggan p
LEFT JOIN tbl_transaksi t
  ON p.id_pelanggan = t.id_pelanggan
 AND t.status_pembayaran = 'paid'
GROUP BY p.id_pelanggan, p.nama_pelanggan;

DELIMITER $$

DROP PROCEDURE IF EXISTS `CekTransaksi`$$
CREATE PROCEDURE `CekTransaksi`(IN `p_id` INT)
BEGIN
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

DROP PROCEDURE IF EXISTS `LaporanTransaksi`$$
CREATE PROCEDURE `LaporanTransaksi`(IN `p_id_pelanggan` INT)
BEGIN
    SELECT t.id_transaksi, t.tanggal, p.nama_pelanggan, p.kota, t.total_belanja
    FROM tbl_transaksi t
    JOIN tbl_pelanggan p ON t.id_pelanggan = p.id_pelanggan
    WHERE p.id_pelanggan = p_id_pelanggan
      AND t.status_pembayaran = 'paid';
END$$

DELIMITER ;
