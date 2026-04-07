CREATE TABLE IF NOT EXISTS `#__simple_login` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL DEFAULT 0,
  `token` varchar(255) NOT NULL DEFAULT '',
  `expires` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `attempts` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__simple_login_throttle` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `username` VARCHAR(150) NOT NULL DEFAULT '',
  `created` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  INDEX (`ip`),
  INDEX (`username`),
  INDEX (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;