--
-- Table structure for table `#__translations_queue`
--
-- One row per source-language article. Per-target-language translation state
-- lives in `#__translations_queue_states` (one row per (queue_id, target_language)).
--

CREATE TABLE IF NOT EXISTS `#__translations_queue` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `content_type` varchar(50) NOT NULL DEFAULT '',
  `content_id` int unsigned NOT NULL,
  `do_not_translate` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_content` (`content_type`, `content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__translations_queue_states`
--
-- Per-target-language translation state for each queued source article.
-- One row per (queue_id, target_language).
--

CREATE TABLE IF NOT EXISTS `#__translations_queue_states` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `queue_id` int unsigned NOT NULL,
  `target_language` char(7) NOT NULL,
  -- Possible values: pending, translating, review, approved, published, failed
  `translation_state` varchar(20) NOT NULL DEFAULT 'pending',
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  -- The source's #__history version this translation was made from; 0 when none was recorded.
  `source_version_id` int unsigned NOT NULL DEFAULT 0,
  -- The translation as it was produced, as a JSON field map, to pair against the translator's correction.
  `machine_draft` mediumtext,
  -- The parts of an item too long to translate in one request that are translated already, as JSON:
  -- a print of the source they belong to, the translated parts, and the terms they were translated
  -- with. Cleared once the draft is saved; NULL while an item needs no more than one request.
  `partial` mediumtext,
  -- 1 while the translation is kept off the site because its source is: it is published again
  -- when the source is. A translation taken off the site by hand is never held.
  `held_by_source` tinyint unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_queue_lang` (`queue_id`, `target_language`),
  KEY `idx_state_lang` (`translation_state`, `target_language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__translations_feedback`
--

CREATE TABLE IF NOT EXISTS `#__translations_feedback` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `queue_id` int unsigned NOT NULL,
  `source_text` mediumtext NOT NULL,
  `machine_draft` mediumtext NOT NULL,
  `human_correction` mediumtext NOT NULL,
  `diff_data` mediumtext,
  `target_language` char(7) NOT NULL,
  `source_origin` varchar(20) NOT NULL DEFAULT 'distilled',
  `context_tags` varchar(500) NOT NULL DEFAULT '',
  `translator_id` int unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `occurrences` int unsigned NOT NULL DEFAULT 1,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_language` (`target_language`),
  KEY `idx_translator` (`translator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__translations_rules`
--

CREATE TABLE IF NOT EXISTS `#__translations_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `rule_name` varchar(255) NOT NULL DEFAULT '',
  `rule_type` varchar(20) NOT NULL,
  `target_language` char(7) NOT NULL,
  `rule_text` text NOT NULL,
  `source_term` varchar(255) DEFAULT NULL,
  `source_term_standard` varchar(255) DEFAULT NULL,
  `target_term` varchar(255) DEFAULT NULL,
  `search_keywords` varchar(500) NOT NULL DEFAULT '',
  `confidence` decimal(3,2) NOT NULL DEFAULT 0.00,
  `weight` int NOT NULL DEFAULT 0,
  `source_origin` varchar(20) NOT NULL DEFAULT 'distilled',
  `source_feedback_ids` text,
  `params` text,
  `state` tinyint NOT NULL DEFAULT 0,
  `ordering` int NOT NULL DEFAULT 0,
  `checked_out` int unsigned DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `modified_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_type_lang` (`rule_type`, `target_language`),
  KEY `idx_state` (`state`),
  KEY `idx_source_term` (`source_term`),
  KEY `idx_source_term_standard` (`source_term_standard`),
  KEY `idx_confidence` (`confidence`),
  KEY `idx_weight` (`weight`),
  KEY `idx_origin` (`source_origin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__translations_standard_forms`
--

CREATE TABLE IF NOT EXISTS `#__translations_standard_forms` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `language` char(7) NOT NULL,
  `word` varchar(255) NOT NULL,
  `standard_form` varchar(255) NOT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_language_word` (`language`, `word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `#__translations_log`
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
