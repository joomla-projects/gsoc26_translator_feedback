--
-- Table structure for table `#__translations_seeded_strings`
--

CREATE TABLE IF NOT EXISTS `#__translations_seeded_strings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `target_language` char(7) NOT NULL,
  `string_id` varchar(255) NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_language_string` (`target_language`, `string_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
