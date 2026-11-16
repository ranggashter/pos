-- Upgrade tambahan untuk aplikasi kasir db_tokokita.
-- Aman dijalankan ulang pada skema yang dibuat oleh skrip ini.
-- Tidak mengubah atau menghapus tabel, data, view, procedure, function,
-- maupun trigger existing.

USE `db_tokokita`;

SET @payment_column_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'uang_diterima'
);
SET @payment_column_ddl = IF(
  @payment_column_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `uang_diterima` DECIMAL(12,2) DEFAULT NULL AFTER `total_belanja`',
  'SELECT 1'
);
PREPARE payment_column_statement FROM @payment_column_ddl;
EXECUTE payment_column_statement;
DEALLOCATE PREPARE payment_column_statement;

CREATE TABLE IF NOT EXISTS `tbl_kategori` (
  `id_kategori` int(11) NOT NULL AUTO_INCREMENT,
  `nama_kategori` varchar(100) NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `status_aktif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_kategori`),
  KEY `idx_tbl_kategori_nama` (`nama_kategori`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_produk` (
  `id_produk` int(11) NOT NULL AUTO_INCREMENT,
  `id_kategori` int(11) NOT NULL,
  `kode_produk` varchar(50) DEFAULT NULL,
  `nama_produk` varchar(150) NOT NULL,
  `harga` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stok` decimal(12,3) NOT NULL DEFAULT 0.000,
  `satuan` varchar(30) NOT NULL DEFAULT 'pcs',
  `keterangan` varchar(255) DEFAULT NULL,
  `status_aktif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_produk`),
  UNIQUE KEY `uk_tbl_produk_kode_produk` (`kode_produk`),
  KEY `idx_tbl_produk_id_kategori` (`id_kategori`),
  KEY `idx_tbl_produk_nama` (`nama_produk`),
  CONSTRAINT `fk_tbl_produk_tbl_kategori`
    FOREIGN KEY (`id_kategori`) REFERENCES `tbl_kategori` (`id_kategori`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `chk_tbl_produk_harga` CHECK (`harga` >= 0),
  CONSTRAINT `chk_tbl_produk_stok` CHECK (`stok` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_detail_transaksi` (
  `id_detail` int(11) NOT NULL AUTO_INCREMENT,
  `id_transaksi` int(11) NOT NULL,
  `id_produk` int(11) NOT NULL,
  `jumlah` decimal(12,3) NOT NULL,
  `harga_satuan` decimal(12,2) NOT NULL,
  `diskon` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id_detail`),
  KEY `idx_tbl_detail_transaksi_id_transaksi` (`id_transaksi`),
  KEY `idx_tbl_detail_transaksi_id_produk` (`id_produk`),
  CONSTRAINT `fk_tbl_detail_transaksi_tbl_transaksi`
    FOREIGN KEY (`id_transaksi`) REFERENCES `tbl_transaksi` (`id_transaksi`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_tbl_detail_transaksi_tbl_produk`
    FOREIGN KEY (`id_produk`) REFERENCES `tbl_produk` (`id_produk`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `chk_tbl_detail_transaksi_jumlah` CHECK (`jumlah` > 0),
  CONSTRAINT `chk_tbl_detail_transaksi_harga` CHECK (`harga_satuan` >= 0),
  CONSTRAINT `chk_tbl_detail_transaksi_diskon` CHECK (`diskon` >= 0),
  CONSTRAINT `chk_tbl_detail_transaksi_subtotal` CHECK (`subtotal` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Log menyimpan riwayat meskipun transaksi dihapus; id_transaksi menjadi NULL.
-- id_detail sengaja tidak diberi FK agar riwayat penghapusan detail tetap utuh.
CREATE TABLE IF NOT EXISTS `log_transaksi` (
  `id_log` int(11) NOT NULL AUTO_INCREMENT,
  `id_transaksi` int(11) DEFAULT NULL,
  `id_detail` int(11) DEFAULT NULL,
  `aktivitas` varchar(50) NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `waktu` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_log`),
  KEY `idx_log_transaksi_id_waktu` (`id_transaksi`, `waktu`),
  KEY `idx_log_transaksi_id_detail` (`id_detail`),
  CONSTRAINT `fk_log_transaksi_tbl_transaksi`
    FOREIGN KEY (`id_transaksi`) REFERENCES `tbl_transaksi` (`id_transaksi`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DELIMITER $$

CREATE TRIGGER IF NOT EXISTS `after_insert_tbl_detail_transaksi`
AFTER INSERT ON `tbl_detail_transaksi`
FOR EACH ROW
BEGIN
  INSERT INTO `log_transaksi`
    (`id_transaksi`, `id_detail`, `aktivitas`, `keterangan`)
  VALUES
    (NEW.`id_transaksi`, NEW.`id_detail`, 'DETAIL_DITAMBAHKAN',
     CONCAT('Produk ID=', NEW.`id_produk`, ', jumlah=', NEW.`jumlah`,
            ', subtotal=', NEW.`subtotal`));
END$$

CREATE TRIGGER IF NOT EXISTS `after_update_tbl_detail_transaksi`
AFTER UPDATE ON `tbl_detail_transaksi`
FOR EACH ROW
BEGIN
  INSERT INTO `log_transaksi`
    (`id_transaksi`, `id_detail`, `aktivitas`, `keterangan`)
  VALUES
    (NEW.`id_transaksi`, NEW.`id_detail`, 'DETAIL_DIUBAH',
     CONCAT('Produk ID ', OLD.`id_produk`, ' menjadi ', NEW.`id_produk`,
            '; jumlah ', OLD.`jumlah`, ' menjadi ', NEW.`jumlah`));
END$$

CREATE TRIGGER IF NOT EXISTS `after_delete_tbl_detail_transaksi`
AFTER DELETE ON `tbl_detail_transaksi`
FOR EACH ROW
BEGIN
  INSERT INTO `log_transaksi`
    (`id_transaksi`, `id_detail`, `aktivitas`, `keterangan`)
  VALUES
    (OLD.`id_transaksi`, OLD.`id_detail`, 'DETAIL_DIHAPUS',
     CONCAT('Produk ID=', OLD.`id_produk`, ', jumlah=', OLD.`jumlah`,
            ', subtotal=', OLD.`subtotal`));
END$$

DELIMITER ;
