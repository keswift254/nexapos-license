-- Plans managed from generator.html, and lifetime plans. Both are also done automatically
-- the first time a plan / purchase action runs (Services/Plans.php, Database::ensurePurchaseTable),
-- so an already-deployed database needs no manual step; this file is the same statements for
-- anyone applying migrations by hand (a hand-made license_plans starts EMPTY: add the plans in the
-- generator's "Plans & prices" card - only the automatic creation fills in the starting set).
CREATE TABLE IF NOT EXISTS license_plans (
    id VARCHAR(20) NOT NULL PRIMARY KEY,
    label VARCHAR(60) NOT NULL,
    months INT NOT NULL DEFAULT 0,
    days INT NOT NULL DEFAULT 0,
    lifetime TINYINT(1) NOT NULL DEFAULT 0,
    amount_kes INT NOT NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100
);
ALTER TABLE license_purchases ADD COLUMN lifetime TINYINT(1) NOT NULL DEFAULT 0 AFTER days;
