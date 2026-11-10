-- =============================================================================
-- group-d-date-audit.sql — READ-ONLY. Changes nothing. Safe to run on production.
-- =============================================================================
--
-- What it answers
--   Before fix/lagos-day-boundary, some business-dated columns were stamped with
--   the server (UTC) date. Rows written between 00:00 and 01:00 Lagos (23:00–
--   00:00 UTC) therefore carry the previous day's date. This counts them, per
--   table and month, so a backfill can be decided on numbers. No backfill was
--   done, by decision. Frozen statement snapshots are not read or touched.
--
-- How to run (MySQL; Lagos is UTC+1 with no daylight saving, hence + 1 HOUR)
--   On the server, from the app directory:
--     cd /home/selumcom/domains/gadgetplug.com.ng/gadgetplug
--     mysql -h <DB_HOST> -u <DB_USERNAME> -p <DB_DATABASE> < docs/ops/group-d-date-audit.sql
--   (values from that directory's .env). Or paste the whole file into
--   phpMyAdmin's SQL tab for the GadgetPlug database.
--   The statements run inside a READ ONLY transaction that is rolled back, so
--   even a mistaken edit to this file could not write.
--
-- Reading the first result
--   mismatched     stored date <> Lagos date of created_at, any reason
--   bug_signature  stored date = UTC date of created_at, and that is not the
--                  Lagos date: the 00:00–01:00 Lagos rows written by the bug
--   other          mismatched for another reason (a date picked or backdated)
-- Reading the second (expenses only)
--   picked_more_than_1_day_away  incurred_at deliberately chosen by the user
--   exactly_1_day_earlier        candidates for the bug (or a picked yesterday)
-- =============================================================================

SET SESSION TRANSACTION READ ONLY;
START TRANSACTION;

SELECT 'pos_debt_payments.collected_at' AS tbl,
       DATE_FORMAT(created_at + INTERVAL 1 HOUR, '%Y-%m') AS month,
       COUNT(*) AS total_rows,
       SUM(collected_at <> DATE(created_at + INTERVAL 1 HOUR)) AS mismatched,
       SUM(collected_at = DATE(created_at) AND DATE(created_at) <> DATE(created_at + INTERVAL 1 HOUR)) AS bug_signature,
       SUM(collected_at <> DATE(created_at + INTERVAL 1 HOUR) AND collected_at <> DATE(created_at)) AS other
FROM pos_debt_payments GROUP BY month
UNION ALL
SELECT 'pos_customer_ledger_entries.occurred_at', DATE_FORMAT(created_at + INTERVAL 1 HOUR, '%Y-%m'), COUNT(*),
       SUM(occurred_at <> DATE(created_at + INTERVAL 1 HOUR)),
       SUM(occurred_at = DATE(created_at) AND DATE(created_at) <> DATE(created_at + INTERVAL 1 HOUR)),
       SUM(occurred_at <> DATE(created_at + INTERVAL 1 HOUR) AND occurred_at <> DATE(created_at))
FROM pos_customer_ledger_entries GROUP BY 2
UNION ALL
SELECT 'expenses.incurred_at', DATE_FORMAT(created_at + INTERVAL 1 HOUR, '%Y-%m'), COUNT(*),
       SUM(incurred_at <> DATE(created_at + INTERVAL 1 HOUR)),
       SUM(incurred_at = DATE(created_at) AND DATE(created_at) <> DATE(created_at + INTERVAL 1 HOUR)),
       SUM(incurred_at <> DATE(created_at + INTERVAL 1 HOUR) AND incurred_at <> DATE(created_at))
FROM expenses GROUP BY 2
UNION ALL
SELECT 'financial_ledger_entries.occurred_at', DATE_FORMAT(created_at + INTERVAL 1 HOUR, '%Y-%m'), COUNT(*),
       SUM(occurred_at <> DATE(created_at + INTERVAL 1 HOUR)),
       SUM(occurred_at = DATE(created_at) AND DATE(created_at) <> DATE(created_at + INTERVAL 1 HOUR)),
       SUM(occurred_at <> DATE(created_at + INTERVAL 1 HOUR) AND occurred_at <> DATE(created_at))
FROM financial_ledger_entries GROUP BY 2
UNION ALL
SELECT 'stock_accountability_entries.occurred_at', DATE_FORMAT(created_at + INTERVAL 1 HOUR, '%Y-%m'), COUNT(*),
       SUM(occurred_at <> DATE(created_at + INTERVAL 1 HOUR)),
       SUM(occurred_at = DATE(created_at) AND DATE(created_at) <> DATE(created_at + INTERVAL 1 HOUR)),
       SUM(occurred_at <> DATE(created_at + INTERVAL 1 HOUR) AND occurred_at <> DATE(created_at))
FROM stock_accountability_entries GROUP BY 2
ORDER BY 1, 2;

-- Expenses only: dates the user evidently picked on purpose — incurred_at more
-- than one day away from the Lagos date the row was created.
SELECT DATE_FORMAT(created_at + INTERVAL 1 HOUR, '%Y-%m') AS month,
       COUNT(*) AS total_rows,
       SUM(ABS(DATEDIFF(incurred_at, DATE(created_at + INTERVAL 1 HOUR))) > 1) AS picked_more_than_1_day_away,
       SUM(DATEDIFF(incurred_at, DATE(created_at + INTERVAL 1 HOUR)) = -1) AS exactly_1_day_earlier
FROM expenses GROUP BY month ORDER BY month;

ROLLBACK;
