-- ================================================================
-- INKA OTOSERVICE - MYSQL SETUP (LARAGON)
-- ================================================================

CREATE DATABASE IF NOT EXISTS bengkel_pro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bengkel_pro;

-- 1. BRANCHES
CREATE TABLE IF NOT EXISTS branches (
  id         VARCHAR(36) PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,
  address    TEXT,
  phone      VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. USERS (Auth Table - pengganti Supabase Auth)
CREATE TABLE IF NOT EXISTS users (
  id         VARCHAR(36) PRIMARY KEY,
  email      VARCHAR(255) UNIQUE NOT NULL,
  password   VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. PROFILES
CREATE TABLE IF NOT EXISTS profiles (
  id           VARCHAR(36) PRIMARY KEY,
  full_name    VARCHAR(255) NOT NULL DEFAULT 'User',
  role         ENUM('owner','manager_ops','admin','admin_depok','admin_bsd','spv') DEFAULT 'admin',
  phone        VARCHAR(20),
  total_points INT DEFAULT 0,
  branch_id    VARCHAR(36),
  referral_code VARCHAR(50),
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

-- 4. APP SETTINGS
CREATE TABLE IF NOT EXISTS app_settings (
  `key`      VARCHAR(255) PRIMARY KEY,
  `value`    TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 5. CATALOG
CREATE TABLE IF NOT EXISTS catalog (
  id          VARCHAR(36) PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  category    ENUM('Service','Spare Part') NOT NULL,
  price       BIGINT NOT NULL DEFAULT 0,
  cost_price  BIGINT DEFAULT 0,
  description TEXT,
  stock       INT DEFAULT NULL,
  is_active   TINYINT(1) DEFAULT 1,
  branch_id   VARCHAR(36),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

-- 6. BOOKINGS
CREATE TABLE IF NOT EXISTS bookings (
  id             VARCHAR(36) PRIMARY KEY,
  customer_name  VARCHAR(255) NOT NULL,
  customer_phone VARCHAR(20) NOT NULL,
  car_model      VARCHAR(255) NOT NULL,
  license_plate  VARCHAR(20) NOT NULL,
  booking_code   VARCHAR(50),
  booking_type   VARCHAR(50) DEFAULT 'walk-in',
  service_date   DATE NOT NULL,
  service_time   VARCHAR(20),
  status         ENUM('awaiting_dp','pending','processing','completed','cancelled') DEFAULT 'pending',
  member_id      VARCHAR(36),
  branch_id      VARCHAR(36),
  notes          TEXT,
  dp_receipt     VARCHAR(255) DEFAULT NULL,
  is_online      TINYINT(1) DEFAULT 0,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES profiles(id) ON DELETE SET NULL,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

-- 7. TRANSACTIONS
CREATE TABLE IF NOT EXISTS transactions (
  id             VARCHAR(36) PRIMARY KEY,
  customer_name  VARCHAR(255),
  branch_id      VARCHAR(36),
  booking_id     VARCHAR(36),
  member_id      VARCHAR(36),
  total_amount   BIGINT NOT NULL DEFAULT 0,
  discount_amount BIGINT DEFAULT 0,
  payment_method VARCHAR(50) DEFAULT 'Cash',
  status         ENUM('Draft','In Progress','Paid','Cancelled') DEFAULT 'Paid',
  mechanic_name  VARCHAR(255) DEFAULT NULL,
  created_by     VARCHAR(36),
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  FOREIGN KEY (member_id) REFERENCES profiles(id) ON DELETE SET NULL
);

-- 8. TRANSACTION ITEMS
CREATE TABLE IF NOT EXISTS transaction_items (
  id             VARCHAR(36) PRIMARY KEY,
  transaction_id VARCHAR(36) NOT NULL,
  catalog_id     VARCHAR(36),
  item_name      VARCHAR(255) NOT NULL,
  qty            INT NOT NULL DEFAULT 1,
  price_at_sale  BIGINT NOT NULL,
  cost_at_sale   BIGINT NOT NULL DEFAULT 0,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  FOREIGN KEY (catalog_id) REFERENCES catalog(id) ON DELETE SET NULL
);

-- 9. EXPENSES
CREATE TABLE IF NOT EXISTS expenses (
  id           VARCHAR(36) PRIMARY KEY,
  branch_id    VARCHAR(36),
  category     ENUM('Gaji','Listrik','Sewa','Pemasaran','Stok','Operasional','Lainnya') NOT NULL,
  amount       BIGINT NOT NULL DEFAULT 0,
  description  TEXT,
  expense_date DATE DEFAULT (CURRENT_DATE),
  created_by   VARCHAR(36),
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

-- 10. REWARDS
CREATE TABLE IF NOT EXISTS rewards (
  id              VARCHAR(36) PRIMARY KEY,
  name            VARCHAR(255) NOT NULL,
  description     TEXT,
  points_required INT NOT NULL,
  reward_type     ENUM('discount','free_service','voucher','merchandise') DEFAULT 'discount',
  discount_value  BIGINT,
  is_active       TINYINT(1) DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

/*
-- 11. POINT TRANSACTIONS
CREATE TABLE IF NOT EXISTS point_transactions (
  id             VARCHAR(36) PRIMARY KEY,
  member_id      VARCHAR(36),
  transaction_id VARCHAR(36),
  points         INT NOT NULL,
  type           ENUM('earn','redeem','adjustment','bonus','expired') NOT NULL,
  description    TEXT,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES profiles(id) ON DELETE CASCADE,
  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
);

-- 12. MEMBER VEHICLES
CREATE TABLE IF NOT EXISTS member_vehicles (
  id            VARCHAR(36) PRIMARY KEY,
  member_id     VARCHAR(36) NOT NULL,
  brand_model   VARCHAR(255) NOT NULL,
  license_plate VARCHAR(20) NOT NULL,
  year          INT,
  color         VARCHAR(50),
  is_primary    TINYINT(1) DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES profiles(id) ON DELETE CASCADE
);

-- 13. MEMBER FEEDBACK
CREATE TABLE IF NOT EXISTS member_feedback (
  id         VARCHAR(36) PRIMARY KEY,
  member_id  VARCHAR(36),
  full_name  VARCHAR(255),
  subject    VARCHAR(255),
  message    TEXT NOT NULL,
  rating     INT CHECK (rating >= 1 AND rating <= 5),
  status     ENUM('pending','read','resolved') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES profiles(id) ON DELETE SET NULL
);
*/

-- 14. VOUCHERS
CREATE TABLE IF NOT EXISTS vouchers (
  id             VARCHAR(36) PRIMARY KEY,
  code           VARCHAR(50) UNIQUE NOT NULL,
  discount_value BIGINT NOT NULL DEFAULT 0,
  discount_type  ENUM('fixed','percent') DEFAULT 'fixed',
  min_purchase   BIGINT DEFAULT 0,
  is_active      TINYINT(1) DEFAULT 1,
  used_at        TIMESTAMP NULL,
  used_by        VARCHAR(36),
  expires_at     TIMESTAMP NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (used_by) REFERENCES profiles(id) ON DELETE SET NULL
);

-- 15. EMPLOYEES
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
);

-- 16. EMPLOYEE LOANS
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
);

-- 17. SALARY SLIPS
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
);

-- ================================================================
-- INDEXES
-- ================================================================
CREATE INDEX idx_transactions_branch   ON transactions(branch_id);
CREATE INDEX idx_transactions_status   ON transactions(status);
CREATE INDEX idx_transactions_created  ON transactions(created_at);
CREATE INDEX idx_bookings_branch       ON bookings(branch_id);
CREATE INDEX idx_bookings_status       ON bookings(status);
CREATE INDEX idx_bookings_plate        ON bookings(license_plate);
CREATE INDEX idx_profiles_role         ON profiles(role);
CREATE INDEX idx_profiles_branch       ON profiles(branch_id);
CREATE INDEX idx_catalog_active        ON catalog(is_active);

-- ================================================================
-- DEFAULT SETTINGS
-- ================================================================
INSERT IGNORE INTO app_settings (`key`, `value`) VALUES
  ('points_per_rupiah', '10000'),
  ('points_enabled',    'true'),
  ('app_name',          'Inka Otoservice'),
  ('montir_ai_quota',   '10'),
  ('montir_ai_days',    '30'),
  ('booking_dp',        '50000'),
  ('payment_bank_name', 'Bank BCA'),
  ('payment_account_number', '1234567890'),
  ('payment_account_name', 'PT Inka Otoservice'),
  ('company_name_payroll', 'INKA OTOSERVICE');

-- ================================================================
-- SAMPLE CATALOG
-- ================================================================
INSERT IGNORE INTO catalog (id, name, category, price, cost_price, description, stock) VALUES
  (UUID(), 'Ganti Oli Shell Helix',    'Service',    450000, 320000, 'Ganti oli mesin Shell Helix Ultra', NULL),
  (UUID(), 'Service Rutin 10.000 KM',  'Service',   1200000, 800000, 'Pengecekan menyeluruh berkala 10K KM', NULL),
  (UUID(), 'Kampas Rem Depan (Ori)',   'Spare Part',  350000, 210000, 'Kampas rem depan orisinal', 12),
  (UUID(), 'Filter Udara',             'Spare Part',   85000,  45000, 'Filter udara standar', 20),
  (UUID(), 'Tune Up Mesin',            'Service',    750000, 500000, 'Tune up lengkap mesin', NULL);

/*
-- ================================================================
-- SAMPLE REWARDS
-- ================================================================
INSERT IGNORE INTO rewards (id, name, description, points_required, reward_type, discount_value) VALUES
  (UUID(), 'Diskon 50K',  'Potongan Rp 50.000 untuk servis berikutnya',  50,  'discount', 50000),
  (UUID(), 'Diskon 100K', 'Potongan Rp 100.000 untuk servis berikutnya', 100, 'discount', 100000);
*/
