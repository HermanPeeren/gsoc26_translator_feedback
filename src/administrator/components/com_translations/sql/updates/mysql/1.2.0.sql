--
-- Brings an existing database up to the 1.2.0 schema.
--
-- A feedback row says how many places its correction stands for. The seed task translates a
-- text that occurs in several language files once, and writes one row for all of them; the
-- distiller weighs the correction by this number. Rows written by translators stand for one.
--

ALTER TABLE `#__translations_feedback`
    ADD COLUMN `occurrences` int unsigned NOT NULL DEFAULT 1 AFTER `last_error` /** CAN FAIL **/;
