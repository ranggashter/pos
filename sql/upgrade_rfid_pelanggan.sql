-- Tambahkan UID kartu member RFID ke data pelanggan.
-- Aman dijalankan ulang pada MariaDB/MySQL.

USE `db_tokokita`;

SET @rfid_column_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_pelanggan'
    AND COLUMN_NAME = 'rfid_uid'
);
SET @rfid_column_ddl = IF(
  @rfid_column_exists = 0,
  'ALTER TABLE `tbl_pelanggan` ADD COLUMN `rfid_uid` VARCHAR(64) DEFAULT NULL AFTER `password_hash`',
  'SELECT 1'
);
PREPARE rfid_column_statement FROM @rfid_column_ddl;
EXECUTE rfid_column_statement;
DEALLOCATE PREPARE rfid_column_statement;

SET @rfid_index_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_pelanggan'
    AND INDEX_NAME = 'uk_tbl_pelanggan_rfid_uid'
);
SET @rfid_index_ddl = IF(
  @rfid_index_exists = 0,
  'CREATE UNIQUE INDEX `uk_tbl_pelanggan_rfid_uid` ON `tbl_pelanggan` (`rfid_uid`)',
  'SELECT 1'
);
PREPARE rfid_index_statement FROM @rfid_index_ddl;
EXECUTE rfid_index_statement;
DEALLOCATE PREPARE rfid_index_statement;
