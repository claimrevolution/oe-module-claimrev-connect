-- Verify what tools/demo-seed.sql actually created.
--
-- Run immediately after seeding. Every count should be non-zero, and the
-- reconciliation-eligible count is the one that matters most: it is what
-- ReconciliationService's default 'billed' filter (claims.status IN (2,6))
-- will actually find. If that reads 0, the Reconciliation tab will still
-- look empty no matter what date range is chosen.

SELECT 'patients'                AS what, COUNT(*) AS n FROM patient_data   WHERE pid        BETWEEN 9001 AND 9008
UNION ALL
SELECT 'insurance rows',               COUNT(*)        FROM insurance_data  WHERE pid        BETWEEN 9001 AND 9008
UNION ALL
SELECT 'payers added',                 COUNT(*)        FROM insurance_companies WHERE id     BETWEEN 201  AND 203
UNION ALL
SELECT 'encounters (all)',             COUNT(*)        FROM form_encounter  WHERE pid        BETWEEN 9001 AND 9008
UNION ALL
SELECT 'charges unbilled (tier A)',    COUNT(*)        FROM billing         WHERE pid        BETWEEN 9001 AND 9008 AND billed = 0
UNION ALL
SELECT 'charges billed (tier B)',      COUNT(*)        FROM billing         WHERE pid        BETWEEN 9001 AND 9008 AND billed = 1
UNION ALL
SELECT 'claims rows',                  COUNT(*)        FROM claims          WHERE patient_id BETWEEN 9001 AND 9008
UNION ALL
SELECT 'reconciliation-eligible',      COUNT(*)        FROM claims          WHERE patient_id BETWEEN 9001 AND 9008 AND status IN (2, 6)
UNION ALL
SELECT 'denied (denial analytics)',    COUNT(*)        FROM claims          WHERE patient_id BETWEEN 9001 AND 9008 AND status = 7;

-- The exact date window to type into the Reconciliation / Claims filters.
SELECT MIN(DATE(date)) AS search_from, MAX(DATE(date)) AS search_to
FROM form_encounter WHERE pid BETWEEN 9001 AND 9008;

-- The PCNs the module will send to ClaimRev for these encounters.
SELECT CONCAT(patient_id, '-', encounter_id) AS pcn, status
FROM claims WHERE patient_id BETWEEN 9001 AND 9008 ORDER BY patient_id;
