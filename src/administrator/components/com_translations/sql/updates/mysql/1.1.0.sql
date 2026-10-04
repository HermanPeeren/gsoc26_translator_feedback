--
-- Brings an existing database up to the 1.1.0 schema.
--
-- A feedback row counts its distillation attempts and keeps the last error, so a row that
-- keeps failing is set aside (status 'failed') after three attempts instead of being sent
-- to the provider again on every run.
--

ALTER TABLE `#__translations_feedback`
    ADD COLUMN `attempts` tinyint unsigned NOT NULL DEFAULT 0 AFTER `status` /** CAN FAIL **/;
ALTER TABLE `#__translations_feedback`
    ADD COLUMN `last_error` varchar(500) NOT NULL DEFAULT '' AFTER `attempts` /** CAN FAIL **/;
