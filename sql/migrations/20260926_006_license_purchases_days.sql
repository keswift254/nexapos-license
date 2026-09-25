-- Short plans (the KSh 5 test plan is one day): license_purchases gains `days`, added on
-- top of `months` when the license is issued. Also done automatically the first time a
-- purchase action runs (see Database::ensurePurchaseTable), so an already-deployed database
-- needs no manual step; this file is the same statement for anyone applying migrations by hand.
ALTER TABLE license_purchases ADD COLUMN days INT NOT NULL DEFAULT 0 AFTER months;
