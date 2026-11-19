# Aplikasi Kasir Toko Kita

Aplikasi kasir berbasis PHP dan MariaDB/MySQL untuk dijalankan secara lokal dengan XAMPP. Database utama bernama `toko` (atau `db_tokokita`).

## Instalasi XAMPP

1. Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel.
2. Buka phpMyAdmin di `http://localhost/phpmyadmin/`, buat database `toko` bila belum ada, lalu pilih database tersebut.
3. Impor file berikut secara berurutan melalui tab **Import**:
   1. `toko.sql` (atau `db_tokokita.sql`) — skema existing pelanggan, transaksi, view, procedure, function, trigger, dan akun pengguna.
   2. `sql/upgrade_kasir.sql` — kolom pembayaran pada transaksi serta tabel kategori, produk, detail transaksi, dan log transaksi.
   3. `sql/upgrade_rfid_pelanggan.sql`
   4. `sql/upgrade_diskon_pelanggan.sql`
   5. `sql/upgrade_saldo_pelanggan.sql`
   6. `sql/upgrade_duitku_qris.sql` — integrasi kolom dan tabel order Duitku.
   7. `sql/upgrade_duitku_channels.sql` — kolom untuk kanal transfer bank, e-wallet, dan retail.
   8. `sql/upgrade_pengguna.sql` — tabel akun untuk login admin/kasir.
4. Sesuaikan host, nama database, username, dan password MySQL pada `config/database.php` jika berbeda dari default XAMPP (`127.0.0.1`, `toko`, `root`, tanpa password).
5. Atur konfigurasi **Duitku API** langsung pada `.htaccess` atau variabel environment server:

   ```apache
   SetEnv DUITKU_ENV sandbox
   SetEnv DUITKU_MERCHANT_CODE "DXXXXX"
   SetEnv DUITKU_API_KEY "your-duitku-api-key"
   ```

   Ganti contoh credential dengan Merchant Code dan API Key dari dashboard Duitku. Tutup dan buka kembali XAMPP Control Panel jika diperlukan, lalu restart Apache. Jangan commit `.htaccess` ketika berisi API Key rahasia. Pastikan ekstensi PHP `curl` aktif.
6. Pada Duitku Dashboard, atur **Callback URL** ke URL publik HTTPS yang mengarah ke `/callback_duitku.php`. Callback tidak dapat diterima langsung dari `localhost`; untuk pengujian lokal gunakan tunnel HTTPS sementara (seperti ngrok/Cloudflare Tunnel).
7. Buat akun awal dari PowerShell atau Command Prompt pada komputer lokal:

   ```powershell
   cd C:\xampp\htdocs\tugas_pak_hendrik\toko
   & C:\xampp\php\php.exe scripts\buat_pengguna.php
   ```

   Masukkan nama, username, peran (`admin` atau `kasir`), dan password minimal 12 karakter saat diminta. Password disimpan sebagai hash aman (`password_hash`); tidak ada password bawaan di source code.
8. Buka `http://localhost/tugas_pak_hendrik/toko/` dan login.

Jika folder proyek diletakkan di lokasi atau virtual host lain, sesuaikan URL di browser. `.htaccess` melindungi direktori internal dan file SQL/Markdown bila Apache mengizinkan konfigurasi direktori (`AllowOverride`).
Jika aplikasi sudah terpasang, jalankan `sql/upgrade_duitku_qris.sql` dan `sql/upgrade_duitku_channels.sql` untuk memasang struktur transaksi Duitku beserta detail instruksi pembayaran per kanal.

## Peran dan Fitur

- **Admin:** master pelanggan, kategori, produk, kasir, riwayat/detail transaksi, laporan, dan ekspor CSV.
- **Kasir:** halaman kasir, struk, dan riwayat/detail transaksi.
- POS menerima pemindai barcode USB yang berfungsi sebagai keyboard; atur `Kode Produk / Barcode` pada halaman admin produk, lalu scan pada kolom kasir. Produk aktif otomatis masuk ke keranjang setelah pemindaian selesai tanpa perlu menekan Enter.
- Login memakai `password_hash`/`password_verify`, sesi dengan cookie `HttpOnly` dan `SameSite`, token CSRF untuk form, serta pembatasan percobaan login per sesi.
- CRUD pelanggan memanggil stored procedure `TambahPelanggan` untuk penambahan; perubahan dan penghapusan memakai prepared statement.
- Master kategori dan produk mendukung pencarian/filter, validasi input, status aktif, dan proteksi foreign key.
- Kasir menyimpan transaksi, rincian, dan pengurangan stok dalam satu transaksi database. Subtotal dihitung dengan function `HitungTotal`; jumlah penjualan berupa bilangan bulat karena parameter function existing bertipe `INT`.
- Kasir menyediakan pembayaran:
  1. **Tunai:** input nominal yang diterima pelanggan dengan kalkulasi kembalian otomatis.
  2. **Saldo Member:** pemotongan saldo pelanggan secara aman dengan validasi saldo minimum.
  3. **Duitku:** pilihan kanal transfer bank/virtual account, e-wallet, QRIS, dan retail; halaman checkout menampilkan nomor VA, kode pembayaran retail, QR, atau tautan pembayaran sesuai kanal. Status dipantau sampai pembayaran terkonfirmasi dan stok ditahan selama transaksi berlangsung.
