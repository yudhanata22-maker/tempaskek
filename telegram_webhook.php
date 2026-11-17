<?php
require_once __DIR__ . '/config.php';

$rawInput = file_get_contents('php://input');
$update = json_decode($rawInput, true);

if (!is_array($update)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $cbId = $cb['id'] ?? '';
    $data = $cb['data'] ?? '';
    $fromName = trim(($cb['from']['first_name'] ?? 'Staf') . ' ' . ($cb['from']['last_name'] ?? ''));

    $settings = getAllSettings($pdo);
    $token = trim($settings['tg_bot_token'] ?? '');

    if (strpos($data, 'complete_order:') === 0) {
        $invoiceNo = substr($data, strlen('complete_order:'));
        $result = completeOrderAndSyncTelegram($pdo, $invoiceNo, $fromName . ' (Telegram)');

        if ($token !== '' && $cbId !== '') {
            tgApiRequest($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => $result['success'] ? '✅ Pesanan ' . $invoiceNo . ' dikonfirmasi SELESAI!' : $result['message'],
                'show_alert' => false
            ]);
        }
    }
}

http_response_code(200);
echo 'OK';
