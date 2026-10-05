--
-- Table structure for table `#__translations_seeded_strings`
--
-- One row per language string a seed run has sent to a provider. The status is 'seeded' once
-- the answer is recorded, 'retry' while an attempt has not succeeded yet, and 'failed' after
-- three attempts, when the string is no longer sent. The fingerprint is a hash of the source
-- text and the pack's translation; when a new pack version changes either, the string is seeded
-- again.
--

CREATE TABLE IF NOT EXISTS `#__translations_seeded_strings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `target_language` char(7) NOT NULL,
  `string_id` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'seeded',
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `fingerprint` char(40) NOT NULL DEFAULT '',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_language_string` (`target_language`, `string_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
