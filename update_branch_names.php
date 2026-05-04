<?php
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
    
    // Drop unique index if exists
    try {
        $pdo->exec("ALTER TABLE branches DROP INDEX unique_branch_name");
        echo "Dropped unique_branch_name index.\n";
    } catch (Exception $e) {
        // Ignore if index doesn't exist
    }

    $pdo->exec("UPDATE branches SET name = 'Inka Otoservice'");
    echo "Branches updated successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
