-- ClaimRev Connect — demo / test dataset
--
-- Seeds a small but complete billing dataset so the module's data-driven
-- screens (Claims, Reconciliation, Claim Status, Payment Advice, the
-- analytics reports) have something real to show, and so the ClaimRev
-- round-trip can be demonstrated end to end.
--
-- WHY THIS EXISTS: a fresh OpenEMR install has patients and encounters but
-- an empty `claims` table, and Reconciliation inner-joins `claims`. With no
-- rows there it returns early and never even calls ClaimRev, so those screens
-- look broken when they are merely empty.
--
-- SAFE TO RE-RUN. Every demo row lives in reserved id ranges and is deleted
-- before being re-inserted:
--     patients / encounters   pid 9001-9008
--     insurance companies     id 201-203
-- Nothing outside those ranges is touched, so real or pre-existing demo data
-- is left alone.
--
-- USAGE (from the VM host):
--   docker exec -i <mysql-container> mysql -uroot -p<pw> <db> < demo-seed.sql
-- or inside the container:
--   mysql -uroot -p<pw> <db> < demo-seed.sql
--
-- Then run tools/demo-seed-verify.sql to confirm what landed.
--
-- SCOPE: office-visit E/M coding only (99203 / 99213 / 99214). Deliberately
-- no specialty CPT/HCPCS and no NCCI edit scenarios.

SET @now := NOW();
SET @today := CURDATE();

-- Provider and facility are looked up rather than hardcoded, so this script
-- works on any install. Falls back to the lowest id if the named ones differ.
SET @prov := (SELECT id FROM users WHERE active = 1 AND LENGTH(COALESCE(npi, 0)) > 1 ORDER BY id LIMIT 1);
SET @fac  := (SELECT id FROM facility ORDER BY id LIMIT 1);

-- ---------------------------------------------------------------------------
-- Clean out any previous run
-- ---------------------------------------------------------------------------
DELETE FROM claims          WHERE patient_id BETWEEN 9001 AND 9008;
DELETE FROM billing         WHERE pid        BETWEEN 9001 AND 9008;
DELETE FROM form_encounter  WHERE pid        BETWEEN 9001 AND 9008;
DELETE FROM insurance_data  WHERE pid        BETWEEN 9001 AND 9008;
DELETE FROM patient_data    WHERE pid        BETWEEN 9001 AND 9008;
DELETE FROM addresses       WHERE foreign_id BETWEEN 201  AND 203;
DELETE FROM insurance_companies WHERE id     BETWEEN 201  AND 203;

-- ---------------------------------------------------------------------------
-- Payers
--
-- Real CMS payer IDs so the generated 837P is realistic. UnitedHealthcare
-- (87726), Cigna (62308), Humana (61101) and Medicare already exist on a
-- stock install; these three fill out the rest of a typical Arizona mix.
-- ---------------------------------------------------------------------------
INSERT INTO insurance_companies (id, name, cms_id, ins_type_code, inactive, date_created, last_updated) VALUES
    (201, 'BCBS Arizona',            '53589', 2, 0, @now, @now),
    (202, 'Aetna',                   '60054', 2, 0, @now, @now),
    (203, 'Arizona Complete Health', '68069', 3, 0, @now, @now);

-- addresses.id is not auto-increment on OpenEMR, so allocate explicitly from
-- the current high-water mark rather than letting it default to 0 and collide.
SET @addr := (SELECT COALESCE(MAX(id), 0) FROM addresses);

INSERT INTO addresses (id, foreign_id, line1, city, state, zip, country) VALUES
    (@addr + 1, 201, 'PO Box 13466',  'Phoenix',    'AZ', '85002', 'USA'),
    (@addr + 2, 202, 'PO Box 981106', 'El Paso',    'TX', '79998', 'USA'),
    (@addr + 3, 203, 'PO Box 9010',   'Farmington', 'MO', '63640', 'USA');

