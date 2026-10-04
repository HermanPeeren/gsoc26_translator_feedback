--
-- Brings an existing database up to the 1.1.0 schema.
--
-- A seeded string counts its attempts and keeps the last error, so a string that keeps
-- failing is set aside (status 'failed') after three attempts instead of being sent to the
-- provider again on every run. Strings seeded before this version keep the default 'seeded'.
--

ALTER TABLE `#__translations_seeded_strings`
    ADD COLUMN `status` varchar(20) NOT NULL DEFAULT 'seeded' AFTER `string_id` /** CAN FAIL **/;
ALTER TABLE `#__translations_seeded_strings`
    ADD COLUMN `attempts` tinyint unsigned NOT NULL DEFAULT 0 AFTER `status` /** CAN FAIL **/;
ALTER TABLE `#__translations_seeded_strings`
    ADD COLUMN `last_error` varchar(500) NOT NULL DEFAULT '' AFTER `attempts` /** CAN FAIL **/;
