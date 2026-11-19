-- Migrasi Payment Gateway dari Midtrans ke Duitku
-- Aman dijalankan ulang pada MariaDB/MySQL.

-- 1. Tambahkan kolom Duitku pada tbl_transaksi
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'duitku_order_id'
);
SET @ddl = IF(
  @col_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `duitku_order_id` VARCHAR(50) DEFAULT NULL AFTER `status_pembayaran`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'duitku_reference'
);
SET @ddl = IF(
  @col_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `duitku_reference` VARCHAR(100) DEFAULT NULL AFTER `duitku_order_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'duitku_status'
);
SET @ddl = IF(
  @col_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `duitku_status` VARCHAR(30) DEFAULT NULL AFTER `duitku_reference`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND INDEX_NAME = 'uk_tbl_transaksi_duitku_order_id'
);
SET @ddl = IF(
  @idx_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD UNIQUE KEY `uk_tbl_transaksi_duitku_order_id` (`duitku_order_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Salin data lama jika ada
UPDATE `tbl_transaksi`
SET `duitku_order_id` = `midtrans_order_id`,
    `duitku_reference` = `midtrans_transaction_id`,
    `duitku_status` = `midtrans_status`
WHERE `duitku_order_id` IS NULL AND `midtrans_order_id` IS NOT NULL;


-- 2. Tambahkan kolom Duitku pada tbl_qris_order
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_qris_order'
    AND COLUMN_NAME = 'duitku_order_id'
);
SET @ddl = IF(
  @col_exists = 0,
  'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_order_id` VARCHAR(50) DEFAULT NULL AFTER `id_qris_order`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_qris_order'
    AND COLUMN_NAME = 'duitku_reference'
);
SET @ddl = IF(
  @col_exists = 0,
  'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_reference` VARCHAR(100) DEFAULT NULL AFTER `status_pembayaran`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_qris_order'
    AND COLUMN_NAME = 'duitku_payment_url'
);
SET @ddl = IF(
  @col_exists = 0,
  'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_payment_url` VARCHAR(1000) DEFAULT NULL AFTER `duitku_reference`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_qris_order'
    AND COLUMN_NAME = 'duitku_qr_string'
);
SET @ddl = IF(
  @col_exists = 0,
  'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_qr_string` TEXT DEFAULT NULL AFTER `duitku_payment_url`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_qris_order'
    AND COLUMN_NAME = 'duitku_qr_url'
);
SET @ddl = IF(
  @col_exists = 0,
  'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_qr_url` VARCHAR(1000) DEFAULT NULL AFTER `duitku_qr_string`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_qris_order'
    AND COLUMN_NAME = 'duitku_status'
);
SET @ddl = IF(
  @col_exists = 0,
  'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_status` VARCHAR(30) DEFAULT NULL AFTER `duitku_qr_url`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_qris_order'
    AND INDEX_NAME = 'uk_tbl_qris_order_duitku_order_id'
);
SET @ddl = IF(
  @idx_exists = 0,
  'ALTER TABLE `tbl_qris_order` ADD UNIQUE KEY `uk_tbl_qris_order_duitku_order_id` (`duitku_order_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Pastikan midtrans_order_id boleh NULL agar order baru berbasis Duitku tidak bentrok
ALTER TABLE `tbl_qris_order` MODIFY COLUMN `midtrans_order_id` VARCHAR(50) DEFAULT NULL;

-- Salin data lama di tbl_qris_order jika ada
UPDATE `tbl_qris_order`
SET `duitku_order_id` = `midtrans_order_id`,
    `duitku_reference` = `midtrans_transaction_id`,
    `duitku_payment_url` = `midtrans_redirect_url`,
    `duitku_qr_url` = `midtrans_qr_url`,
    `duitku_status` = `midtrans_status`
WHERE `duitku_order_id` IS NULL AND `midtrans_order_id` IS NOT NULL;