- Callback Duitku berada di `callback_duitku.php`; notifikasi diverifikasi memakai MD5 signature Duitku (`merchantCode + amount + merchantOrderId + apiKey`). Transaksi Duitku yang berhasil baru dimasukkan ke riwayat setelah pembayaran terkonfirmasi. Jika transaksi gagal atau kedaluwarsa, stok dikembalikan otomatis.
- Riwayat dan laporan memanfaatkan view, stored procedure, function, JOIN, dan subquery existing.
- Struk serta laporan dapat dicetak melalui dialog cetak browser; ringkasan total belanja per pelanggan dapat diekspor sebagai CSV.

Error database yang ditampilkan kepada pengguna bersifat umum; detail teknis dicatat melalui `error_log` PHP. Pastikan log PHP hanya dapat diakses administrator server.

## Checklist Pengujian Akhir

Lakukan uji dengan data dummy yang dapat dikenali, dan hapus data dummy hanya setelah memastikan tidak terkait transaksi. Jangan menghapus data existing.

### Akses dan Keamanan

- [ ] Login dengan akun admin dan kasir; username/password salah menampilkan pesan umum.
- [ ] Setelah lima percobaan gagal pada sesi yang sama, login dibatasi sementara.
- [ ] Kasir dapat membuka kasir dan riwayat, tetapi tidak dapat mengakses master atau laporan admin (uji URL langsung).
- [ ] Logout mengakhiri sesi; halaman internal mengarahkan kembali ke login.
- [ ] Form POST tanpa token CSRF yang valid ditolak.
- [ ] Akses langsung ke direktori internal, `*.sql`, dan `*.md` tidak mengungkap source/database dump melalui Apache.

### Master Data dan Relasi

- [ ] Tambah, cari, ubah, dan hapus pelanggan; penambahan/perubahan terlihat di `log_pelanggan` dan `log_perubahan_pelanggan`.
- [ ] Hapus pelanggan yang memiliki transaksi ditolak dengan pesan yang jelas.
- [ ] CRUD kategori dan produk; produk menampilkan nama kategori melalui JOIN.
- [ ] Harga/stok negatif dan kategori kosong ditolak; kategori/produk yang masih direferensikan tidak dapat dihapus.
- [ ] Pencarian/filter produk dan indikator stok menipis berfungsi.
- [ ] Scan barcode produk aktif menambah satu item; barcode tidak dikenal dan stok habis menampilkan pesan tanpa mengubah keranjang.

### Kasir dan Transaksi

- [ ] Pilih pelanggan, tanggal, produk, dan jumlah; subtotal menggunakan `HitungTotal(harga, jumlah)`.
- [ ] Simpan transaksi dan cocokkan `tbl_transaksi.total_belanja` dengan jumlah subtotal pada `tbl_detail_transaksi`.
- [ ] Masukkan uang diterima; transaksi menolak nominal di bawah total, dan struk menampilkan uang diterima serta kembalian yang benar.
- [ ] Pembayaran member menolak transaksi tanpa pelanggan atau dengan saldo kurang; saldo hanya berkurang setelah pembayaran berhasil.
- [ ] Uji kanal Duitku yang sudah aktif di akun Sandbox:
  - [ ] Order pending menahan stok produk.
  - [ ] Virtual account menampilkan nomor VA, retail menampilkan kode pembayaran, dan e-wallet/QRIS menampilkan instruksi yang sesuai.
  - [ ] Polling otomatis mendeteksi status settlement via callback atau API query Duitku.
  - [ ] Callback settlement mencatat transaksi lunas satu kali ke `tbl_transaksi`.
  - [ ] Callback expire/cancel mengembalikan stok ke inventaris toko.
- [ ] Pastikan callback dengan signature atau nominal tidak valid ditolak (HTTP 400).
- [ ] Pastikan stok produk berkurang sesuai jumlah dan trigger menambah baris pada `log_transaksi`.
- [ ] Uji stok tidak cukup atau produk nonaktif; checkout gagal, detail/total tidak tersimpan sebagian, dan stok tidak berkurang.
- [ ] Uji struk, tombol cetak, riwayat, filter tanggal/pelanggan, dan halaman detail.

### View, Procedure, Function, Trigger, dan Laporan

- [ ] `view_transaksi_pelanggan` menampilkan transaksi dengan data pelanggan; `view_total_belanja` merangkum total per pelanggan.
- [ ] Dari laporan, pilih pelanggan untuk menguji `CekTransaksi` dan `LaporanTransaksi`.
- [ ] Cek function existing dengan nilai uji:

  ```sql
  SELECT HitungTotal(10000, 2) AS total,
         HitungDiskon(10000, 10) AS diskon,
         HitungPajak(10000) AS pajak,
         KategoriBelanja(600000) AS kategori;
  ```

- [ ] Pastikan laporan menampilkan kategori belanja, transaksi di atas rata-rata, pelanggan tanpa transaksi, dan maksimal lima transaksi terbesar.
- [ ] Cetak laporan dan buka hasil ekspor CSV untuk memeriksa kolom serta data.

## Verifikasi Data dengan phpMyAdmin

```sql
SELECT * FROM view_transaksi_pelanggan ORDER BY tanggal DESC;
SELECT * FROM view_total_belanja ORDER BY total_belanja DESC;

SELECT t.id_transaksi, t.total_belanja,
       COALESCE(SUM(d.subtotal), 0) AS total_rincian
FROM tbl_transaksi t
LEFT JOIN tbl_detail_transaksi d ON d.id_transaksi = t.id_transaksi
GROUP BY t.id_transaksi, t.total_belanja
ORDER BY t.id_transaksi DESC;

SELECT id_log, id_transaksi, id_detail, aktivitas, waktu
FROM log_transaksi
ORDER BY id_log DESC
LIMIT 20;
```
