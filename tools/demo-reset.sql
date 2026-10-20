-- ClaimRev Connect — demo reset for the simulated test payer
--
-- Puts an OpenEMR install into a clean "ready to bill" state for a full
-- claim -> 277CA -> 835 -> ERA posting demo against ClaimRev's simulated
-- test payer. Safe to run as often as you like. See tools/DEMO.md for the
-- demo itself.
--
-- WHAT IT DOES
--   1. Removes everything the previous run left for the nine test patients:
--      payment sessions and lines, claim versions, billing, encounters and
--      their forms rows, and the module's claim mirror and timeline.
--   2. Recreates the test payer (insurer 210, payer ID TEST01), the nine
--      patients and one UNBILLED encounter each, with NEW encounter numbers.
--   3. Checks the billing setup a claim needs to get past ClaimRev's edits
--      and prints a readiness report. It never changes facility or provider
--      data; fix anything it flags in the UI.
--
-- WHY NEW ENCOUNTER NUMBERS EVERY RUN
--   ClaimRev cannot be reset. It keeps the old claims and their 835s under
--   the old patient control numbers ("pid-encounter"). Reusing an encounter
--   number would let an old ERA match a fresh encounter and post last run's
--   money. New numbers leave old ERAs matching nothing.
--
--   Format YDDDHHMMp (9 digits, fits the int(11) encounter columns in
--   billing, claims and ar_activity): last digit of the year, day of year,
--   hour, minute, patient slot 1-9. 629314011 = 2026, day 293, 14:01, slot 1.
--   Two resets in the same minute would reuse numbers, so wait a minute.
--
-- SCENARIOS (the subscriber ID, 2010BA NM109, picks the simulator's answer)
--     pid   patient            subscriber ID    277CA            835 (first / next)
--     9101  Alice Accept       TESTACCEPT       accepted         paid / -
--     9102  Ray Rejecta        TESTREJECTA7     rejected A7      - / -
--     9103  Dana Denial        TESTDENY50       accepted         denied CO-50 / -
--     9104  Reese Reversal     TESTREVERSAL     accepted         paid / reversal
--     9105  Corey Correction   TESTCORRECTION   accepted         paid / reversal + repay 90%
--     9106  Sloane Split       TESTSPLIT        accepted         first half of lines / the rest
--     9107  Penny Pend         TESTPEND         pended           - / -
--     9108  Norris Noresponse  TESTNORESPONSE   nothing          - / -
--     9109  Dexter Default     ZZ9109DEFAULT    accepted         paid / -
--
-- RESERVED RANGES (nothing outside them is touched)
--     patients pid 9101-9109, insurer id 210
--     Does not overlap tools/demo-seed.sql (pid 9001-9008, insurers 201-203).
--
-- USAGE
--   docker exec -i <mysql-container> mariadb -uroot -p<pw> <db> < demo-reset.sql
--   On the openemr-test VM, oe8:  sudo docker exec -i mysql803 mariadb -uroot -proot openemr803 < demo-reset.sql

SET @now   := NOW();
SET @today := CURDATE();
SET @dos   := DATE_SUB(@today, INTERVAL 3 DAY);

-- Encounter number base for this run: YDDDHHMM, times 10 for the slot.
SET @encbase := CAST(CONCAT(RIGHT(YEAR(@now), 1), LPAD(DAYOFYEAR(@now), 3, '0'), DATE_FORMAT(@now, '%H%i')) AS UNSIGNED) * 10;

SET @prov := (SELECT id FROM users WHERE active = 1 AND LENGTH(COALESCE(npi, 0)) > 1 ORDER BY id LIMIT 1);
SET @fac  := (SELECT id FROM facility WHERE billing_location = 1 ORDER BY id LIMIT 1);
SET @fac  := COALESCE(@fac, (SELECT id FROM facility ORDER BY id LIMIT 1));
SET @x12  := (SELECT id FROM x12_partners ORDER BY id LIMIT 1);

-- ---------------------------------------------------------------------------
-- 1. Clean out the previous run
-- ---------------------------------------------------------------------------

-- Payment sessions. ERA sessions are stored with patient_id = 0, so they are
-- found through their lines. A session is only removed when every line in it
-- belongs to a test patient, so a real check that happens to include a test
-- patient is never deleted.
DELETE s FROM ar_session s
 WHERE s.session_id IN (SELECT a.session_id FROM ar_activity a WHERE a.pid BETWEEN 9101 AND 9109)
   AND NOT EXISTS (SELECT 1 FROM ar_activity o WHERE o.session_id = s.session_id AND o.pid NOT BETWEEN 9101 AND 9109);
DELETE FROM ar_session      WHERE patient_id BETWEEN 9101 AND 9109;
DELETE FROM ar_activity     WHERE pid        BETWEEN 9101 AND 9109;
DELETE FROM claims          WHERE patient_id BETWEEN 9101 AND 9109;
DELETE FROM billing         WHERE pid        BETWEEN 9101 AND 9109;
DELETE FROM forms           WHERE pid        BETWEEN 9101 AND 9109;
DELETE FROM form_encounter  WHERE pid        BETWEEN 9101 AND 9109;
DELETE FROM insurance_data  WHERE pid        BETWEEN 9101 AND 9109;
DELETE FROM patient_data    WHERE pid        BETWEEN 9101 AND 9109;
DELETE FROM mod_claimrev_claim_events WHERE pid BETWEEN 9101 AND 9109;
DELETE FROM mod_claimrev_claims       WHERE pid BETWEEN 9101 AND 9109;
DELETE FROM addresses       WHERE foreign_id = 210;
DELETE FROM insurance_companies WHERE id     = 210;

-- ---------------------------------------------------------------------------
-- 2. The test payer. cms_id TEST01 is what ClaimRev routes to the simulator.
-- ---------------------------------------------------------------------------
INSERT INTO insurance_companies (id, name, cms_id, ins_type_code, x12_default_partner_id, inactive, date_created, last_updated) VALUES
    (210, 'ClaimRev Simulated Test Payer', 'TEST01', 2, @x12, 0, @now, @now);

-- addresses.id is not auto-increment on OpenEMR; allocate explicitly.
SET @addr := (SELECT COALESCE(MAX(id), 0) FROM addresses);
INSERT INTO addresses (id, foreign_id, line1, city, state, zip, country) VALUES
    (@addr + 1, 210, 'PO Box 1321', 'Tulsa', 'OK', '74101', 'USA');

-- ---------------------------------------------------------------------------
-- 3. Patients — one per scenario; the last name names the scenario.
-- ---------------------------------------------------------------------------
INSERT INTO patient_data (pid, pubpid, fname, lname, DOB, sex, street, city, state, postal_code, phone_home, date) VALUES
    (9101, 'TP-9101', 'Alice',   'Accept',     '1961-04-12', 'Female', '101 S Main St',     'Tulsa',        'OK', '74103', '918-555-0101', @now),
    (9102, 'TP-9102', 'Ray',     'Rejecta',    '1974-08-30', 'Male',   '220 E 5th St',      'Tulsa',        'OK', '74103', '918-555-0102', @now),
    (9103, 'TP-9103', 'Dana',    'Denial',     '1988-02-14', 'Female', '3310 S Peoria Ave', 'Tulsa',        'OK', '74105', '918-555-0103', @now),
    (9104, 'TP-9104', 'Reese',   'Reversal',   '1955-11-03', 'Male',   '1400 N Elm Pl',     'Broken Arrow', 'OK', '74012', '918-555-0104', @now),
    (9105, 'TP-9105', 'Corey',   'Correction', '1969-06-21', 'Male',   '812 W Kenosha St',  'Broken Arrow', 'OK', '74012', '918-555-0105', @now),
    (9106, 'TP-9106', 'Sloane',  'Split',      '1992-09-09', 'Female', '45 E 71st St',      'Tulsa',        'OK', '74136', '918-555-0106', @now),
    (9107, 'TP-9107', 'Penny',   'Pend',       '1980-01-25', 'Female', '6020 S Yale Ave',   'Tulsa',        'OK', '74135', '918-555-0107', @now),
    (9108, 'TP-9108', 'Norris',  'Noresponse', '1946-12-17', 'Male',   '2700 E 21st St',    'Tulsa',        'OK', '74114', '918-555-0108', @now),
    (9109, 'TP-9109', 'Dexter',  'Default',    '1977-03-08', 'Male',   '9100 S Memorial Dr','Tulsa',        'OK', '74133', '918-555-0109', @now);

-- policy_number becomes the subscriber ID (NM109) — it selects the scenario.
INSERT INTO insurance_data
    (type, provider, plan_name, policy_number, group_number,
     subscriber_lname, subscriber_fname, subscriber_relationship, subscriber_DOB,
     subscriber_street, subscriber_postal_code, subscriber_city, subscriber_state, subscriber_country,
     subscriber_sex, accept_assignment, policy_type, date, pid)
SELECT 'primary', 210, 'Test Payer PPO', c.policy_number, 'TP1321',
       p.lname, p.fname, 'self', p.DOB,
       p.street, p.postal_code, p.city, p.state, 'USA',
       p.sex, 'TRUE', '', DATE_SUB(@today, INTERVAL 365 DAY), p.pid
FROM (
    SELECT 9101 AS pid, 'TESTACCEPT'     AS policy_number UNION ALL
    SELECT 9102,        'TESTREJECTA7'                    UNION ALL
    SELECT 9103,        'TESTDENY50'                      UNION ALL
    SELECT 9104,        'TESTREVERSAL'                    UNION ALL
    SELECT 9105,        'TESTCORRECTION'                  UNION ALL
    SELECT 9106,        'TESTSPLIT'                       UNION ALL
    SELECT 9107,        'TESTPEND'                        UNION ALL
    SELECT 9108,        'TESTNORESPONSE'                  UNION ALL
    SELECT 9109,        'ZZ9109DEFAULT'
) AS c
JOIN patient_data p ON p.pid = c.pid;

-- ---------------------------------------------------------------------------
-- 4. Unbilled encounters with this run's encounter numbers
-- ---------------------------------------------------------------------------
INSERT INTO form_encounter (date, reason, facility, facility_id, billing_facility, pid, encounter, provider_id, pc_catid, last_level_billed, last_level_closed, pos_code)
SELECT @dos, 'Office visit', f.name, @fac, @fac, p.pid, @encbase + (p.pid - 9100), @prov, 5, 0, 0, '11'
FROM patient_data p
CROSS JOIN (SELECT name FROM facility WHERE id = @fac) AS f
WHERE p.pid BETWEEN 9101 AND 9109;

-- The forms row is what lists an encounter in the patient's chart.
INSERT INTO forms (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir)
SELECT @dos, fe.encounter, 'New Patient Encounter', fe.id, fe.pid, (SELECT username FROM users WHERE id = @prov), 'Default', 1, 0, 'newpatient'
FROM form_encounter fe WHERE fe.pid BETWEEN 9101 AND 9109;

-- Service lines: two per encounter; TESTSPLIT gets four so the split 835 has
-- a meaningful first half and second half.
INSERT INTO billing (date, code_type, code, pid, provider_id, user, groupname, authorized, encounter, code_text, billed, activity, units, fee, justify)
SELECT @dos, 'CPT4', l.code, fe.pid, @prov, @prov, 'Default', 1, fe.encounter, l.code_text, 0, 1, 1, l.fee, 'ICD10|I10:'
FROM form_encounter fe
JOIN (
    SELECT 0 AS split_only, '99214' AS code, 'Office o/p est mod 30-39 min' AS code_text, 186.00 AS fee UNION ALL
    SELECT 0,               '36415',         'Routine venipuncture',                      15.00        UNION ALL
    SELECT 1,               '80053',         'Comprehensive metabolic panel',             42.00        UNION ALL
    SELECT 1,               '85025',         'Complete CBC w/auto diff WBC',              28.00
) AS l ON l.split_only = 0 OR fe.pid = 9106
WHERE fe.pid BETWEEN 9101 AND 9109;

INSERT INTO billing (date, code_type, code, pid, provider_id, user, groupname, authorized, encounter, code_text, billed, activity, units, fee, justify)
SELECT @dos, 'ICD10', 'I10', pid, @prov, @prov, 'Default', 1, encounter, 'Essential (primary) hypertension', 0, 1, 1, 0.00, ''
FROM form_encounter WHERE pid BETWEEN 9101 AND 9109;

-- ---------------------------------------------------------------------------
-- 5. Readiness report
-- ---------------------------------------------------------------------------

-- NPI check digit: Luhn over the 10 digits with the 80840 prefix (worth 24).
-- d1, d3, d5, d7, d9 are doubled; a doubled digit over 9 has 9 taken off.
SELECT 'billing facility' AS item,
       CONCAT(f.name, ' (id ', f.id, ')') AS value,
       CONCAT_WS('; ',
         IF(f.facility_npi NOT REGEXP '^[0-9]{10}$', 'NPI is not 10 digits', NULL),
         IF(f.facility_npi REGEXP '^[0-9]{10}$' AND MOD(
             24
             + (SUBSTRING(f.facility_npi,1,1)*2 - 9*(SUBSTRING(f.facility_npi,1,1) > 4))
             +  SUBSTRING(f.facility_npi,2,1)
             + (SUBSTRING(f.facility_npi,3,1)*2 - 9*(SUBSTRING(f.facility_npi,3,1) > 4))
             +  SUBSTRING(f.facility_npi,4,1)
             + (SUBSTRING(f.facility_npi,5,1)*2 - 9*(SUBSTRING(f.facility_npi,5,1) > 4))
             +  SUBSTRING(f.facility_npi,6,1)
             + (SUBSTRING(f.facility_npi,7,1)*2 - 9*(SUBSTRING(f.facility_npi,7,1) > 4))
             +  SUBSTRING(f.facility_npi,8,1)
             + (SUBSTRING(f.facility_npi,9,1)*2 - 9*(SUBSTRING(f.facility_npi,9,1) > 4))
             +  SUBSTRING(f.facility_npi,10,1), 10) <> 0, 'NPI fails the check digit (2010AA NM109 reject)', NULL),
         IF(COALESCE(TRIM(f.attn), '') = '', 'Attn is empty (2010AA PER contact name reject)', NULL),
         IF(LENGTH(REPLACE(f.postal_code, '-', '')) <> 9, 'Zip is not 9 digits (2010AA N4 reject)', NULL),
         IF(COALESCE(TRIM(f.federal_ein), '') = '', 'Tax ID is empty', NULL)
       ) AS problems
FROM facility f WHERE f.id = @fac
UNION ALL
SELECT 'rendering provider', CONCAT(u.fname, ' ', u.lname, ' (', u.username, ')'),
       IF(u.npi NOT REGEXP '^[0-9]{10}$' OR MOD(
             24
             + (SUBSTRING(u.npi,1,1)*2 - 9*(SUBSTRING(u.npi,1,1) > 4)) +  SUBSTRING(u.npi,2,1)
             + (SUBSTRING(u.npi,3,1)*2 - 9*(SUBSTRING(u.npi,3,1) > 4)) +  SUBSTRING(u.npi,4,1)
             + (SUBSTRING(u.npi,5,1)*2 - 9*(SUBSTRING(u.npi,5,1) > 4)) +  SUBSTRING(u.npi,6,1)
             + (SUBSTRING(u.npi,7,1)*2 - 9*(SUBSTRING(u.npi,7,1) > 4)) +  SUBSTRING(u.npi,8,1)
             + (SUBSTRING(u.npi,9,1)*2 - 9*(SUBSTRING(u.npi,9,1) > 4)) +  SUBSTRING(u.npi,10,1), 10) <> 0,
          'NPI missing or fails the check digit', NULL)
FROM users u WHERE u.id = @prov
UNION ALL
SELECT 'x12 partner', CONCAT(x.name, ' (sender ', x.x12_sender_id, ')'),
       IF(x.x12_sender_id <> 'DEMO1', 'sender ID is not DEMO1 — claims will not reach the test account', NULL)
FROM x12_partners x WHERE x.id = @x12
UNION ALL
SELECT 'other TEST01 payers', GROUP_CONCAT(CONCAT(name, ' (id ', id, ')') SEPARATOR ', '),
       'these also route to the simulator — fine for testing, just be aware'
FROM insurance_companies WHERE cms_id = 'TEST01' AND id <> 210
HAVING COUNT(*) > 0;

SELECT p.pid, CONCAT(p.fname, ' ', p.lname) AS patient, i.policy_number AS subscriber_id,
       fe.encounter, CONCAT(p.pid, '-', fe.encounter) AS pcn, DATE(fe.date) AS dos,
       (SELECT COUNT(*) FROM billing b WHERE b.encounter = fe.encounter AND b.pid = fe.pid AND b.code_type = 'CPT4') AS service_lines,
       (SELECT SUM(fee) FROM billing b WHERE b.encounter = fe.encounter AND b.pid = fe.pid) AS charges
FROM patient_data p
JOIN insurance_data i ON i.pid = p.pid AND i.type = 'primary'
JOIN form_encounter fe ON fe.pid = p.pid
WHERE p.pid BETWEEN 9101 AND 9109
ORDER BY p.pid;
