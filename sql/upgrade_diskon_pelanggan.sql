-- Tambahkan pengaturan persentase diskon pada data pelanggan.
-- Nilai ini disimpan sebagai profil dan tidak mengubah perhitungan checkout.
-- Aman dijalankan ulang pada MariaDB/MySQL.

USE `db_tokokita`;

SET @discount_column_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_pelanggan'
    AND COLUMN_NAME = 'diskon_persen'
);
SET @discount_column_ddl = IF(
  @discount_column_exists = 0,
  'ALTER TABLE `tbl_pelanggan` ADD COLUMN `diskon_persen` DECIMAL(5,2) NOT NULL DEFAULT 0.00',
  'SELECT 1'
);
PREPARE discount_column_statement FROM @discount_column_ddl;
EXECUTE discount_column_statement;
DEALLOCATE PREPARE discount_column_statement;

SET @discount_check_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tbl_pelanggan'
    AND CONSTRAINT_NAME = 'chk_tbl_pelanggan_diskon_persen'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @discount_check_ddl = IF(
  @discount_check_exists = 0,
  'ALTER TABLE `tbl_pelanggan` ADD CONSTRAINT `chk_tbl_pelanggan_diskon_persen` CHECK (`diskon_persen` >= 0 AND `diskon_persen` <= 100)',
  'SELECT 1'
);
PREPARE discount_check_statement FROM @discount_check_ddl;
EXECUTE discount_check_statement;
DEALLOCATE PREPARE discount_check_statement;
