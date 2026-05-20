CREATE TABLE IF NOT EXISTS `#__simple_login` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `selector` CHAR(16) NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `type` ENUM('login','invite') NOT NULL DEFAULT 'login',
  `created` DATETIME NOT NULL,
  `expires` DATETIME NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,

  PRIMARY KEY (`id`),

  UNIQUE KEY `idx_selector` (`selector`),

  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires`),
  KEY `idx_used` (`used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__simple_login_throttle` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `user_id` INT UNSIGNED DEFAULT NULL,
  `username` VARCHAR(150) DEFAULT NULL,

  `email_hash` CHAR(64) DEFAULT NULL,

  `ip` VARBINARY(16) NOT NULL,

  `status` VARCHAR(50) NOT NULL,

  `login_id` INT UNSIGNED DEFAULT NULL,

  `created` DATETIME NOT NULL,

  PRIMARY KEY (`id`),

  KEY `idx_ip_created` (`ip`, `created`),
  KEY `idx_user_created` (`user_id`, `created`),
  KEY `idx_login_created` (`login_id`, `created`),
  KEY `idx_status_created` (`status`, `created`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__simple_login_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `type` ENUM(
    		 'LoginFlow',
				 'AccountEvent',
				 'DebugDiagnostics',
				 'DebugFlowTrace',
				 'DebugRequestTrace',
				 'InviteFlow',
				 'LoginFlow',
				 'SecurityIncident'

  ) NOT NULL,

  `user_id` INT UNSIGNED DEFAULT NULL,
  `username` VARCHAR(150) DEFAULT NULL,

  `email_hash` CHAR(64) DEFAULT NULL,

  `ip` VARBINARY(16) NOT NULL,
  `user_agent` VARCHAR(512) DEFAULT NULL,

  `status` VARCHAR(50) NOT NULL,
  `login_id` INT UNSIGNED DEFAULT NULL,

  `created` DATETIME NOT NULL,

  PRIMARY KEY (`id`),

  KEY `idx_type_created` (`type`, `created`),
  KEY `idx_status_created` (`status`, `created`),
  KEY `idx_user` (`user_id`),
  KEY `idx_login` (`login_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;