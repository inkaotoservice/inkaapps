<?php
require_once 'includes/config.php';

set_time_limit(0);

echo "<h2>Migrating Local Database to cPanel...</h2>";

$sqlFile = 'local_db_dump.sql';
if (!file_exists($sqlFile)) {
    die("File local_db_dump.sql not found!");
}

$sql = file_get_contents($sqlFile);

try {
    // Disable foreign key checks to prevent order issues
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    
    // Execute the full dump
    $pdo->exec($sql);
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    
    echo "<p style='color:green;'>✅ Database berhasil di-import sepenuhnya!</p>";
    
    // Cleanup
    unlink($sqlFile);
    unlink(__FILE__);
    echo "<p>✅ File sementara telah dihapus untuk keamanan.</p>";
    
    echo "<br><a href='login.php' style='padding:10px 20px; background:blue; color:white; text-decoration:none; border-radius:5px;'>Kembali ke Login</a>";
} catch (PDOException $e) {
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>
