--
-- Brings an existing database up to the 1.3.0 schema.
--
-- A translation counts its attempts and keeps the last error, so an item that keeps failing
-- is set aside (state 'failed') after three attempts instead of being sent to the provider
-- again on every run.
--

ALTER TABLE `#__translations_queue_states`
    ADD COLUMN `attempts` tinyint unsigned NOT NULL DEFAULT 0 AFTER `translation_state` /** CAN FAIL **/;
ALTER TABLE `#__translations_queue_states`
    ADD COLUMN `last_error` varchar(500) NOT NULL DEFAULT '' AFTER `attempts` /** CAN FAIL **/;
