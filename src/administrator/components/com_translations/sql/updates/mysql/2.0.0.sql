--
-- Brings an existing database up to the 2.0.0 schema.
--
-- A translation is not on the site while its source is not. When a source goes off the site, its
-- live translations follow it and are marked here, so that exactly those come back when the
-- source is published again, and a translation somebody took off the site stays off.
--

ALTER TABLE `#__translations_queue_states`
    ADD COLUMN `held_by_source` tinyint unsigned NOT NULL DEFAULT 0 AFTER `partial` /** CAN FAIL **/;
