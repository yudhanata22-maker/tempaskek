<?php
date_default_timezone_set('Asia/Jakarta');

// Otomatis membaca env variable jika PHP di-host di Railway,
// atau ganti nilai fallback di sebelah kanan jika PHP dijalankan di server/komputer terpisah.
$DB_HOST = getenv('MYSQLHOST') ?: 'crossover.proxy.rlwy.net';
$DB_PORT = getenv('MYSQLPORT') ?: '55865';
$DB_NAME = getenv('MYSQLDATABASE') ?: 'railway';
$DB_USER = getenv('MYSQLUSER') ?: 'root';
$DB_PASS = getenv('MYSQLPASSWORD') ?: 'LxgfaKHlBSdNqTxDiYMuQOhBHTmPnLxh';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Koneksi MySQL Railway gagal: ' . $e->getMessage()]);
    exit;
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getAllSettings(PDO $pdo): array {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function tgApiRequest(string $botToken, string $method, array $params = []): array {
    if (empty($botToken)) {
        return ['ok' => false, 'description' => 'Bot Token kosong'];
    }
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'description' => 'cURL Error: ' . $curlErr];
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Invalid JSON from Telegram'];
}

function formatOrderTelegramText(array $settings, array $tx, array $items, string $statusLine): string {
    $storeName = htmlspecialchars($settings['store_name'] ?? 'POS Store', ENT_QUOTES, 'UTF-8');
    $inv = htmlspecialchars($tx['invoice_no'], ENT_QUOTES, 'UTF-8');
    $time = date('d/m/Y H:i', strtotime($tx['created_at'])) . ' WIB';
    $type = htmlspecialchars($tx['order_type'], ENT_QUOTES, 'UTF-8');
    $info = !empty($tx['table_or_platform']) ? ' (' . htmlspecialchars($tx['table_or_platform'], ENT_QUOTES, 'UTF-8') . ')' : '';
    $pay = htmlspecialchars($tx['payment_method'], ENT_QUOTES, 'UTF-8');
    $total = 'Rp ' . number_format((float)$tx['grand_total'], 0, ',', '.');

    $lines = [];
    $lines[] = "🧾 <b>PESANAN BARU - {$storeName}</b>";
    $lines[] = "━━━━━━━━━━━━━━━━━━━━";
    $lines[] = "<b>No. Invoice:</b> <code>{$inv}</code>";
    $lines[] = "<b>Waktu:</b> {$time}";
    $lines[] = "<b>Tipe:</b> {$type}{$info}";
    $lines[] = "<b>Pembayaran:</b> {$pay}";
    $lines[] = "";
    $lines[] = "<b>Daftar Pesanan:</b>";

    foreach ($items as $idx => $item) {
        $num = $idx + 1;
        $name = htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8');
        $qty = (int)$item['qty'];
        $sub = 'Rp ' . number_format((float)$item['unit_price'] * $qty, 0, ',', '.');
        $lines[] = "{$num}. <b>{$name}</b> x{$qty} — {$sub}";
        if (!empty($item['note'])) {
            $note = htmlspecialchars($item['note'], ENT_QUOTES, 'UTF-8');
            $lines[] = "    <i>📝 Catatan: {$note}</i>";
        }
    }

    $lines[] = "━━━━━━━━━━━━━━━━━━━━";
    $lines[] = "💰 <b>TOTAL: {$total}</b>";
    $lines[] = "";
    $lines[] = $statusLine;

    return implode("\n", $lines);
}

function sendOrderToTelegram(PDO $pdo, array $tx, array $items): array {
    $settings = getAllSettings($pdo);
    if (($settings['tg_enabled'] ?? '0') !== '1') {
        return ['sent' => false, 'reason' => 'Notifikasi Telegram nonaktif'];
    }
    $token = trim($settings['tg_bot_token'] ?? '');
    $chatId = trim($settings['tg_chat_id'] ?? '');
    if ($token === '' || $chatId === '') {
        return ['sent' => false, 'reason' => 'Bot Token atau Chat ID belum diisi di Setting'];
    }

    $text = formatOrderTelegramText($settings, $tx, $items, "⏳ <b>Status: SEDANG DIPROSES</b>");
    $replyMarkup = [
        'inline_keyboard' => [
            [
                [
                    'text' => '✅ Konfirmasi Selesai',
                    'callback_data' => 'complete_order:' . $tx['invoice_no']
                ]
            ]
        ]
    ];

    $res = tgApiRequest($token, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => $replyMarkup
    ]);

    if (!empty($res['ok']) && isset($res['result']['message_id'])) {
        $msgId = $res['result']['message_id'];
        $stmt = $pdo->prepare("UPDATE transactions SET tg_message_id = ? WHERE id = ?");
        $stmt->execute([$msgId, $tx['id']]);
        return ['sent' => true, 'message_id' => $msgId];
    }

    return ['sent' => false, 'reason' => $res['description'] ?? 'Gagal mengirim ke Telegram'];
}

function completeOrderAndSyncTelegram(PDO $pdo, string $invoiceNo, string $completedBy = 'Kasir'): array {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE invoice_no = ?");
    $stmt->execute([$invoiceNo]);
    $tx = $stmt->fetch();

    if (!$tx) {
        return ['success' => false, 'message' => 'Transaksi tidak ditemukan'];
    }
    if ($tx['order_status'] === 'Void') {
        return ['success' => false, 'message' => 'Transaksi sudah di-Void'];
    }

    $now = date('Y-m-d H:i:s');
    if ($tx['order_status'] !== 'Selesai') {
        $upd = $pdo->prepare("UPDATE transactions SET order_status = 'Selesai', completed_by = ?, completed_at = ? WHERE id = ?");
        $upd->execute([$completedBy, $now, $tx['id']]);
        $tx['order_status'] = 'Selesai';
        $tx['completed_by'] = $completedBy;
        $tx['completed_at'] = $now;
    }

    // Update pesan di Telegram jika tg_message_id tersedia
    $settings = getAllSettings($pdo);
    $token = trim($settings['tg_bot_token'] ?? '');
    $chatId = trim($settings['tg_chat_id'] ?? '');

    if (!empty($tx['tg_message_id']) && $token !== '' && $chatId !== '') {
        $itemStmt = $pdo->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
        $itemStmt->execute([$tx['id']]);
        $items = $itemStmt->fetchAll();

        $doneTime = date('H:i', strtotime($tx['completed_at'] ?? $now)) . ' WIB';
        $bySafe = htmlspecialchars($tx['completed_by'] ?? $completedBy, ENT_QUOTES, 'UTF-8');
        $statusText = "✅ <b>Status: SELESAI</b> (oleh {$bySafe} • {$doneTime})";
        $newText = formatOrderTelegramText($settings, $tx, $items, $statusText);

        tgApiRequest($token, 'editMessageText', [
            'chat_id' => $chatId,
            'message_id' => (int)$tx['tg_message_id'],
            'text' => $newText,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => []]
        ]);
    }

    return ['success' => true, 'message' => 'Pesanan ditandai Selesai'];
}
