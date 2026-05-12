<?php
require_once 'includes/config.php';

echo "<pre>\n";
echo "=== UPDATE WHATSAPP NUMBERS PER BRANCH ===\n\n";

// Definisi nomor WA
$wa_bsd = '6281398563653';
$wa_depok = '6289678290743';

try {
    // 1. Ambil semua cabang
    $stmt = $pdo->query("SELECT id, name, whatsapp_number FROM branches");
    $branches = $stmt->fetchAll();

    echo "Ditemukan " . count($branches) . " cabang.\n\n";

    foreach ($branches as $br) {
        $name = $br['name'];
        $name_lower = strtolower($name);
        $new_wa = '';

        if (strpos($name_lower, 'bsd') !== false) {
            $new_wa = $wa_bsd;
            $type = "BSD";
        } elseif (strpos($name_lower, 'depok') !== false) {
            $new_wa = $wa_depok;
            $type = "DEPOK";
        } else {
            // Jika tidak ada kata kunci, default ke Depok atau biarkan jika sudah ada?
            // Kita paksa ke Depok sebagai pusat jika tidak dikenal
            $new_wa = $wa_depok;
            $type = "DEFAULT (DEPOK)";
        }

        if ($br['whatsapp_number'] !== $new_wa) {
            $update = $pdo->prepare("UPDATE branches SET whatsapp_number = ? WHERE id = ?");
            $update->execute([$new_wa, $br['id']]);
            echo "✓ Cabang: [{$name}] -> Diupdate ke {$type} ({$new_wa})\n";
        } else {
            echo "– Cabang: [{$name}] -> Sudah benar ({$new_wa})\n";
        }
    }

    echo "\n=== SELESAI ===\n";
    echo "Sekarang logic di booking-online.php akan mengambil nomor ini secara otomatis.\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