-- ---------------------------------------------------------------------------
-- Patients — one per payer, so the demo can show a spread of the payer mix
-- ---------------------------------------------------------------------------
INSERT INTO patient_data (pid, pubpid, fname, lname, DOB, sex, street, city, state, postal_code, phone_home, date) VALUES
    (9001, 'DEMO-9001', 'Marcus',  'Ellery',    '1952-03-14', 'Male',   '418 W Camelback Rd', 'Phoenix',   'AZ', '85013', '602-555-0141', @now),
    (9002, 'DEMO-9002', 'Yolanda', 'Prieto',    '1978-11-02', 'Female', '2210 E Baseline Rd', 'Tempe',     'AZ', '85283', '480-555-0192', @now),
    (9003, 'DEMO-9003', 'Dennis',  'Kwan',      '1965-07-23', 'Male',   '77 N Stone Ave',     'Tucson',    'AZ', '85701', '520-555-0177', @now),
    (9004, 'DEMO-9004', 'Priscilla', 'Nakamura','1990-01-30', 'Female', '905 E Bell Rd',      'Phoenix',   'AZ', '85022', '602-555-0165', @now),
    (9005, 'DEMO-9005', 'Terrence', 'Boyd',     '1948-09-09', 'Male',   '1600 W Chandler Bl', 'Chandler',  'AZ', '85224', '480-555-0118', @now),
    (9006, 'DEMO-9006', 'Antoinette', 'Ruiz',   '1983-05-17', 'Female', '340 S Val Vista Dr', 'Mesa',      'AZ', '85204', '480-555-0133', @now),
    (9007, 'DEMO-9007', 'Grant',   'Whitfield', '1971-12-05', 'Male',   '5250 N Oracle Rd',   'Tucson',    'AZ', '85704', '520-555-0159', @now),
    (9008, 'DEMO-9008', 'Camille', 'Osei',      '1995-08-21', 'Female', '12 E Southern Ave',  'Mesa',      'AZ', '85210', '480-555-0186', @now);

-- Insurance. subscriber_relationship 'self' throughout to keep the demo simple.
INSERT INTO insurance_data
    (type, provider, plan_name, policy_number, group_number,
     subscriber_lname, subscriber_fname, subscriber_relationship, subscriber_DOB,
     subscriber_street, subscriber_postal_code, subscriber_city, subscriber_state, subscriber_country,
     subscriber_sex, accept_assignment, policy_type, date, pid)
SELECT 'primary', c.provider, c.plan_name, c.policy_number, c.group_number,
       p.lname, p.fname, 'self', p.DOB,
       p.street, p.postal_code, p.city, p.state, 'USA',
       p.sex, 'TRUE', '', DATE_SUB(@today, INTERVAL 400 DAY), p.pid
FROM (
    SELECT 9001 AS pid, 104 AS provider, 'Medicare Part B'        AS plan_name, '1EG4TE5MK72' AS policy_number, ''        AS group_number UNION ALL
    SELECT 9002,        203,             'AHCCCS Complete Care',                'AZ884120019',                 'AHC1002'                  UNION ALL
    SELECT 9003,        103,             'UHC Choice Plus',                     '941220887',                   '0R7431'                   UNION ALL
    SELECT 9004,        201,             'BCBS AZ PPO',                         'XZA904112330',                'AZ00417'                  UNION ALL
    SELECT 9005,        106,             'Humana Gold Plus',                    'H1036221904',                 'HUM7741'                  UNION ALL
    SELECT 9006,        202,             'Aetna Choice POS II',                 'W219884301',                  'AET0925'                  UNION ALL
    SELECT 9007,        105,             'Cigna Open Access',                   'U8842019773',                 'CIG3310'                  UNION ALL
    SELECT 9008,        103,             'UHC Navigate',                        '941330924',                   '0R7431'
) AS c
JOIN patient_data p ON p.pid = c.pid;

-- ---------------------------------------------------------------------------
-- TIER A — unbilled encounters, ready to bill LIVE during the demo
--
-- These deliberately have no `claims` row. The demo generates the claim in
-- Billing Manager, which creates it, produces the 837P, and hands it to the
-- module to send — which is exactly the "create a claim / send a claim" story.
-- ---------------------------------------------------------------------------
INSERT INTO form_encounter (date, reason, facility, facility_id, pid, encounter, provider_id, pc_catid, last_level_billed, last_level_closed, pos_code)
SELECT DATE_SUB(@today, INTERVAL d.ago DAY), d.reason, f.name, @fac, d.pid, d.enc, @prov, 5, 0, 0, '11'
FROM (
    SELECT 9001 AS pid, 990101 AS enc, 6  AS ago, 'Follow-up visit'   AS reason UNION ALL
    SELECT 9002,        990102,        4,          'Office visit'                UNION ALL
    SELECT 9003,        990103,        3,          'New patient consult'
) AS d
CROSS JOIN (SELECT name FROM facility WHERE id = @fac) AS f;

