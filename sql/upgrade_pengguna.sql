-- Tabel autentikasi pengguna
USE `db_tokokita`;

CREATE TABLE IF NOT EXISTS `tbl_pengguna` (
  `id_pengguna` int(11) NOT NULL AUTO_INCREMENT,
  `nama_pengguna` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `peran` enum('admin', 'kasir') NOT NULL DEFAULT 'kasir',
  `status_aktif` tinyint(1) NOT NULL DEFAULT 1,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_pengguna`),
  UNIQUE KEY `uk_tbl_pengguna_username` (`username`),
  KEY `idx_tbl_pengguna_peran_status` (`peran`, `status_aktif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Akun bawaan:
-- 1. Username: admin | Password plain text: admin (tersimpan dalam bentuk Bcrypt Hash)
-- 2. Username: kasir | Password plain text: kasir (tersimpan dalam bentuk Bcrypt Hash)
INSERT INTO `tbl_pengguna` (`nama_pengguna`, `username`, `password_hash`, `peran`, `status_aktif`)
VALUES
  ('Administrator', 'admin', '$2y$10$3KbmQTC1ojmZi/gJIt88TuhuT78nmFZjS.tvXR1XYMDWqolMhsWgu', 'admin', 1),
  ('Kasir Toko', 'kasir', '$2y$10$GF1li/PrLCEbOcY.nPr94.X6EjNOGGSF2kggE21igy7a1/rEQjnki', 'kasir', 1)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `status_aktif` = 1;
