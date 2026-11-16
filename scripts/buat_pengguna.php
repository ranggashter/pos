<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$readInput = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    return $value === false ? '' : trim($value);
};

$name = $readInput('Nama pengguna: ');
$username = $readInput('Username (3-50 karakter, huruf/angka/._-): ');
$role = strtolower($readInput('Peran (admin/kasir): '));
$password = $readInput('Password (minimal 3 karakter): ');

if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
    fwrite(STDERR, "Nama wajib diisi dan maksimal 100 karakter.\n");
    exit(1);
}
if (preg_match('/^[A-Za-z0-9._-]{3,50}$/D', $username) !== 1) {
    fwrite(STDERR, "Format username tidak valid (gunakan 3-50 karakter alfanumerik/titik/strip).\n");
    exit(1);
}
if (!in_array($role, ['admin', 'kasir'], true)) {
    fwrite(STDERR, "Peran harus admin atau kasir.\n");
    exit(1);
}
if (strlen($password) < 3 || strlen($password) > 1024) {
    fwrite(STDERR, "Password harus berisi minimal 3 karakter.\n");
    exit(1);
}

try {
    $pdo = require __DIR__ . '/../config/database.php';
    $statement = $pdo->prepare(
        'INSERT INTO tbl_pengguna
            (nama_pengguna, username, password_hash, peran, status_aktif)
         VALUES
            (:nama, :username, :password_hash, :peran, 1)'
    );
    $statement->execute([
        'nama' => $name,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'peran' => $role,
    ]);
    fwrite(STDOUT, 'Akun ' . $username . ' berhasil dibuat dengan peran ' . $role . ".\n");
} catch (PDOException $exception) {
    error_log('Membuat akun pengguna gagal: ' . $exception->getMessage());
    fwrite(STDERR, "Akun gagal dibuat. Pastikan migrasi pengguna sudah dijalankan dan username belum dipakai.\n");
    exit(1);
} finally {
    $password = '';
}
