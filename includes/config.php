<?php
// Konfigurasi Database Laragon
define('BASE_URL', 'http://localhost/bengkel-pro-php/'); // Sesuaikan dengan folder Anda di Laragon

$host = 'localhost';
$db   = 'bengkel_pro';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     // echo "Koneksi Berhasil!"; // Untuk testing
} catch (\PDOException $e) {
     // Jika database belum dibuat, kita tangkap errornya
     die("Koneksi gagal: " . $e->getMessage());
}
?>
