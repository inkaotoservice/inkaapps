<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

echo "<h3>Admin Profile Check:</h3>";
$stmt = $pdo->query("SELECT p.full_name, p.role, p.branch_id, b.name as branch_name 
                    FROM profiles p 
                    LEFT JOIN branches b ON p.branch_id = b.id 
                    WHERE p.role LIKE 'admin%'");
echo "<table border='1'><tr><th>Name</th><th>Role</th><th>Branch ID</th><th>Branch Name</th></tr>";
foreach($stmt->fetchAll() as $r) {
    echo "<tr><td>{$r['full_name']}</td><td>{$r['role']}</td><td>{$r['branch_id']}</td><td>{$r['branch_name']}</td></tr>";
}
echo "</table>";
?>
