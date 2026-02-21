-- =====================================================
-- TABEL: restaurant_settings
-- Untuk menyimpan setting harga per paket per restoran
-- Hanya bisa diedit oleh role selain staff/manager
-- =====================================================

CREATE TABLE IF NOT EXISTS `restaurant_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurant_name` varchar(255) NOT NULL,
  `price_per_packet` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_percentage` decimal(5,2) NOT NULL DEFAULT 5.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_restaurant_name` (`restaurant_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TABEL: restaurant_consumptions
-- Untuk mencatat konsumsi harian dari restoran
-- =====================================================

CREATE TABLE IF NOT EXISTS `restaurant_consumptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `consumption_code` varchar(50) NOT NULL,
  `restaurant_id` int(11) NOT NULL,
  `restaurant_name` varchar(255) NOT NULL,
  `recipient_user_id` int(11) NOT NULL COMMENT 'User yang login/menginput',
  `recipient_name` varchar(255) NOT NULL COMMENT 'Nama user yang menerima konsumsi',
  `delivery_date` date NOT NULL,
  `delivery_time` time NOT NULL,
  `packet_count` int(11) NOT NULL DEFAULT 0,
  `price_per_packet` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_percentage` decimal(5,2) NOT NULL DEFAULT 5.00,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'packet_count * price_per_packet',
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'subtotal * tax_percentage / 100',
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'subtotal + tax_amount',
  `ktp_file` varchar(500) DEFAULT NULL COMMENT 'Foto KTP karyawan restoran',
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','paid','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_consumption_code` (`consumption_code`),
  KEY `idx_restaurant_id` (`restaurant_id`),
  KEY `idx_recipient_user_id` (`recipient_user_id`),
  KEY `idx_delivery_date` (`delivery_date`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- INSERT DATA RESTORAN CONTOH
-- =====================================================

INSERT INTO `restaurant_settings` (`restaurant_name`, `price_per_packet`, `tax_percentage`, `is_active`) VALUES
('Up And Atom', 400.00, 5.00, 1),
('Queen Beach', 450.00, 5.00, 1),
('Burger Shot', 350.00, 5.00, 1),
('Pizza This', 500.00, 5.00, 1)
ON DUPLICATE KEY UPDATE `restaurant_name` = `restaurant_name`;
