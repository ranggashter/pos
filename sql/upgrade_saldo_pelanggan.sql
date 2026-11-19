-- Tambahkan saldo pelanggan dan catat pemakaian saldo per transaksi.
-- Aman dijalankan ulang pada MariaDB/MySQL.

USE `db_tokokita`;

SET @balance_column_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_pelanggan'
    AND COLUMN_NAME = 'saldo'
);
SET @balance_column_ddl = IF(
  @balance_column_exists = 0,
  'ALTER TABLE `tbl_pelanggan` ADD COLUMN `saldo` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
  'SELECT 1'
);
PREPARE balance_column_statement FROM @balance_column_ddl;
EXECUTE balance_column_statement;
DEALLOCATE PREPARE balance_column_statement;

SET @balance_check_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_pelanggan'
    AND CONSTRAINT_NAME = 'chk_tbl_pelanggan_saldo'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @balance_check_ddl = IF(
  @balance_check_exists = 0,
  'ALTER TABLE `tbl_pelanggan` ADD CONSTRAINT `chk_tbl_pelanggan_saldo` CHECK (`saldo` >= 0)',
  'SELECT 1'
);
PREPARE balance_check_statement FROM @balance_check_ddl;
EXECUTE balance_check_statement;
DEALLOCATE PREPARE balance_check_statement;

SET @wallet_column_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND COLUMN_NAME = 'saldo_digunakan'
);
SET @wallet_column_ddl = IF(
  @wallet_column_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `saldo_digunakan` DECIMAL(12,2) NOT NULL DEFAULT 0.00',
  'SELECT 1'
);
PREPARE wallet_column_statement FROM @wallet_column_ddl;
EXECUTE wallet_column_statement;
DEALLOCATE PREPARE wallet_column_statement;

SET @wallet_check_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_transaksi'
    AND CONSTRAINT_NAME = 'chk_tbl_transaksi_saldo_digunakan'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @wallet_check_ddl = IF(
  @wallet_check_exists = 0,
  'ALTER TABLE `tbl_transaksi` ADD CONSTRAINT `chk_tbl_transaksi_saldo_digunakan` CHECK (`saldo_digunakan` >= 0)',
  'SELECT 1'
);
PREPARE wallet_check_statement FROM @wallet_check_ddl;
EXECUTE wallet_check_statement;
DEALLOCATE PREPARE wallet_check_statement;
