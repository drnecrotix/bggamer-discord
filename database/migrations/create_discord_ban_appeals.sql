CREATE TABLE IF NOT EXISTS `discord_ban_appeals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_reference` VARCHAR(32) NOT NULL,
  `ban_id` BIGINT UNSIGNED DEFAULT NULL,
  `discord_user_id` VARCHAR(32) NOT NULL,
  `discord_username` VARCHAR(80) NOT NULL,
  `contact` VARCHAR(190) NOT NULL,
  `appeal_reason` VARCHAR(190) NOT NULL,
  `additional_information` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending', 'under_review', 'information_requested', 'approved', 'rejected', 'closed') NOT NULL DEFAULT 'pending',
  `moderator_response` TEXT DEFAULT NULL,
  `reviewed_by` VARCHAR(120) DEFAULT NULL,
  `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discord_ban_appeals_public_reference` (`public_reference`),
  KEY `idx_discord_ban_appeals_status` (`status`),
  KEY `idx_discord_ban_appeals_discord_user_id` (`discord_user_id`),
  KEY `idx_discord_ban_appeals_submitted_at` (`submitted_at`),
  KEY `idx_discord_ban_appeals_ban_id` (`ban_id`),
  CONSTRAINT `fk_discord_ban_appeals_ban`
    FOREIGN KEY (`ban_id`) REFERENCES `discord_bans` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
