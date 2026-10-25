--
-- Brings an existing database up to the 1.4.0 schema.
--
-- An item too long to translate in one request is translated in parts, over as many runs as it
-- takes. The parts answered so far are kept here, with a print of the source they belong to and
-- the terms they were translated with, so a run that ends before the item does has paid for
-- nothing twice and the next run carries on where it left off.
--

ALTER TABLE `#__translations_queue_states`
    ADD COLUMN `partial` mediumtext AFTER `machine_draft` /** CAN FAIL **/;
