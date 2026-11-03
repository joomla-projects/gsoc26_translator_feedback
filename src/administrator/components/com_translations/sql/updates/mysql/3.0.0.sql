--
-- Brings an existing database up to the 3.0.0 schema.
--
-- One row per request sent to a translation or distillation provider, while the Log Requests
-- option is on: what was sent, what came back, and the numbers to tune the settings with.
--

CREATE TABLE IF NOT EXISTS `#__translations_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime NOT NULL,
  `kind` varchar(20) NOT NULL,
  `content_type` varchar(100) NOT NULL DEFAULT '',
  `content_id` int unsigned NOT NULL DEFAULT 0,
  `source_language` char(7) NOT NULL DEFAULT '',
  `target_language` char(7) NOT NULL DEFAULT '',
  `strings` int unsigned NOT NULL DEFAULT 0,
  `characters` int unsigned NOT NULL DEFAULT 0,
  `terminology_rules` int unsigned NOT NULL DEFAULT 0,
  `style_rules` int unsigned NOT NULL DEFAULT 0,
  `preservation_rules` int unsigned NOT NULL DEFAULT 0,
  `settings` text,
  `duration_ms` int unsigned NOT NULL DEFAULT 0,
  `outcome` varchar(20) NOT NULL,
  `message` varchar(1000) NOT NULL DEFAULT '',
  `request` mediumtext,
  `response` mediumtext,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created`),
  KEY `idx_item` (`content_type`, `content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
