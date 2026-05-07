<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

echo "<h2>Debug Login Status</h2>";
echo "Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? "ACTIVE" : "INACTIVE") . "<br>";
echo "Current Session Data: <pre>";
print_r($_SESSION);
echo "</pre>";

try {
    $stmt = $pdo->query("
        SELECT u.email, p.full_name, p.role, p.branch_id, b.name as branch_name
        FROM users u
        JOIN profiles p ON u.id = p.id
        LEFT JOIN branches b ON p.branch_id = b.id
        WHERE p.role != 'member'
        ORDER BY u.created_at DESC
        LIMIT 10
    ");
    $users = $stmt->fetchAll();

    echo "<h3>Recent Accounts (Staff/SPV/Owner)</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
    echo "<tr><th>Email</th><th>Name</th><th>Role</th><th>Branch</th></tr>";
    foreach ($users as $u) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($u['email']) . "</td>";
        echo "<td>" . htmlspecialchars($u['full_name']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($u['role']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($u['branch_name'] ?? 'Pusat') . "</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

echo "<br><br><a href='login.php'>Kembali ke Login</a>";
