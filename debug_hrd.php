<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h3>PHP Version: " . phpversion() . "</h3>";

echo "<h3>Step 1: Loading config...</h3>";
try {
    require_once 'includes/config.php';
    echo "OK - Config loaded<br>";
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "<br>";
    die();
}

echo "<h3>Step 2: Loading functions...</h3>";
try {
    require_once 'includes/functions.php';
    echo "OK - Functions loaded<br>";
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "<br>";
    die();
}

echo "<h3>Step 3: Check session...</h3>";
echo "Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "<br>";
echo "Session role: " . ($_SESSION['role'] ?? 'NOT SET') . "<br>";

echo "<h3>Step 4: Check employees table...</h3>";
try {
    $result = $pdo->query("SELECT COUNT(*) as cnt FROM employees");
    $row = $result->fetch();
    echo "OK - employees table exists, rows: " . $row['cnt'] . "<br>";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "<br>";
}

echo "<h3>Step 5: Check employees columns...</h3>";
try {
    $result = $pdo->query("DESCRIBE employees");
    $cols = $result->fetchAll();
    foreach ($cols as $col) {
        echo "  Column: " . $col['Field'] . " (" . $col['Type'] . ")<br>";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "<br>";
}

echo "<h3>Step 6: Check query with NULL values...</h3>";
try {
    $query = "SELECT e.*, b.name as branch_name FROM employees e LEFT JOIN branches b ON e.branch_id = b.id ORDER BY e.name ASC";
    $employees = $pdo->query($query)->fetchAll();
    echo "OK - Query returned " . count($employees) . " rows<br>";
    
    foreach ($employees as $emp) {
        echo "<br>Employee: " . htmlspecialchars($emp['name'] ?? '') . "<br>";
        echo "  position: " . var_export($emp['position'], true) . "<br>";
        echo "  basic_salary: " . var_export($emp['basic_salary'], true) . "<br>";
        echo "  remaining_loan: " . var_export($emp['remaining_loan'], true) . "<br>";
        echo "  branch_name: " . var_export($emp['branch_name'] ?? null, true) . "<br>";
        
        // Test rupiah formatting
        echo "  rupiah(basic_salary): " . rupiah($emp['basic_salary']) . "<br>";
        echo "  rupiah(remaining_loan): " . rupiah($emp['remaining_loan']) . "<br>";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "<br>";
    echo "Trace: <pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<h3>Step 7: Check header include...</h3>";
try {
    $page_title = 'Debug Test';
    ob_start();
    include 'includes/header.php';
    ob_end_clean();
    echo "OK - Header loaded<br>";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "<br>";
}

echo "<h3>Step 8: Check sidebar include...</h3>";
try {
    ob_start();
    include 'includes/sidebar.php';
    ob_end_clean();
    echo "OK - Sidebar loaded<br>";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "<br>";
}

echo "<h3>All checks complete!</h3>";