INSERT INTO billing (date, code_type, code, pid, provider_id, user, groupname, authorized, encounter, code_text, billed, activity, units, fee, justify)
SELECT DATE_SUB(@today, INTERVAL b.ago DAY), 'CPT4', b.code, b.pid, @prov, @prov, 'Default', 1, b.enc, b.code_text, 0, 1, 1, b.fee, b.justify
FROM (
    SELECT 9001 AS pid, 990101 AS enc, 6 AS ago, '99213' AS code, 'Office o/p est mod 20-29 min' AS code_text, 128.00 AS fee, 'E11.9:'  AS justify UNION ALL
    SELECT 9002,        990102,        4,        '99214',         'Office o/p est mod 30-39 min',        186.00,        'M54.50:'          UNION ALL
    SELECT 9003,        990103,        3,        '99203',         'Office o/p new mod 30-44 min',        215.00,        'R51.9:'
) AS b;

-- ---------------------------------------------------------------------------
-- TIER B — already billed, with claims rows in a spread of statuses
--
-- Gives Claims, Reconciliation, Claim Status, aging and denial analytics
-- something to render immediately, without waiting on a live round trip.
--
-- claims.status values ReconciliationService filters on:
--   2 = billed        (statusFilter 'billed'  -> IN (2,6))
--   6 = billed/closed (same filter)
--   7 = denied        (statusFilter 'denied')
-- ---------------------------------------------------------------------------
INSERT INTO form_encounter (date, reason, facility, facility_id, pid, encounter, provider_id, pc_catid, last_level_billed, last_level_closed, pos_code)
SELECT DATE_SUB(@today, INTERVAL d.ago DAY), d.reason, f.name, @fac, d.pid, d.enc, @prov, 5, 1, 0, '11'
FROM (
    SELECT 9004 AS pid, 990104 AS enc, 38 AS ago, 'Office visit'    AS reason UNION ALL
    SELECT 9005,        990105,        45,        'Follow-up visit'            UNION ALL
    SELECT 9006,        990106,        52,        'Office visit'               UNION ALL
    SELECT 9007,        990107,        67,        'Follow-up visit'            UNION ALL
    SELECT 9008,        990108,        81,        'Office visit'
) AS d
CROSS JOIN (SELECT name FROM facility WHERE id = @fac) AS f;

INSERT INTO billing (date, code_type, code, pid, provider_id, user, groupname, authorized, encounter, code_text, billed, activity, units, fee, justify, bill_date, payer_id)
SELECT DATE_SUB(@today, INTERVAL b.ago DAY), 'CPT4', b.code, b.pid, @prov, @prov, 'Default', 1, b.enc, b.code_text, 1, 1, 1, b.fee, b.justify,
       DATE_SUB(@today, INTERVAL (b.ago - 2) DAY), b.payer
FROM (
    SELECT 9004 AS pid, 990104 AS enc, 38 AS ago, '99214' AS code, 'Office o/p est mod 30-39 min' AS code_text, 186.00 AS fee, 'J06.9:'  AS justify, 201 AS payer UNION ALL
    SELECT 9005,        990105,        45,        '99213',         'Office o/p est mod 20-29 min',        128.00,        'I10:',              106 UNION ALL
    SELECT 9006,        990106,        52,        '99214',         'Office o/p est mod 30-39 min',        186.00,        'E11.9:',            202 UNION ALL
    SELECT 9007,        990107,        67,        '99213',         'Office o/p est mod 20-29 min',        128.00,        'M54.50:',           105 UNION ALL
    SELECT 9008,        990108,        81,        '99203',         'Office o/p new mod 30-44 min',        215.00,        'R10.9:',            103
) AS b;

INSERT INTO claims (patient_id, encounter_id, version, payer_id, status, payer_type, bill_process, bill_time, process_time, process_file, target, x12_partner_id, submitted_claim)
SELECT c.pid, c.enc, 1, c.payer, c.status, 1, 2,
       DATE_SUB(@now, INTERVAL c.ago DAY),
       DATE_SUB(@now, INTERVAL (c.ago - 1) DAY),
       CONCAT('batch_', DATE_FORMAT(DATE_SUB(@today, INTERVAL c.ago DAY), '%Y%m%d'), '.txt'),
       'T2', 1, ''
FROM (
    SELECT 9004 AS pid, 990104 AS enc, 36 AS ago, 201 AS payer, 2 AS status UNION ALL  -- billed, awaiting payer
    SELECT 9005,        990105,        43,        106,          6            UNION ALL  -- billed / closed
    SELECT 9006,        990106,        50,        202,          7            UNION ALL  -- denied
    SELECT 9007,        990107,        65,        105,          2            UNION ALL  -- billed, awaiting payer
    SELECT 9008,        990108,        79,        103,          6                       -- billed / closed
) AS c;
