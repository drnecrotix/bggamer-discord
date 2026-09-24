CREATE TABLE IF NOT EXISTS `discord_bans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_reference` VARCHAR(24) NOT NULL,
  `discord_user_id` VARCHAR(32) NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `global_name` VARCHAR(80) DEFAULT NULL,
  `avatar_url` VARCHAR(255) DEFAULT NULL,
  `public_reason` VARCHAR(255) NOT NULL,
  `private_reason` TEXT DEFAULT NULL,
  `moderator_discord_id` VARCHAR(32) DEFAULT NULL,
  `moderator_name` VARCHAR(120) DEFAULT NULL,
  `banned_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `status` ENUM('active', 'temporary', 'expired', 'unbanned') NOT NULL DEFAULT 'active',
  `unbanned_at` DATETIME DEFAULT NULL,
  `appeal_status` ENUM('not_requested', 'pending', 'under_review', 'information_requested', 'approved', 'rejected', 'closed') NOT NULL DEFAULT 'not_requested',
  `active_discord_user_id` VARCHAR(32) GENERATED ALWAYS AS (
    CASE
      WHEN `status` IN ('active', 'temporary') THEN `discord_user_id`
      ELSE NULL
    END
  ) STORED,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discord_bans_public_reference` (`public_reference`),
  UNIQUE KEY `uq_discord_bans_active_discord_user_id` (`active_discord_user_id`),
  KEY `idx_discord_bans_status` (`status`),
  KEY `idx_discord_bans_banned_at` (`banned_at`),
  KEY `idx_discord_bans_unbanned_at` (`unbanned_at`),
  KEY `idx_discord_bans_username` (`username`),
  KEY `idx_discord_bans_global_name` (`global_name`),
  KEY `idx_discord_bans_appeal_status` (`appeal_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
