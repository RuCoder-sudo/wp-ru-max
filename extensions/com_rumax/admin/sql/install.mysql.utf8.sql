CREATE TABLE IF NOT EXISTS `#__rumax_settings` (
  `id` int unsigned NOT NULL,
  `data` longtext NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__rumax_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(32) NOT NULL,
  `network` varchar(32) NOT NULL DEFAULT 'max',
  `content_id` bigint unsigned NOT NULL DEFAULT 0,
  `payload` longtext NOT NULL,
  `due_at` datetime NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `last_error` text NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rumax_queue_due` (`status`, `due_at`),
  KEY `idx_rumax_queue_content` (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__rumax_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_time` datetime NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `event_data` longtext NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'info',
  `details` longtext NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rumax_history_time` (`event_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__rumax_post_meta` (
  `content_id` bigint unsigned NOT NULL,
  `data` longtext NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__rumax_conversations` (
  `id` varchar(64) NOT NULL,
  `data` longtext NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;