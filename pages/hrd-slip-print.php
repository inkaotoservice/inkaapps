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
    SELECT s.*, p.full_name as emp_name, p.jobdesk as emp_position, b.name as branch_name
    FROM salary_slips s 
    JOIN profiles p ON s.employee_id = p.id 
    LEFT JOIN branches b ON p.branch_id = b.id
    WHERE s.id = ?
");
$stmt->execute([$slip_id]);
$slip = $stmt->fetch();

if (!$slip) {
    die("Slip gaji tidak ditemukan.");
}

// Use INKA OTOSERVICE as requested
$company_name = 'INKA OTOSERVICE';

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
$wa_text = "Halo *" . $slip['emp_name'] . "*,\n\n";
$wa_text .= "Berikut rincian gaji Anda untuk periode *" . $period_label . "*:\n";
$wa_text .= "-----------------------------------\n";
$wa_text .= "*[ PENDAPATAN ]*\n";
$wa_text .= "Gaji Pokok: " . rupiah($slip['basic_salary']) . "\n";
$wa_text .= "Uang Harian (" . $slip['qty_days_present'] . " hr): " . rupiah($slip['daily_allowance_total']) . "\n";
$wa_text .= "Lembur (" . $slip['qty_overtime_hours'] . " jm): " . rupiah($slip['overtime_total']) . "\n\n";
$wa_text .= "*[ POTONGAN ]*\n";
$has_potongan = false;
if ($slip['late_penalty_total'] > 0) { $wa_text .= "Terlambat: -" . rupiah($slip['late_penalty_total']) . "\n"; $has_potongan = true; }
if ($slip['absence_penalty_total'] > 0) { $wa_text .= "Tidak Hadir: -" . rupiah($slip['absence_penalty_total']) . "\n"; $has_potongan = true; }
if ($slip['bpjs_tk_deduction'] > 0) { $wa_text .= "BPJSTK: -" . rupiah($slip['bpjs_tk_deduction']) . "\n"; $has_potongan = true; }
if ($slip['bpjs_deduction'] > 0) { $wa_text .= "BPJS Kes: -" . rupiah($slip['bpjs_deduction']) . "\n"; $has_potongan = true; }
if ($slip['deduction_kasbon'] > 0) { $wa_text .= "Cash Bon: -" . rupiah($slip['deduction_kasbon']) . "\n"; $has_potongan = true; }
if ($slip['deduction_tabungan'] > 0) { $wa_text .= "Tabungan: -" . rupiah($slip['deduction_tabungan']) . "\n"; $has_potongan = true; }
if ($slip['deduction_lain'] > 0) { $wa_text .= "Lain-lain: -" . rupiah($slip['deduction_lain']) . "\n"; $has_potongan = true; }
if (!$has_potongan) { $wa_text .= "- Tidak ada potongan -\n"; }
else { $wa_text .= "Total Potongan: -" . rupiah($slip['total_deductions']) . "\n"; }
$wa_text .= "-----------------------------------\n";
$wa_text .= "*GAJI KOTOR:* " . rupiah($slip['gross_salary']) . "\n";
$wa_text .= "*GAJI BERSIH: " . rupiah($slip['net_salary']) . "*\n";
$wa_text .= "-----------------------------------\n\n";
$wa_text .= "*Info Sisa:*\n";
$wa_text .= "Sisa Cuti: " . $slip['remaining_leave_after'] . " hari\n";
$wa_text .= "Sisa Pinjaman: " . rupiah($slip['remaining_loan_after']) . "\n\n";
$wa_text .= "Terima kasih.";
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
        
        body { background: #f8fafc; color: #334155; }
        
        .no-print { display: flex; }

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .slip-wrapper { padding: 0 !important; }
            .slip-container { 
                box-shadow: none !important; 
                border-radius: 0 !important; 
                border: none !important;
                margin: 0 !important;
                max-width: 100% !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            @page { size: A4; margin: 10mm; }
            
            /* Compress layout specifically for print to ensure it fits 1 page */
            .slip-header, .slip-content { padding: 16px 24px !important; }
            .slip-section { margin-bottom: 12px !important; }
            .section-title { padding-bottom: 8px !important; margin-bottom: 8px !important; }
            .item-row { padding: 6px 0 !important; }
            .subtotal-row { padding: 10px 16px !important; margin-top: 6px !important; }
            .grand-total { margin-top: 16px !important; padding: 12px 24px !important; }
            .slip-footer { margin-top: 16px !important; padding-top: 16px !important; }
            div[style*="height: 32px;"] { height: 16px !important; }
            .signature-title { margin-bottom: 40px !important; }
            .print-date { padding: 12px !important; }
        }

        /* Action Bar */
        .action-bar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 16px 24px;
            gap: 12px;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .action-bar a, .action-bar button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-print { background: #2563eb; color: #fff; }
        .btn-print:hover { background: #1d4ed8; }
        .btn-wa { background: #22c55e; color: #fff; }
        .btn-wa:hover { background: #16a34a; }
        .btn-back { background: #f1f5f9; color: #475569; }
        .btn-back:hover { background: #e2e8f0; color: #0f172a; }

        /* Container */
        .slip-wrapper {
            padding: 40px 20px;
        }
        .slip-container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px -10px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        /* Header */
        .slip-header {
            padding: 40px;
            background: #fff;
            color: #0f172a;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #f8fafc;
        }
        .company-info .company-name {
            font-size: 24px;
            font-weight: 900;
            letter-spacing: 0.05em;
            color: #0f172a;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .company-info .company-name svg {
            width: 28px;
            height: 28px;
            color: #3b82f6;
        }
        .company-info .slip-title {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.2em;
        }
        
        .employee-badge {
            text-align: right;
            background: #f8fafc;
            padding: 16px 24px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        .employee-badge .period {
            font-size: 11px;
            font-weight: 800;
            color: #0ea5e9;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 8px;
        }
        .employee-badge .name {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .employee-badge .position {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }

        /* Content */
        .slip-content {
            padding: 40px;
        }

        /* Sections */
        .slip-section {
            margin-bottom: 32px;
        }
        .section-title {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 16px;
        }
        .section-title.income { color: #059669; border-color: #d1fae5; }
        .section-title.deduction { color: #e11d48; border-color: #ffe4e6; }

        /* Items */
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 14px;
        }
        .item-row:last-child { border-bottom: none; }
        
        .item-label { font-weight: 600; color: #475569; flex: 1; }
        .item-detail {
            font-size: 12px;
            color: #94a3b8;
            font-weight: 600;
            width: 180px;
            text-align: right;
            padding-right: 24px;
        }
        .item-amount {
            font-weight: 700;
            color: #0f172a;
            font-family: 'Inter', monospace;
            width: 140px;
            text-align: right;
        }
        .amount-negative { color: #e11d48; }
        .empty-value { color: #cbd5e1; font-weight: 400; }

        /* Totals */
        .subtotal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: #f8fafc;
            border-radius: 8px;
            margin-top: 12px;
            font-weight: 800;
            font-size: 15px;
        }
        .subtotal-row.income { color: #059669; background: #ecfdf5; }
        .subtotal-row.deduction { color: #e11d48; background: #fff1f2; }

        .grand-total {
            margin-top: 40px;
            background: #ecfdf5;
            color: #0f172a;
            padding: 24px 40px;
            border-radius: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 2px solid #10b981;
        }
        .grand-total-label {
            font-size: 14px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #047857;
        }
        .grand-total-amount {
            font-size: 28px;
            font-weight: 900;
            color: #047857;
        }

        /* Footer */
        .slip-footer {
            margin-top: 40px;
            padding-top: 32px;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .info-cards {
            display: flex;
            gap: 16px;
        }
        .info-card {
            background: #f8fafc;
            padding: 16px 24px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            min-width: 140px;
        }
        .info-card-label {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 4px;
        }
        .info-card-value {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
        }
        .info-card-value.danger { color: #e11d48; }

        .signature {
            text-align: center;
            margin-right: 20px;
        }
        .signature-title {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 60px;
        }
        .signature-name {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            text-decoration: underline;
        }

        .print-date {
            text-align: center;
            padding: 24px;
            font-size: 11px;
            font-weight: 500;
            color: #cbd5e1;
            letter-spacing: 0.05em;
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
        Cetak / PDF
    </button>
    <a href="<?php echo $wa_url; ?>" target="_blank" class="btn-wa">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>
        WhatsApp
    </a>
</div>

<div class="slip-wrapper">
    <!-- Slip Gaji -->
    <div class="slip-container">
        
        <!-- Header -->
        <div class="slip-header">
            <div class="company-info">
                <div class="company-name">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                    <?php echo htmlspecialchars($company_name); ?>
                </div>
                <div class="slip-title">Slip Gaji Karyawan</div>
            </div>
            
            <div class="employee-badge">
                <div class="period"><?php echo $period_label; ?></div>
                <div class="name"><?php echo htmlspecialchars(strtoupper($slip['emp_name'])); ?></div>
                <div class="position"><?php echo htmlspecialchars($slip['emp_position'] ?? '-'); ?> &bull; <?php echo htmlspecialchars($slip['branch_name'] ?? 'Pusat'); ?></div>
            </div>
        </div>

        <div class="slip-content">
            <!-- Pendapatan -->
            <div class="slip-section">
                <div class="section-title income">Pendapatan</div>
                
                <div class="item-row">
                    <div class="item-label">Gaji Pokok</div>
                    <div class="item-detail">Bulanan Tetap</div>
                    <div class="item-amount"><?php echo rupiah($slip['basic_salary']); ?></div>
                </div>
                <div class="item-row">
                    <div class="item-label">Uang Harian</div>
                    <div class="item-detail"><?php echo $slip['qty_days_present']; ?> Hari &times; <?php echo rupiah($daily_rate); ?></div>
                    <div class="item-amount"><?php echo rupiah($slip['daily_allowance_total']); ?></div>
                </div>
                <div class="item-row">
                    <div class="item-label">Lembur</div>
                    <div class="item-detail"><?php echo $slip['qty_overtime_hours']; ?> Jam &times; <?php echo rupiah($overtime_rate); ?></div>
                    <div class="item-amount"><?php echo rupiah($slip['overtime_total']); ?></div>
                </div>
            </div>

            <!-- Potongan -->
            <div class="slip-section">
                <div class="section-title deduction">Potongan Kehadiran & Asuransi</div>
                
                <div class="item-row">
                    <div class="item-label">Terlambat</div>
                    <div class="item-detail"><?php echo $slip['qty_late_minutes']; ?> Menit</div>
                    <div class="item-amount <?php echo $slip['late_penalty_total'] > 0 ? 'amount-negative' : 'empty-value'; ?>">
                        <?php echo $slip['late_penalty_total'] > 0 ? '-' . rupiah($slip['late_penalty_total']) : '-'; ?>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-label">Tidak Hadir</div>
                    <div class="item-detail"><?php echo $slip['qty_absent_days']; ?> Hari</div>
                    <div class="item-amount <?php echo $slip['absence_penalty_total'] > 0 ? 'amount-negative' : 'empty-value'; ?>">
                        <?php echo $slip['absence_penalty_total'] > 0 ? '-' . rupiah($slip['absence_penalty_total']) : '-'; ?>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-label">Iuran BPJSTK</div>
                    <div class="item-detail"></div>
                    <div class="item-amount <?php echo $slip['bpjs_tk_deduction'] > 0 ? 'amount-negative' : 'empty-value'; ?>">
                        <?php echo $slip['bpjs_tk_deduction'] > 0 ? '-' . rupiah($slip['bpjs_tk_deduction']) : '-'; ?>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-label">Iuran BPJS</div>
                    <div class="item-detail"></div>
                    <div class="item-amount <?php echo $slip['bpjs_deduction'] > 0 ? 'amount-negative' : 'empty-value'; ?>">
                        <?php echo $slip['bpjs_deduction'] > 0 ? '-' . rupiah($slip['bpjs_deduction']) : '-'; ?>
                    </div>
                </div>
            </div>

            <!-- Total Gaji Kotor -->
            <div class="subtotal-row income">
                <div>TOTAL GAJI KOTOR</div>
                <div><?php echo rupiah($slip['gross_salary']); ?></div>
            </div>

            <div style="height: 32px;"></div>

            <!-- Potongan Kasbon -->
            <div class="slip-section">
                <div class="section-title deduction">Potongan Lainnya</div>
                
                <div class="item-row">
                    <div class="item-label">Cash bon</div>
                    <div class="item-detail"></div>
                    <div class="item-amount <?php echo $slip['deduction_kasbon'] > 0 ? 'amount-negative' : 'empty-value'; ?>">
                        <?php echo $slip['deduction_kasbon'] > 0 ? '-' . rupiah($slip['deduction_kasbon']) : '-'; ?>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-label">Tabungan</div>
                    <div class="item-detail"></div>
                    <div class="item-amount <?php echo $slip['deduction_tabungan'] > 0 ? 'amount-negative' : 'empty-value'; ?>">
                        <?php echo $slip['deduction_tabungan'] > 0 ? '-' . rupiah($slip['deduction_tabungan']) : '-'; ?>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-label">Lain-lain</div>
                    <div class="item-detail"></div>
                    <div class="item-amount <?php echo $slip['deduction_lain'] > 0 ? 'amount-negative' : 'empty-value'; ?>">
                        <?php echo $slip['deduction_lain'] > 0 ? '-' . rupiah($slip['deduction_lain']) : '-'; ?>
                    </div>
                </div>
            </div>

            <div class="subtotal-row deduction">
                <div>TOTAL POTONGAN</div>
                <div><?php echo $slip['total_deductions'] > 0 ? '-' . rupiah($slip['total_deductions']) : 'Rp 0'; ?></div>
            </div>

            <!-- GAJI BERSIH -->
            <div class="grand-total">
                <div class="grand-total-label">Gaji Bersih Diterima</div>
                <div class="grand-total-amount"><?php echo rupiah($slip['net_salary']); ?></div>
            </div>

            <!-- Footer Info -->
            <div class="slip-footer">
                <div class="info-cards">
                    <div class="info-card">
                        <div class="info-card-label">Sisa Cuti</div>
                        <div class="info-card-value"><?php echo $slip['remaining_leave_after']; ?> <span style="font-size:11px;color:#94a3b8;font-weight:600;">Hari</span></div>
                    </div>
                    <div class="info-card">
                        <div class="info-card-label">Sisa Pinjaman</div>
                        <div class="info-card-value <?php echo $slip['remaining_loan_after'] > 0 ? 'danger' : ''; ?>"><?php echo rupiah($slip['remaining_loan_after']); ?></div>
                    </div>
                </div>

                <div class="signature">
                    <div class="signature-title">Mengetahui, HRD</div>
                    <div class="signature-name">_______________</div>
                </div>
            </div>
        </div>
    </div>

    <div class="print-date">
        DICETAK PADA: <?php echo date('d M Y, H:i', strtotime($slip['created_at'])); ?> WIB &bull; DOKUMEN RAHASIA
    </div>
</div>

</body>
</html>
