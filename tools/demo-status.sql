-- ClaimRev Connect — demo status report (read-only)
--
-- Shows where each test-payer patient (pid 9101-9109) stands in OpenEMR:
-- claim status, money posted, patient share, balance and which insurance
-- level is closed, then the payment sessions and the Claim Status timeline.
-- Compare against "Expected results" in tools/DEMO.md.
--
-- Changes nothing. Run it as often as you like:
--   docker exec -i <mysql-container> mariadb -uroot -p<pw> <db> < demo-status.sql

-- ---------------------------------------------------------------------------
-- 1. One line per patient
-- ---------------------------------------------------------------------------
-- oe_claim: the latest claims version's status — 1 unbilled, 2 billed,
--   3 processed (ERA posted), 7 denied. Blank = never billed this run.
-- closed: form_encounter.last_level_closed — 0 nothing closed (balance with
--   the payer), 1 primary insurance done (balance is the patient's).
-- patient_share: the coinsurance / deductible / copay memos posted from ERAs.
SELECT p.pid,
       p.lname AS scenario,
       fe.encounter,
       CASE c.status WHEN 1 THEN 'unbilled' WHEN 2 THEN 'billed' WHEN 3 THEN 'processed'
                     WHEN 6 THEN 'closed' WHEN 7 THEN 'denied' ELSE COALESCE(c.status, '-') END AS oe_claim,
       charges.amt                                  AS charges,
       COALESCE(money.paid, 0)                      AS ins_paid,
       COALESCE(money.adj, 0)                       AS adjusted,
       COALESCE(memo.pt_share, 0)                   AS patient_share,
       charges.amt - COALESCE(money.paid, 0) - COALESCE(money.adj, 0) AS balance,
       fe.last_level_closed                         AS closed,
       COALESCE(money.sessions, 0)                  AS sessions,
       COALESCE(ev.events, 0)                       AS timeline_events
FROM patient_data p
JOIN form_encounter fe ON fe.pid = p.pid
JOIN (SELECT pid, encounter, SUM(fee) AS amt FROM billing WHERE activity = 1 GROUP BY pid, encounter) charges
  ON charges.pid = fe.pid AND charges.encounter = fe.encounter
LEFT JOIN (SELECT pid, encounter, SUM(pay_amount) AS paid, SUM(adj_amount) AS adj, COUNT(DISTINCT session_id) AS sessions
             FROM ar_activity WHERE deleted IS NULL GROUP BY pid, encounter) money
  ON money.pid = fe.pid AND money.encounter = fe.encounter
LEFT JOIN (SELECT pid, encounter,
                  SUM(CAST(SUBSTRING_INDEX(memo, ': ', -1) AS DECIMAL(12,2))) AS pt_share
             FROM ar_activity
            WHERE deleted IS NULL AND memo REGEXP '^Ins[0-9] (dedbl|coins|copay|ptresp): '
            GROUP BY pid, encounter) memo
  ON memo.pid = fe.pid AND memo.encounter = fe.encounter
LEFT JOIN claims c
  ON c.patient_id = fe.pid AND c.encounter_id = fe.encounter
 AND c.version = (SELECT MAX(version) FROM claims c2 WHERE c2.patient_id = fe.pid AND c2.encounter_id = fe.encounter)
LEFT JOIN (SELECT pid, encounter, COUNT(*) AS events FROM mod_claimrev_claim_events GROUP BY pid, encounter) ev
  ON ev.pid = fe.pid AND ev.encounter = fe.encounter
WHERE p.pid BETWEEN 9101 AND 9109
ORDER BY p.pid;

-- ---------------------------------------------------------------------------
-- 2. Payment sessions. pay_total should equal lines_paid on every row.
-- ---------------------------------------------------------------------------
SELECT s.session_id, a.pid, s.check_date, s.pay_total,
       SUM(a.pay_amount) AS lines_paid,
       IF(ROUND(s.pay_total - SUM(a.pay_amount), 2) = 0, 'ok', 'MISMATCH') AS balanced,
       SUBSTRING_INDEX(MAX(IF(a.memo LIKE 'Chk#%', a.memo, NULL)), ' ', 1) AS check_no,
       s.reference
FROM ar_session s
JOIN ar_activity a ON a.session_id = s.session_id AND a.deleted IS NULL
WHERE a.pid BETWEEN 9101 AND 9109
GROUP BY s.session_id, a.pid
ORDER BY a.pid, s.session_id;

-- ---------------------------------------------------------------------------
-- 3. Claim Status timeline (module)
-- ---------------------------------------------------------------------------
SELECT pid, created_date, event_type, source, status_code, status_description, amount, detail_text
FROM mod_claimrev_claim_events
WHERE pid BETWEEN 9101 AND 9109
ORDER BY pid, id;

-- ---------------------------------------------------------------------------
-- 4. Claim versions (what Billing Manager's history shows)
-- ---------------------------------------------------------------------------
SELECT patient_id AS pid, encounter_id AS encounter, version, status, bill_process, bill_time, process_time, process_file
FROM claims
WHERE patient_id BETWEEN 9101 AND 9109
ORDER BY patient_id, version;
