<?php
require_once 'includes/config.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employees (
            id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            position VARCHAR(100),
            branch_id VARCHAR(36),
            basic_salary BIGINT DEFAULT 0,
            daily_allowance BIGINT DEFAULT 0,
            overtime_rate BIGINT DEFAULT 0,
            absence_penalty_per_day BIGINT DEFAULT 0,
            late_penalty_per_minute BIGINT DEFAULT 0,
            bpjs_tk_deduction BIGINT DEFAULT 0,
            bpjs_deduction BIGINT DEFAULT 0,
            remaining_leave INT DEFAULT 0,
            remaining_loan BIGINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
        )
    ");
    echo "SUCCESS: Created employees table.<br>";
} catch (PDOException $e) {
    echo "ERROR (employees): " . $e->getMessage() . "<br>";
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_loans (
            id VARCHAR(36) PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL,
            type ENUM('kasbon', 'potongan_gaji') NOT NULL,
            amount BIGINT NOT NULL,
            date DATE NOT NULL,
            description TEXT,
            salary_slip_id VARCHAR(36) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )
    ");
    echo "SUCCESS: Created employee_loans table.<br>";
} catch (PDOException $e) {
    echo "ERROR (employee_loans): " . $e->getMessage() . "<br>";
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS salary_slips (
            id VARCHAR(36) PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL,
            period_month INT NOT NULL,
            period_year INT NOT NULL,
            basic_salary BIGINT DEFAULT 0,
            qty_days_present INT DEFAULT 0,
            daily_allowance_total BIGINT DEFAULT 0,
            qty_overtime_hours INT DEFAULT 0,
            overtime_total BIGINT DEFAULT 0,
            qty_late_minutes INT DEFAULT 0,
            late_penalty_total BIGINT DEFAULT 0,
            qty_absent_days INT DEFAULT 0,
            absence_penalty_total BIGINT DEFAULT 0,
            bpjs_tk_deduction BIGINT DEFAULT 0,
            bpjs_deduction BIGINT DEFAULT 0,
            gross_salary BIGINT DEFAULT 0,
            deduction_kasbon BIGINT DEFAULT 0,
            deduction_tabungan BIGINT DEFAULT 0,
            deduction_lain BIGINT DEFAULT 0,
            total_deductions BIGINT DEFAULT 0,
            net_salary BIGINT DEFAULT 0,
            remaining_leave_after INT DEFAULT 0,
            remaining_loan_after BIGINT DEFAULT 0,
            created_by VARCHAR(36),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )
    ");
    echo "SUCCESS: Created salary_slips table.<br>";
} catch (PDOException $e) {
    echo "ERROR (salary_slips): " . $e->getMessage() . "<br>";
}

try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO app_settings (`key`, `value`) VALUES ('company_name_payroll', 'INKA OTOSERVICE')");
    $stmt->execute();
    echo "SUCCESS: Inserted default settings.<br>";
} catch (PDOException $e) {
    echo "ERROR (app_settings): " . $e->getMessage() . "<br>";
}

echo "Migration completed.";
