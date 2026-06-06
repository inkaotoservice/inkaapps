<?php
// Konfigurasi Database Laragon
if (php_sapi_name() === 'cli' || $_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_NAME'] == '127.0.0.1') {
    // Konfigurasi Database Lokal (XAMPP Port 8080)
    define('BASE_URL', 'http://localhost:8080/bengkel-pro-php-v1/'); 
    $host = 'localhost';
    $db   = 'bengkel_pro';
    $user = 'root';
    $pass = '';
} else {
    // Konfigurasi Database cPanel (Production)
    define('BASE_URL', 'https://app.inkaotoservice.id/');
    $host = 'localhost';
    $db   = 'inkaotos_bengkel';
    $user = 'inkaotos_admin';
    $pass = 'jc(^9ZHkM4]65vws';
}
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
