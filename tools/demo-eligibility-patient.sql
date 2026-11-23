-- ClaimRev Connect — eligibility / Revenue Tools demo patient
--
-- Fred Flintstone, pid 9110, covered by the simulated test payer (insurer 210,
-- payer ID TEST01) with subscriber ID PCP01, for running eligibility and
-- Revenue Tools (coverage discovery, demographics, MBI finder) from OpenEMR.
--
-- Run tools/demo-reset.sql at least once first: it creates insurer 210.
-- demo-reset.sql does not touch pid 9110, so Fred and his eligibility history
-- survive resets. Re-running this file recreates him and clears that history.
--
-- USAGE
--   docker exec -i <mysql-container> mariadb -uroot -p<pw> <db> < demo-eligibility-patient.sql

SET @now := NOW();

DELETE FROM mod_claimrev_eligibility WHERE pid = 9110;
DELETE FROM insurance_data WHERE pid = 9110;
DELETE FROM patient_data   WHERE pid = 9110;

INSERT INTO patient_data (pid, pubpid, fname, lname, DOB, sex, street, city, state, postal_code, phone_home, date) VALUES
    (9110, 'TP-9110', 'Fred', 'Flintstone', '1969-05-27', 'Male', '301 Cobblestone Way', 'Tulsa', 'OK', '74103', '918-555-0110', @now);

INSERT INTO insurance_data
    (type, provider, plan_name, policy_number, group_number,
     subscriber_lname, subscriber_fname, subscriber_relationship, subscriber_DOB,
     subscriber_street, subscriber_postal_code, subscriber_city, subscriber_state, subscriber_country,
     subscriber_sex, accept_assignment, policy_type, date, pid)
SELECT 'primary', 210, 'Test Payer PPO', 'PCP01', 'TP1321',
       p.lname, p.fname, 'self', p.DOB,
       p.street, p.postal_code, p.city, p.state, 'USA',
       p.sex, 'TRUE', '', DATE_SUB(CURDATE(), INTERVAL 365 DAY), p.pid
FROM patient_data p WHERE p.pid = 9110;

SELECT p.pid, CONCAT(p.fname, ' ', p.lname) AS patient, p.DOB, p.sex,
       i.policy_number AS subscriber_id, ic.name AS payer, ic.cms_id
FROM patient_data p
JOIN insurance_data i ON i.pid = p.pid AND i.type = 'primary'
JOIN insurance_companies ic ON ic.id = i.provider
WHERE p.pid = 9110;
