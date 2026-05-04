<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_GET['id']) && !isset($_GET['booking_id'])) {
    die("Parameter ID atau Booking ID tidak ditemukan.");
}

if (isset($_GET['booking_id'])) {
    $stmt = $pdo->prepare("
        SELECT t.*, b.name as branch_name, b.address as branch_address, b.phone as branch_phone,
               b.logo_url as branch_logo, b.invoice_notes as branch_notes,
               bk.customer_phone, bk.license_plate, bk.car_model
        FROM transactions t 
        LEFT JOIN branches b ON t.branch_id = b.id 
        LEFT JOIN bookings bk ON t.booking_id = bk.id
        WHERE t.booking_id = ?
        ORDER BY t.created_at DESC LIMIT 1
    ");
    $stmt->execute([$_GET['booking_id']]);
} else {
    $stmt = $pdo->prepare("
        SELECT t.*, b.name as branch_name, b.address as branch_address, b.phone as branch_phone,
               b.logo_url as branch_logo, b.invoice_notes as branch_notes,
               bk.customer_phone, bk.license_plate, bk.car_model
        FROM transactions t 
        LEFT JOIN branches b ON t.branch_id = b.id 
        LEFT JOIN bookings bk ON t.booking_id = bk.id
        WHERE t.id = ?
    ");
    $stmt->execute([$_GET['id']]);
}
$tx = $stmt->fetch();

if (!$tx) {
    die("Transaksi tidak valid.");
}

$stmt_items = $pdo->prepare("
    SELECT ti.*, c.name as item_name 
    FROM transaction_items ti 
    LEFT JOIN catalog c ON ti.catalog_id = c.id 
    WHERE ti.transaction_id = ?
");
$stmt_items->execute([$tx['id']]);
$items = $stmt_items->fetchAll();

$stmt_settings = $pdo->query("SELECT `key`, `value` FROM app_settings WHERE `key` IN ('receipt_logo_url', 'receipt_notes')");
$settings = [];
while ($row = $stmt_settings->fetch()) {
    $settings[$row['key']] = $row['value'];
}
$logo_url = !empty($tx['branch_logo']) ? '../assets/uploads/logos/' . $tx['branch_logo'] : ($settings['receipt_logo_url'] ?? '');
$notes = !empty($tx['branch_notes']) ? $tx['branch_notes'] : ($settings['receipt_notes'] ?? "Terima kasih atas kunjungan Anda!\nBarang yang sudah dibeli tidak dapat ditukar atau dikembalikan.");
$invoice_no = "INV-" . strtoupper(substr(str_replace('-', '', $tx['id']), 0, 8));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo htmlspecialchars($tx['customer_name']); ?></title>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #51767c;
            --text-dark: #1e293b;
            --text-gray: #64748b;
            --border-light: #e2e8f0;
            --bg-light: #f8fafc;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #e2e8f0; 
            color: var(--text-dark);
            line-height: 1.5;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            padding: 40px 20px;
        }

        /* Toolbar */
        .toolbar {
            position: fixed;
            top: 20px;
            display: flex;
            gap: 12px;
            background: white;
            padding: 10px 20px;
            border-radius: 99px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
            z-index: 100;
        }
        .btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 99px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            text-decoration: none;
            font-family: 'Outfit', sans-serif;
        }
        .btn-print { background: #2563eb; color: white; }
        .btn-print:hover { background: #1d4ed8; transform: translateY(-1px); }
        .btn-wa { background: #10b981; color: white; }
        .btn-wa:hover { background: #059669; transform: translateY(-1px); }
        .btn-close { background: #f1f5f9; color: #475569; }

        /* A4 Container */
        .a4-container {
            background: white;
            width: 210mm;
            min-height: 297mm;
            padding: 20mm;
            box-shadow: 0 20px 50px -12px rgba(0,0,0,0.1);
            position: relative;
            margin-top: 60px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 1;
            margin-bottom: 40px;
        }

        .company-brand {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .company-logo-box {
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 24px;
            letter-spacing: 1px;
        }

        .company-name {
            font-family: 'Outfit', sans-serif;
            font-weight: 900;
            font-size: 28px;
            line-height: 1.1;
            color: #000;
            text-transform: uppercase;
        }

        .invoice-title-area {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 40px;
        }

        .invoice-title-block {
            width: 80px;
            height: 35px;
            background: var(--primary);
        }

        .invoice-title {
            font-family: 'Outfit', sans-serif;
            font-size: 36px;
            font-weight: 800;
            letter-spacing: 2px;
            color: #000;
            line-height: 1;
        }

        .invoice-meta-container {
            display: flex;
            justify-content: flex-end;
            margin-top: -90px;
            margin-bottom: 40px;
        }

        .invoice-meta {
            text-align: left;
            font-size: 13px;
        }

        .invoice-meta table {
            border-collapse: collapse;
        }

        .invoice-meta td {
            padding: 3px 0;
        }

        .invoice-meta td:first-child {
            font-weight: 700;
            padding-right: 10px;
            color: #000;
        }

        .parties-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            font-size: 13px;
        }

        .party-col {
            flex: 1;
        }

        .party-label {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 14px;
            color: #000;
            margin-bottom: 10px;
        }

        .party-name {
            font-weight: 800;
            font-size: 16px;
            color: #000;
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .party-address {
            color: var(--text-gray);
            line-height: 1.4;
        }

        .payment-terms {
            font-size: 13px;
            margin-bottom: 30px;
            font-weight: 700;
        }

        /* Table */
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            font-size: 13px;
        }

        .invoice-table th {
            text-align: left;
            padding: 12px 10px;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            color: #000;
            border-top: 2px solid #e2e8f0;
            border-bottom: 2px solid #e2e8f0;
        }

        .invoice-table td {
            padding: 10px;
            border-bottom: 1px dotted #cbd5e1;
            color: var(--text-dark);
        }

        .invoice-table th.text-right,
        .invoice-table td.text-right {
            text-align: right;
        }
        
        .invoice-table th.text-center,
        .invoice-table td.text-center {
            text-align: center;
        }

        .summary-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }

        .terms-info {
            flex: 1;
            padding-right: 40px;
        }

        .terms-block {
            margin-bottom: 25px;
        }

        .terms-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 13px;
            color: #000;
            margin-bottom: 5px;
        }

        .terms-text {
            font-size: 12px;
            color: var(--text-gray);
            white-space: pre-line;
            line-height: 1.5;
        }

        .totals-box {
            width: 300px;
            font-size: 13px;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            color: #000;
        }

        .totals-row.bold {
            font-weight: 800;
        }

        .totals-row.grand-total {
            background: var(--primary);
            color: white;
            padding: 12px 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 16px;
            font-weight: 800;
        }

        @media print {
            body { background: white; padding: 0; }
            .toolbar { display: none; }
            .a4-container { 
                box-shadow: none; 
                margin: 0;
                padding: 0;
                width: 100%;
                min-height: auto;
            }
            @page {
                size: A4;
                margin: 20mm;
            }
        }
    </style>
</head>
<body>
    <?php if (is_logged_in()): ?>
    <div class="toolbar">
        <button onclick="window.print()" class="btn btn-print">
            <i data-lucide="printer" style="width: 18px;"></i> Cetak Invoice
        </button>
        <?php 
            $items_text = "";
            foreach($items as $item) {
                $items_text .= "- *" . $item['item_name'] . "*\n    " . $item['qty'] . " x " . number_format($item['price_at_sale'], 0, ',', '.') . " = Rp " . number_format($item['qty'] * $item['price_at_sale'], 0, ',', '.') . "\n\n";
            }
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $invoice_link = $protocol . "://" . $host . $path . "?id=" . $tx['id'];
            
            $final_total = $tx['total_amount'] - $tx['dp_amount'];
            
            $dp_text = "";
            if ($tx['dp_amount'] > 0) {
                $dp_text = "Potongan DP   : Rp " . number_format($tx['dp_amount'], 0, ',', '.') . "\n";
            }
            
            $car_text = "";
            if (!empty($tx['car_model'])) {
                $car_text = "*KENDARAAN:* " . $tx['car_model'] . " (" . $tx['license_plate'] . ")\n";
            }

            $date_str = date('d M Y', strtotime($tx['created_at']));
            $branch_str = strtoupper($tx['branch_name'] ?? 'INKA OTOSERVICE');

            $wa_text = "*" . $branch_str . "*\n" .
                       "=============================\n" .
                       "*NO. NOTA :* " . $invoice_no . "\n" .
                       "*TANGGAL  :* " . $date_str . "\n" .
                       "*PELANGGAN:* " . $tx['customer_name'] . "\n" .
                       $car_text .
                       "-----------------------------\n" .
                       "*RINCIAN TRANSAKSI:*\n\n" .
                       $items_text . 
                       "-----------------------------\n" .
                       "Subtotal      : Rp " . number_format($tx['total_amount'], 0, ',', '.') . "\n" .
                       $dp_text .
                       "=============================\n" .
                       "*TOTAL BAYAR   : Rp " . number_format($final_total, 0, ',', '.') . "*\n" .
                       "*(LUNAS via " . $tx['payment_method'] . ")*\n" .
                       "=============================\n\n" .
                       "Terima kasih telah mempercayakan perawatan kendaraan Anda kepada kami!\n\n" .
                       "*Download nota disini:*\n" .
                       $invoice_link;
            $phone = !empty($tx['customer_phone']) ? preg_replace('/[^0-9]/', '', $tx['customer_phone']) : '';
            if (strpos($phone, '0') === 0) { $phone = '62' . substr($phone, 1); }
            elseif (strpos($phone, '8') === 0) { $phone = '62' . $phone; }
        ?>
        <a href="https://wa.me/<?php echo $phone; ?>?text=<?php echo rawurlencode($wa_text); ?>" target="_blank" class="btn btn-wa">
            <i data-lucide="message-circle" style="width: 18px;"></i> WhatsApp
        </a>
        <button onclick="window.close()" class="btn btn-close">Tutup</button>
    </div>
    <?php else: ?>
    <div class="toolbar" style="justify-content: center; width: 100%; max-width: 320px; left: 50%; transform: translateX(-50%);">
        <button onclick="window.print()" class="btn" style="flex: 1; justify-content: center; background: #0f172a; color: white; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.4);">
            <i data-lucide="download" style="width: 18px;"></i> Simpan sebagai PDF
        </button>
    </div>
    <?php endif; ?>

    <div class="a4-container">
        <div class="header-top">
            <div class="company-brand">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" style="max-height: 50px;">
                <?php else: ?>
                    <div style="background: #1e293b; color: white; padding: 10px 14px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                        <i data-lucide="tool" style="width: 28px; height: 28px;"></i>
                    </div>
                <?php endif; ?>
                <div class="company-name">
                    <?php echo htmlspecialchars($tx['branch_name'] ?? 'INKA OTOSERVICE'); ?>
                </div>
            </div>
        </div>

        <div class="invoice-title-area">
            <div class="invoice-title-block"></div>
            <div class="invoice-title">INVOICE</div>
        </div>

        <div class="invoice-meta-container">
            <div class="invoice-meta">
                <table>
                    <tr>
                        <td>Invoice no</td>
                        <td>: <?php echo $invoice_no; ?></td>
                    </tr>
                    <tr>
                        <td>Date</td>
                        <td>: <?php echo date('d M Y', strtotime($tx['created_at'])); ?></td>
                    </tr>
                    <tr>
                        <td>Due Date</td>
                        <td>: <?php echo date('d M Y', strtotime($tx['created_at'])); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="parties-section">
            <div class="party-col">
                <div class="party-label">INFORMASI PELANGGAN:</div>
                <div class="party-name"><?php echo htmlspecialchars($tx['customer_name']); ?></div>
                <div class="party-address">
                    <div style="display: flex; align-items: center; gap: 5px; margin-bottom: 4px;"><i data-lucide="phone" style="width: 14px; height: 14px;"></i> <?php echo htmlspecialchars($tx['customer_phone'] ?? '-'); ?></div>
                    <div style="display: flex; align-items: center; gap: 5px;"><i data-lucide="car" style="width: 14px; height: 14px;"></i> <?php echo htmlspecialchars($tx['car_model'] ?? '-'); ?> (<?php echo htmlspecialchars($tx['license_plate'] ?? '-'); ?>)</div>
                </div>
            </div>
        </div>



        <table class="invoice-table">
            <thead>
                <tr>
                    <th width="5%">NO.</th>
                    <th width="45%">DESCRIPTION</th>
                    <th width="10%" class="text-center">QTY</th>
                    <th width="20%" class="text-right">PRICE</th>
                    <th width="20%" class="text-right">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $subtotal = 0;
                $no = 1;
                foreach ($items as $item): 
                    $total = $item['qty'] * $item['price_at_sale'];
                    $subtotal += $total;
                ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td class="text-center"><?php echo $item['qty']; ?></td>
                        <td class="text-right">Rp <?php echo number_format($item['price_at_sale'], 0, ',', '.'); ?></td>
                        <td class="text-right">Rp <?php echo number_format($total, 0, ',', '.'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="summary-section">
            <div class="terms-info">
                <div class="terms-block">
                    <div class="terms-title">Term and Conditions :</div>
                    <div class="terms-text"><?php echo htmlspecialchars($notes); ?></div>
                </div>
                <div class="terms-block">
                    <div class="terms-title">Payment Information :</div>
                    <div class="terms-text">Lunas via <?php echo htmlspecialchars($tx['payment_method']); ?> pada <?php echo date('d M Y H:i', strtotime($tx['created_at'])); ?></div>
                </div>
            </div>
            <div class="totals-box">
                <div class="totals-row">
                    <span>Subtotal</span>
                    <span>Rp <?php echo number_format($subtotal, 0, ',', '.'); ?></span>
                </div>
                <?php if ($tx['dp_amount'] > 0): ?>
                <div class="totals-row" style="color: #059669; font-weight: 600;">
                    <span>Down Payment (DP)</span>
                    <span>- Rp <?php echo number_format($tx['dp_amount'], 0, ',', '.'); ?></span>
                </div>
                <?php endif; ?>
                <div class="totals-row grand-total">
                    <span><?php echo $tx['status'] === 'Paid' ? 'TOTAL BAYAR' : 'SISA TAGIHAN'; ?></span>
                    <span>Rp <?php echo number_format($subtotal - $tx['dp_amount'], 0, ',', '.'); ?></span>
                </div>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
