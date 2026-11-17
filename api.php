<?php
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    case 'bootstrap':
        $settings = getAllSettings($pdo);
        // Jangan kirim PIN mentah ke frontend kecuali untuk pengecekan sisi server
        unset($settings['owner_pin']);

        $products = $pdo->query("SELECT * FROM products ORDER BY category ASC, id ASC")->fetchAll();
        $members = $pdo->query("SELECT * FROM members ORDER BY name ASC")->fetchAll();

        $txStmt = $pdo->query("
            SELECT t.*, m.name AS member_name 
            FROM transactions t 
            LEFT JOIN members m ON t.member_id = m.id 
            ORDER BY t.id DESC LIMIT 100
        ");
        $transactions = $txStmt->fetchAll();

        $itemStmt = $pdo->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
        foreach ($transactions as &$tx) {
            $itemStmt->execute([$tx['id']]);
            $tx['items'] = $itemStmt->fetchAll();
        }

        jsonResponse([
            'success' => true,
            'settings' => $settings,
            'products' => $products,
            'members' => $members,
            'transactions' => $transactions
        ]);
        break;

    case 'create_order':
        $items = $input['items'] ?? [];
        if (empty($items)) {
            jsonResponse(['success' => false, 'message' => 'Keranjang belanja masih kosong'], 400);
        }

        try {
            $pdo->beginTransaction();

            $invoiceNo = 'INV-' . date('Ymd-His') . '-' . rand(10, 99);
            $orderType = $input['order_type'] ?? 'Dine-In';
            $tableOrPlatform = trim($input['table_or_platform'] ?? '');
            $memberId = !empty($input['member_id']) ? (int)$input['member_id'] : null;
            $subtotal = (float)($input['subtotal'] ?? 0);
            $discountAmount = (float)($input['discount_amount'] ?? 0);
            $serviceAmount = (float)($input['service_amount'] ?? 0);
            $taxAmount = (float)($input['tax_amount'] ?? 0);
            $grandTotal = (float)($input['grand_total'] ?? 0);
            $paymentMethod = $input['payment_method'] ?? 'Tunai';
            $cashReceived = (float)($input['cash_received'] ?? $grandTotal);
            $cashChange = max(0, $cashReceived - $grandTotal);
            $createdAt = date('Y-m-d H:i:s');

            $totalCost = 0;
            $preparedItems = [];

            $prodCheck = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
            $stockUpd = $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?");

            foreach ($items as $it) {
                $prodId = (int)$it['product_id'];
                $qty = max(1, (int)$it['qty']);
                $unitPrice = (float)$it['unit_price'];
                $note = trim($it['note'] ?? '');

                $prodCheck->execute([$prodId]);
                $prodRow = $prodCheck->fetch();

                $unitCost = $prodRow ? (float)$prodRow['cost'] : 0;
                $prodName = $prodRow ? $prodRow['name'] : ($it['product_name'] ?? 'Item');
                $isCustom = $prodRow ? (int)$prodRow['is_custom_price'] : 0;

                if ($isCustom === 1) {
                    $prodName = 'Sundukan';
                } elseif ($prodRow) {
                    if ((int)$prodRow['stock'] < $qty) {
                        throw new Exception("Stok {$prodName} tidak mencukupi (Sisa: {$prodRow['stock']})");
                    }
                    $stockUpd->execute([$qty, $prodId]);
                }

                $totalCost += ($unitCost * $qty);
                $preparedItems[] = [
                    'product_id' => $prodId,
                    'product_name' => $prodName,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'note' => $note
                ];
            }

            $txInsert = $pdo->prepare("
                INSERT INTO transactions 
                (invoice_no, order_type, table_or_platform, member_id, subtotal, discount_amount, service_amount, tax_amount, grand_total, total_cost, payment_method, cash_received, cash_change, order_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Diproses', ?)
            ");
            $txInsert->execute([
                $invoiceNo, $orderType, $tableOrPlatform, $memberId,
                $subtotal, $discountAmount, $serviceAmount, $taxAmount,
                $grandTotal, $totalCost, $paymentMethod, $cashReceived, $cashChange, $createdAt
            ]);
            $txId = (int)$pdo->lastInsertId();

            $itemInsert = $pdo->prepare("
                INSERT INTO transaction_items (transaction_id, product_id, product_name, qty, unit_price, unit_cost, note)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($preparedItems as $pi) {
                $itemInsert->execute([
                    $txId, $pi['product_id'], $pi['product_name'],
                    $pi['qty'], $pi['unit_price'], $pi['unit_cost'], $pi['note']
                ]);
            }

            if ($memberId) {
                $earnedPoints = (int)floor($grandTotal / 2000);
                $memUpd = $pdo->prepare("UPDATE members SET points = points + ?, total_spent = total_spent + ? WHERE id = ?");
                $memUpd->execute([$earnedPoints, $grandTotal, $memberId]);
            }

            $pdo->commit();

            $txRecord = [
                'id' => $txId,
                'invoice_no' => $invoiceNo,
                'order_type' => $orderType,
                'table_or_platform' => $tableOrPlatform,
                'grand_total' => $grandTotal,
                'payment_method' => $paymentMethod,
                'created_at' => $createdAt
            ];

            $tgResult = sendOrderToTelegram($pdo, $txRecord, $preparedItems);

            jsonResponse([
                'success' => true,
                'invoice_no' => $invoiceNo,
                'transaction_id' => $txId,
                'telegram' => $tgResult
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
        break;

    case 'complete_order':
        $invoiceNo = trim($input['invoice_no'] ?? '');
        $res = completeOrderAndSyncTelegram($pdo, $invoiceNo, 'Kasir (Web POS)');
        jsonResponse($res, $res['success'] ? 200 : 400);
        break;

    case 'void_order':
        $txId = (int)($input['transaction_id'] ?? 0);
        $pin = trim($input['pin'] ?? '');
        $settings = getAllSettings($pdo);

        if ($pin !== ($settings['owner_pin'] ?? '123456')) {
            jsonResponse(['success' => false, 'message' => 'PIN Owner salah!'], 403);
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? FOR UPDATE");
            $stmt->execute([$txId]);
            $tx = $stmt->fetch();

            if (!$tx || $tx['order_status'] === 'Void') {
                throw new Exception('Transaksi tidak valid atau sudah di-Void');
            }

            $itemsStmt = $pdo->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
            $itemsStmt->execute([$txId]);
            $items = $itemsStmt->fetchAll();

            $restoreStock = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ? AND is_custom_price = 0");
            foreach ($items as $it) {
                if ($it['product_id']) {
                    $restoreStock->execute([$it['qty'], $it['product_id']]);
                }
            }

            if ($tx['member_id']) {
                $deductPoints = (int)floor((float)$tx['grand_total'] / 2000);
                $memUpd = $pdo->prepare("UPDATE members SET points = GREATEST(0, points - ?), total_spent = GREATEST(0, total_spent - ?) WHERE id = ?");
                $memUpd->execute([$deductPoints, $tx['grand_total'], $tx['member_id']]);
            }

            $upd = $pdo->prepare("UPDATE transactions SET order_status = 'Void' WHERE id = ?");
            $upd->execute([$txId]);

            $pdo->commit();
            jsonResponse(['success' => true, 'message' => 'Transaksi berhasil di-Void & stok dikembalikan']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
        break;

    case 'save_product':
        $id = !empty($input['id']) ? (int)$input['id'] : null;
        $sku = trim($input['sku'] ?? '');
        $name = trim($input['name'] ?? '');
        $category = trim($input['category'] ?? 'Makanan');
        $price = (float)($input['price'] ?? 0);
        $cost = (float)($input['cost'] ?? 0);
        $stock = (int)($input['stock'] ?? 0);
        $isCustom = !empty($input['is_custom_price']) ? 1 : 0;

        if ($sku === '' || $name === '') {
            jsonResponse(['success' => false, 'message' => 'SKU dan Nama Produk wajib diisi'], 400);
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE products SET sku=?, name=?, category=?, price=?, cost=?, stock=?, is_custom_price=? WHERE id=?");
            $stmt->execute([$sku, $name, $category, $price, $cost, $stock, $isCustom, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, price, cost, stock, is_custom_price) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$sku, $name, $category, $price, $cost, $stock, $isCustom]);
        }
        jsonResponse(['success' => true]);
        break;

    case 'restock_product':
        $id = (int)($input['id'] ?? 0);
        $addQty = (int)($input['add_qty'] ?? 0);
        $stmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        $stmt->execute([$addQty, $id]);
        jsonResponse(['success' => true]);
        break;

    case 'delete_product':
        $id = (int)($input['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['success' => true]);
        break;

    case 'save_member':
        $name = trim($input['name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        if ($name === '' || $phone === '') {
            jsonResponse(['success' => false, 'message' => 'Nama dan No HP wajib diisi'], 400);
        }
        $stmt = $pdo->prepare("INSERT INTO members (name, phone, points, total_spent) VALUES (?, ?, 0, 0)");
        $stmt->execute([$name, $phone]);
        jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

    case 'save_settings':
        $allowedKeys = [
            'store_name', 'store_address', 'store_phone', 'receipt_footer',
            'tax_enabled', 'tax_rate', 'service_enabled', 'service_rate',
            'tg_enabled', 'tg_bot_token', 'tg_chat_id'
        ];
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $input)) {
                $stmt->execute([$key, (string)$input[$key]]);
            }
        }
        if (!empty($input['owner_pin'])) {
            $stmt->execute(['owner_pin', trim((string)$input['owner_pin'])]);
        }
        jsonResponse(['success' => true, 'message' => 'Semua pengaturan berhasil disimpan ke MySQL']);
        break;

    case 'test_telegram':
        $token = trim($input['tg_bot_token'] ?? '');
        $chatId = trim($input['tg_chat_id'] ?? '');
        if ($token === '' || $chatId === '') {
            jsonResponse(['success' => false, 'message' => 'Isi Bot Token dan Chat ID terlebih dahulu'], 400);
        }
        $res = tgApiRequest($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ <b>Tes Koneksi POS majoo Berhasil!</b>\nBot siap mengirim notifikasi pesanan masuk ke grup/channel ini.",
            'parse_mode' => 'HTML'
        ]);
        if (!empty($res['ok'])) {
            jsonResponse(['success' => true, 'message' => 'Pesan tes berhasil dikirim ke Telegram!']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Gagal: ' . ($res['description'] ?? 'Unknown error')], 400);
        }
        break;

    case 'set_webhook':
        $token = trim($input['tg_bot_token'] ?? '');
        $webhookUrl = trim($input['webhook_url'] ?? '');
        if ($token === '' || $webhookUrl === '') {
            jsonResponse(['success' => false, 'message' => 'Bot Token dan URL Webhook wajib diisi'], 400);
        }
        $res = tgApiRequest($token, 'setWebhook', ['url' => $webhookUrl]);
        if (!empty($res['ok'])) {
            jsonResponse(['success' => true, 'message' => 'Webhook Telegram aktif: ' . ($res['description'] ?? 'OK')]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Gagal set webhook: ' . ($res['description'] ?? 'Error')], 400);
        }
        break;

    case 'sync_telegram':
        // Fallback Polling: memproses klik tombol di Telegram tanpa perlu HTTPS Webhook
        $settings = getAllSettings($pdo);
        $token = trim($settings['tg_bot_token'] ?? '');
        if ($token === '') {
            jsonResponse(['success' => false, 'message' => 'Bot Token belum diatur'], 400);
        }
        $offset = (int)($settings['tg_last_update_id'] ?? 0) + 1;
        $updates = tgApiRequest($token, 'getUpdates', [
            'offset' => $offset,
            'timeout' => 2,
            'allowed_updates' => ['callback_query']
        ]);

        if (empty($updates['ok'])) {
            jsonResponse(['success' => false, 'message' => $updates['description'] ?? 'Gagal mengambil update Telegram (Pastikan Webhook nonaktif jika pakai mode Sync Polling)'], 400);
        }

        $processed = 0;
        $maxUpdateId = (int)($settings['tg_last_update_id'] ?? 0);

        foreach ($updates['result'] as $upd) {
            $maxUpdateId = max($maxUpdateId, (int)$upd['update_id']);
            if (isset($upd['callback_query'])) {
                $cb = $upd['callback_query'];
                $data = $cb['data'] ?? '';
                if (strpos($data, 'complete_order:') === 0) {
                    $invoiceNo = substr($data, strlen('complete_order:'));
                    $fromName = trim(($cb['from']['first_name'] ?? 'Staf') . ' ' . ($cb['from']['last_name'] ?? ''));
                    completeOrderAndSyncTelegram($pdo, $invoiceNo, $fromName . ' (Telegram)');
                    tgApiRequest($token, 'answerCallbackQuery', [
                        'callback_query_id' => $cb['id'],
                        'text' => '✅ Pesanan ' . $invoiceNo . ' selesai!'
                    ]);
                    $processed++;
                }
            }
        }

        if ($maxUpdateId > 0) {
            $updSetting = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'tg_last_update_id'");
            $updSetting->execute([(string)$maxUpdateId]);
        }

        jsonResponse(['success' => true, 'processed' => $processed, 'message' => "Sinkronisasi selesai ({$processed} pesanan dikonfirmasi dari Telegram)"]);
        break;

    case 'close_shift':
        $opening = (float)($input['opening_cash'] ?? 0);
        $expected = (float)($input['expected_cash'] ?? 0);
        $actual = (float)($input['actual_cash'] ?? 0);
        $diff = $actual - $expected;
        $notes = trim($input['notes'] ?? '');

        $stmt = $pdo->prepare("INSERT INTO shifts (opening_cash, expected_cash, actual_cash, difference, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$opening, $expected, $actual, $diff, $notes]);
        jsonResponse(['success' => true, 'message' => 'Laporan Tutup Shift tersimpan ke MySQL']);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Endpoint tidak dikenal'], 404);
}
