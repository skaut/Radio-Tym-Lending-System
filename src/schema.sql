-- RTLS database schema.
--
-- The database file itself (src/rtls.sqlite) is NOT in git - it holds real
-- borrower names. This file is the tracked source of truth for its structure,
-- and src/index.php applies it on first boot when the database is missing or
-- has no tables yet.
--
-- Keep this in sync by hand for now. Batch 4 of the review replaces it with a
-- versioned migration runner.

CREATE TABLE IF NOT EXISTS "radios"
(
    id INTEGER PRIMARY KEY,
    radioId TEXT,
    name TEXT,
    status TEXT,
    "last-action-time" DATETIME,
    "last-borrower" TEXT,
    channel TEXT
);
