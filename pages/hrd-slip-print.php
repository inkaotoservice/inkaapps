<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

auth_ready();

if (!in_array(get_role(), ['owner', 'manager_ops'])) {
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$slip_id = $_GET['id'] ?? null;
if (!$slip_id) {
    die("ID Slip tidak ditemukan.");
}

$stmt = $pdo->prepare("
    SELECT s.*, e.name as emp_name, e.position as emp_position, b.name as branch_name
    FROM salary_slips s 
    JOIN employees e ON s.employee_id = e.id 
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE s.id = ?
");
$stmt->execute([$slip_id]);
$slip = $stmt->fetch();

if (!$slip) {
    die("Slip gaji tidak ditemukan.");
}

// Get company name from settings
$company_name = get_setting('company_name_payroll', 'PT. RUMI SOLUSI OTOMOTIF');

$months = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$period_label = $months[$slip['period_month']] . ' ' . $slip['period_year'];

// Hitung ulang komponen untuk display
$daily_rate = ($slip['qty_days_present'] > 0) ? round($slip['daily_allowance_total'] / $slip['qty_days_present']) : 0;
$overtime_rate = ($slip['qty_overtime_hours'] > 0) ? round($slip['overtime_total'] / $slip['qty_overtime_hours']) : 0;
$absence_rate = ($slip['qty_absent_days'] > 0) ? round($slip['absence_penalty_total'] / $slip['qty_absent_days']) : 0;

// WA message
$wa_text = "Halo *" . $slip['emp_name'] . "*,\nBerikut rincian gaji Anda untuk periode *" . $period_label . "*.\n\n";
$wa_text .= "*— PENDAPATAN —*\n";
$wa_text .= "Gaji Pokok: " . rupiah($slip['basic_salary']) . "\n";
$wa_text .= "Uang Harian (" . $slip['qty_days_present'] . " hr): " . rupiah($slip['daily_allowance_total']) . "\n";
$wa_text .= "Lembur (" . $slip['qty_overtime_hours'] . " jm): " . rupiah($slip['overtime_total']) . "\n";
$wa_text .= "\n*— POTONGAN —*\n";
if ($slip['late_penalty_total'] > 0) $wa_text .= "Terlambat: -" . rupiah($slip['late_penalty_total']) . "\n";
if ($slip['absence_penalty_total'] > 0) $wa_text .= "Tidak Hadir: -" . rupiah($slip['absence_penalty_total']) . "\n";
if ($slip['bpjs_tk_deduction'] > 0) $wa_text .= "BPJSTK: -" . rupiah($slip['bpjs_tk_deduction']) . "\n";
if ($slip['bpjs_deduction'] > 0) $wa_text .= "BPJS Kes: -" . rupiah($slip['bpjs_deduction']) . "\n";
$wa_text .= "\n*TOTAL GAJI KOTOR: " . rupiah($slip['gross_salary']) . "*\n";
$wa_text .= "\n*— POTONGAN KASBON —*\n";
if ($slip['deduction_kasbon'] > 0) $wa_text .= "Cash bon: -" . rupiah($slip['deduction_kasbon']) . "\n";
if ($slip['deduction_tabungan'] > 0) $wa_text .= "Tabungan: -" . rupiah($slip['deduction_tabungan']) . "\n";
if ($slip['deduction_lain'] > 0) $wa_text .= "Lain-lain: -" . rupiah($slip['deduction_lain']) . "\n";
$wa_text .= "Total Potongan: -" . rupiah($slip['total_deductions']) . "\n";
$wa_text .= "\n✅ *TOTAL GAJI BERSIH: " . rupiah($slip['net_salary']) . "*\n";
$wa_text .= "\nSisa Cuti: " . $slip['remaining_leave_after'] . " hari\n";
$wa_text .= "Sisa Pinjaman: " . rupiah($slip['remaining_loan_after']) . "\n";
$wa_text .= "\nTerima kasih atas kerja kerasnya! 🙏";
$wa_url = "https://wa.me/?text=" . urlencode($wa_text);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slip Gaji — <?php echo htmlspecialchars($slip['emp_name']); ?> — <?php echo $period_label; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        
        body { background: #f1f5f9; }
        
        .no-print { display: flex; }

        /* Print styles */
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .slip-container { 
                box-shadow: none !important; 
                border-radius: 0 !important; 
                border: none !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
            @page {
                size: A4;
                margin: 10mm;
            }
        }

        /* Action Bar */
        .action-bar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(12px);
            padding: 12px 24px;
            gap: 12px;
            align-items: center;
            justify-content: center;
        }
        .action-bar a, .action-bar button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-print { background: #3b82f6; color: #fff; }
        .btn-print:hover { background: #2563eb; }
        .btn-wa { background: #25D366; color: #fff; }
        .btn-wa:hover { background: #20bd5a; }
        .btn-back { background: #334155; color: #94a3b8; }
        .btn-back:hover { background: #475569; color: #fff; }

        /* Slip Container */
        .slip-container {
            max-width: 700px;
            margin: 32px auto;
            background: #fff;
            border-radius: 24px;
            border: 2px solid #e2e8f0;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        /* Header */
        .slip-header {
            text-align: center;
            padding: 32px 32px 24px;
            border-bottom: 3px solid #0f172a;
        }
        .slip-header .company-name {
            font-size: 18px;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .slip-header .period {
            font-size: 14px;
            font-weight: 700;
            color: #64748b;
            margin-top: 8px;
        }
        .slip-header .employee-name {
            font-size: 20px;
            font-weight: 900;
            color: #0f172a;
            margin-top: 16px;
            letter-spacing: 0.02em;
        }
        .slip-header .employee-position {
            font-size: 11px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-top: 4px;
        }

        /* Table */
        .slip-table {
            width: 100%;
            border-collapse: collapse;
        }
        .slip-table th {
            background: #f8fafc;
            padding: 10px 20px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #94a3b8;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        .slip-table th:nth-child(2), .slip-table th:nth-child(3) { text-align: center; }
        .slip-table th:last-child { text-align: right; }
        
        .slip-table td {
            padding: 12px 20px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }
        .slip-table td:nth-child(2), .slip-table td:nth-child(3) { text-align: center; }
        .slip-table td:last-child { 
            text-align: right; 
            font-weight: 800; 
            color: #0f172a;
        }
        
        .slip-table .label { font-weight: 700; color: #475569; }
        .slip-table .nominal { font-family: 'Inter', monospace; font-weight: 700; color: #64748b; text-align: right; }
        
        /* Section headers */
        .section-header {
            background: #f8fafc;
            padding: 12px 20px;
            border-top: 2px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }
        .section-header span {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #94a3b8;
        }

        /* Total Rows */
        .total-row td {
            background: #f8fafc;
            border-top: 2px solid #e2e8f0 !important;
            border-bottom: 2px solid #e2e8f0 !important;
            font-weight: 900 !important;
            font-size: 14px !important;
            padding: 14px 20px !important;
        }
        .total-row td:last-child { color: #0f172a !important; }
        
        .net-row td {
            background: #0f172a !important;
            color: #fff !important;
            border: none !important;
            font-size: 16px !important;
            padding: 18px 20px !important;
        }
        .net-row td:last-child { 
            color: #34d399 !important; 
            font-size: 20px !important;
        }

        .deduction-value { color: #ef4444 !important; }
        .empty-value { color: #cbd5e1 !important; }

        /* Footer info */
        .slip-footer {
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            background: #f8fafc;
            border-top: 2px solid #e2e8f0;
        }
        .slip-footer .info-block {
            text-align: center;
            flex: 1;
        }
        .slip-footer .info-label {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #94a3b8;
        }
        .slip-footer .info-value {
            font-size: 16px;
            font-weight: 900;
            color: #0f172a;
            margin-top: 4px;
        }

        .date-created {
            text-align: center;
            padding: 12px;
            font-size: 10px;
            font-weight: 600;
            color: #cbd5e1;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

<!-- Action Bar (hidden on print) -->
<div class="action-bar no-print">
    <a href="hrd-payroll.php" class="btn-back">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        Kembali
    </a>
    <button onclick="window.print();" class="btn-print">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
        Cetak / Save PDF
    </button>
    <a href="<?php echo $wa_url; ?>" target="_blank" class="btn-wa">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>
        Kirim via WhatsApp
    </a>
</div>

<!-- Slip Gaji -->
<div class="slip-container">
    
    <!-- Header -->
    <div class="slip-header">
        <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
        <div class="period"><?php echo $period_label; ?></div>
        <div class="employee-name"><?php echo htmlspecialchars(strtoupper($slip['emp_name'])); ?></div>
        <div class="employee-position"><?php echo htmlspecialchars($slip['emp_position'] ?? '-'); ?> — <?php echo htmlspecialchars($slip['branch_name'] ?? 'Pusat'); ?></div>
    </div>

    <!-- Detail Table -->
    <table class="slip-table">
        <thead>
            <tr>
                <th style="width:40%">Keterangan</th>
                <th style="width:20%">Nominal</th>
                <th style="width:15%">Qty</th>
                <th style="width:25%">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <!-- Pendapatan -->
            <tr>
                <td class="label">Gaji Pokok</td>
                <td class="nominal"><?php echo rupiah($slip['basic_salary']); ?></td>
                <td>—</td>
                <td><?php echo rupiah($slip['basic_salary']); ?></td>
            </tr>
            <tr>
                <td class="label">Uang Harian</td>
                <td class="nominal"><?php echo rupiah($daily_rate); ?></td>
                <td><?php echo $slip['qty_days_present']; ?> <small style="color:#94a3b8;font-size:10px">Hari</small></td>
                <td><?php echo rupiah($slip['daily_allowance_total']); ?></td>
            </tr>
            <tr>
                <td class="label">Lembur</td>
                <td class="nominal"><?php echo rupiah($overtime_rate); ?></td>
                <td><?php echo $slip['qty_overtime_hours']; ?> <small style="color:#94a3b8;font-size:10px">Jam</small></td>
                <td><?php echo rupiah($slip['overtime_total']); ?></td>
            </tr>
            <tr>
                <td class="label">Terlambat</td>
                <td class="nominal"></td>
                <td><?php echo $slip['qty_late_minutes']; ?> <small style="color:#94a3b8;font-size:10px">Menit</small></td>
                <td class="deduction-value"><?php echo $slip['late_penalty_total'] > 0 ? '-' . rupiah($slip['late_penalty_total']) : '-'; ?></td>
            </tr>
            <tr>
                <td class="label">Tidak Hadir</td>
                <td class="nominal"><?php echo $absence_rate > 0 ? rupiah($absence_rate) : ''; ?></td>
                <td><?php echo $slip['qty_absent_days']; ?> <small style="color:#94a3b8;font-size:10px">Hari</small></td>
                <td class="deduction-value"><?php echo $slip['absence_penalty_total'] > 0 ? '-' . rupiah($slip['absence_penalty_total']) : '-'; ?></td>
            </tr>
            <tr>
                <td class="label">Iuran BPJSTK</td>
                <td class="nominal"></td>
                <td></td>
                <td class="deduction-value"><?php echo $slip['bpjs_tk_deduction'] > 0 ? '-' . rupiah($slip['bpjs_tk_deduction']) : '-'; ?></td>
            </tr>
            <tr>
                <td class="label">Iuran BPJS</td>
                <td class="nominal"></td>
                <td></td>
                <td class="deduction-value"><?php echo $slip['bpjs_deduction'] > 0 ? '-' . rupiah($slip['bpjs_deduction']) : '-'; ?></td>
            </tr>

            <!-- Total Gaji Kotor -->
            <tr class="total-row">
                <td colspan="3" style="text-align:center; letter-spacing:0.15em; text-transform:uppercase; font-size:11px !important;">Total Gaji Kotor</td>
                <td><?php echo rupiah($slip['gross_salary']); ?></td>
            </tr>

            <!-- Section: Potongan Kasbon -->
            <tr><td colspan="4" class="section-header"><span>Potongan Kasbon</span></td></tr>
            <tr>
                <td class="label">Cash bon</td>
                <td></td>
                <td></td>
                <td class="deduction-value"><?php echo $slip['deduction_kasbon'] > 0 ? '-' . rupiah($slip['deduction_kasbon']) : '-'; ?></td>
            </tr>
            <tr>
                <td class="label">Tabungan</td>
                <td></td>
                <td></td>
                <td class="deduction-value"><?php echo $slip['deduction_tabungan'] > 0 ? '-' . rupiah($slip['deduction_tabungan']) : '-'; ?></td>
            </tr>
            <tr>
                <td class="label">Lain-lain</td>
                <td></td>
                <td></td>
                <td class="deduction-value"><?php echo $slip['deduction_lain'] > 0 ? '-' . rupiah($slip['deduction_lain']) : '-'; ?></td>
            </tr>
            
            <!-- Total Potongan -->
            <tr class="total-row">
                <td colspan="3" style="text-align:center; letter-spacing:0.15em; text-transform:uppercase; font-size:11px !important;">Total Potongan Kasbon</td>
                <td class="deduction-value"><?php echo $slip['total_deductions'] > 0 ? '-' . rupiah($slip['total_deductions']) : 'Rp 0'; ?></td>
            </tr>

            <!-- GAJI BERSIH -->
            <tr class="net-row">
                <td colspan="3" style="text-align:center; letter-spacing:0.2em; text-transform:uppercase; font-size:13px !important; font-weight:900 !important;">Total Gaji Bersih</td>
                <td><?php echo rupiah($slip['net_salary']); ?></td>
            </tr>
        </tbody>
    </table>

    <!-- Footer Info -->
    <div class="slip-footer">
        <div class="info-block">
            <div class="info-label">Sisa Cuti</div>
            <div class="info-value"><?php echo $slip['remaining_leave_after']; ?> <small style="color:#94a3b8;font-size:11px">Hari</small></div>
        </div>
        <div class="info-block" style="border-left: 2px solid #e2e8f0;">
            <div class="info-label">Sisa Pinjaman</div>
            <div class="info-value" style="color: <?php echo $slip['remaining_loan_after'] > 0 ? '#ef4444' : '#0f172a'; ?>;"><?php echo rupiah($slip['remaining_loan_after']); ?></div>
        </div>
    </div>

    <div class="date-created">
        Dicetak pada: <?php echo date('d F Y, H:i', strtotime($slip['created_at'])); ?> WIB
    </div>
</div>

</body>
</html>
