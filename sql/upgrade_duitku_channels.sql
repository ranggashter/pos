-- Upgrade Database Toko: Dukungan Metode Pembayaran Duitku (Transfer Bank / VA, E-Wallet, Retail Minimarket)
-- Aman dijalankan berulang kali.

ALTER TABLE `tbl_transaksi` MODIFY COLUMN `metode_pembayaran` VARCHAR(50) NOT NULL DEFAULT 'tunai';

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_channel'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE `tbl_transaksi` ADD COLUMN `duitku_channel` VARCHAR(20) DEFAULT NULL AFTER `duitku_status`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_va_number'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE `tbl_transaksi` ADD COLUMN `duitku_va_number` VARCHAR(100) DEFAULT NULL AFTER `duitku_channel`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_payment_name'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE `tbl_transaksi` ADD COLUMN `duitku_payment_name` VARCHAR(100) DEFAULT NULL AFTER `duitku_va_number`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_payment_code'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE `tbl_transaksi` ADD COLUMN `duitku_payment_code` VARCHAR(100) DEFAULT NULL AFTER `duitku_payment_name`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_qris_order' AND COLUMN_NAME = 'duitku_channel'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_channel` VARCHAR(20) DEFAULT NULL AFTER `duitku_status`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_qris_order' AND COLUMN_NAME = 'duitku_va_number'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_va_number` VARCHAR(100) DEFAULT NULL AFTER `duitku_channel`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_qris_order' AND COLUMN_NAME = 'duitku_payment_name'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_payment_name` VARCHAR(100) DEFAULT NULL AFTER `duitku_va_number`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_qris_order' AND COLUMN_NAME = 'duitku_payment_code'
);
SET @ddl = IF(@col_exists = 0, 'ALTER TABLE `tbl_qris_order` ADD COLUMN `duitku_payment_code` VARCHAR(100) DEFAULT NULL AFTER `duitku_payment_name`', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
