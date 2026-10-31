CREATE TABLE IF NOT EXISTS `#__translations_languagefiles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `language` varchar(7) NOT NULL,
  `file` varchar(100) NOT NULL,
  `lang_key` varchar(190) NOT NULL,
  `source_hash` char(40) NOT NULL,
  `translation` mediumtext NOT NULL,
  `state` varchar(20) NOT NULL DEFAULT 'pending',
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `modified` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_language_file_key` (`language`, `file`, `lang_key`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
