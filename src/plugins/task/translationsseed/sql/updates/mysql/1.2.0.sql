--
-- Brings an existing database up to the 1.2.0 schema.
--
-- A seeded string keeps a fingerprint of its source text and the pack's translation. When a new
-- version of the language pack changes either, the string is seeded again. Strings seeded before
-- this version get their fingerprint the first time a seed run sees them, without a request.
--

ALTER TABLE `#__translations_seeded_strings`
    ADD COLUMN `fingerprint` char(40) NOT NULL DEFAULT '' AFTER `last_error` /** CAN FAIL **/;
