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
  -- Possible values: pending, translating, review, approved, published
  `translation_state` varchar(20) NOT NULL DEFAULT 'pending',
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
  `source_text` text NOT NULL,
  `machine_draft` text NOT NULL,
  `human_correction` text NOT NULL,
  `diff_data` text,
  `target_language` char(7) NOT NULL,
  `context_tags` varchar(500) NOT NULL DEFAULT '',
  `translator_id` int unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
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
  KEY `idx_confidence` (`confidence`),
  KEY `idx_weight` (`weight`),
  KEY `idx_origin` (`source_origin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
