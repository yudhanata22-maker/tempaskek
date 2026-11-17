<?php
require_once __DIR__ . '/config.php';

$queries = [
    "CREATE TABLE IF NOT EXISTS `settings` (
      `setting_key` VARCHAR(100) PRIMARY KEY,
      `setting_value` TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('store_name', 'Angkringan & Kopi Nusantara'),
    ('store_address', 'Jl. Raya Utama No. 45, Trenggalek'),
    ('store_phone', '0812-3456-7890'),
    ('receipt_footer', 'Terima kasih telah berkunjung! Selamat menikmati hidangan.'),
    ('tax_enabled', '1'),
    ('tax_rate', '10'),
    ('service_enabled', '0'),
    ('service_rate', '5'),
    ('owner_pin', '123456'),
    ('tg_enabled', '0'),
    ('tg_bot_token', ''),
    ('tg_chat_id', ''),
    ('tg_last_update_id', '0')",

    "CREATE TABLE IF NOT EXISTS `products` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `sku` VARCHAR(50) NOT NULL UNIQUE,
      `name` VARCHAR(150) NOT NULL,
      `category` VARCHAR(50) NOT NULL,
      `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `stock` INT NOT NULL DEFAULT 0,
      `is_custom_price` TINYINT(1) NOT NULL DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "INSERT IGNORE INTO `products` (`sku`, `name`, `category`, `price`, `cost`, `stock`, `is_custom_price`) VALUES
    ('MKN-001', 'Sundukan (Input Harga)', 'Makanan', 0, 0, 9999, 1),
    ('MKN-002', 'Nasi Kucing Teri Sambal', 'Makanan', 4000, 2200, 45, 0),
    ('MKN-003', 'Nasi Bakar Ayam Suwir', 'Makanan', 8000, 4500, 30, 0),
    ('MKN-004', 'Mie Nyemek Spesial', 'Makanan', 12000, 6000, 25, 0),
    ('KPI-001', 'Kopi Susu Gula Aren', 'Kopi', 12000, 4800, 50, 0),
    ('KPI-002', 'Kopi Jos Arang', 'Kopi', 7000, 2500, 60, 0),
    ('KPI-003', 'Americano Iced', 'Kopi', 10000, 3500, 40, 0),
    ('NKP-001', 'Es Teh Tarik', 'Non-Kopi', 8000, 2000, 80, 0),
    ('NKP-002', 'Wedang Jahe Geprek', 'Non-Kopi', 7000, 2200, 50, 0),
    ('NKP-003', 'Matcha Latte Iced', 'Non-Kopi', 13000, 5200, 35, 0),
    ('CML-001', 'Tahu Walik Crispy', 'Camilan', 10000, 4500, 30, 0),
    ('CML-002', 'Pisang Bakar Coklat Keju', 'Camilan', 12000, 5500, 25, 0)",

    "CREATE TABLE IF NOT EXISTS `members` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(120) NOT NULL,
      `phone` VARCHAR(30) NOT NULL UNIQUE,
      `points` INT NOT NULL DEFAULT 0,
      `total_spent` DECIMAL(14,2) NOT NULL DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "INSERT IGNORE INTO `members` (`name`, `phone`, `points`, `total_spent`) VALUES
    ('Budi Santoso', '081234567801', 120, 240000),
    ('Siti Aminah', '085678901234', 65, 130000)",

    "CREATE TABLE IF NOT EXISTS `transactions` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `invoice_no` VARCHAR(50) NOT NULL UNIQUE,
      `order_type` VARCHAR(30) NOT NULL,
      `table_or_platform` VARCHAR(60) DEFAULT '',
      `member_id` INT NULL,
      `subtotal` DECIMAL(12,2) NOT NULL,
      `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `service_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `grand_total` DECIMAL(12,2) NOT NULL,
      `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `payment_method` VARCHAR(30) NOT NULL,
      `cash_received` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `cash_change` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `order_status` ENUM('Diproses', 'Selesai', 'Void') NOT NULL DEFAULT 'Diproses',
      `tg_message_id` BIGINT NULL,
      `completed_by` VARCHAR(100) NULL,
      `completed_at` DATETIME NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `transaction_items` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `transaction_id` INT NOT NULL,
      `product_id` INT NULL,
      `product_name` VARCHAR(150) NOT NULL,
      `qty` INT NOT NULL,
      `unit_price` DECIMAL(12,2) NOT NULL,
      `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `note` VARCHAR(255) DEFAULT '',
      FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `shifts` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `opening_cash` DECIMAL(12,2) NOT NULL,
      `expected_cash` DECIMAL(12,2) NOT NULL,
      `actual_cash` DECIMAL(12,2) NOT NULL,
      `difference` DECIMAL(12,2) NOT NULL,
      `notes` TEXT,
      `closed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($queries as $idx => $sql) {
    $pdo->exec($sql);
}

echo "Berhasil membuat semua 6 tabel dan mengisi data awal ke MySQL Railway!";
