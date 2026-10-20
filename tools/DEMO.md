# Claim-to-payment demo with ClaimRev's simulated test payer

A full round trip, watchable from OpenEMR: bill a claim, ClaimRev sends it to a
simulated payer, the payer answers with a 277CA and 835s on demand, and the ERAs
post back into OpenEMR. Nine test patients each trigger a different payer
response.

Everything runs in **ClaimRev production** under the **DEMO1** test account.
Claims to payer ID **TEST01** from DEMO1 go to the simulator, never to a real
payer.

## Files

| File | What it does |
|---|---|
| `demo-reset.sql` | Clears the last run and creates nine fresh, unbilled encounters. Prints a readiness report. Safe to run any time. |
| `demo-status.sql` | Read-only report: claim status, money, balance and timeline per patient. Run after any step. |

On the test VM (oe8 is `mysql803` / `openemr803`):

```
gcloud compute scp tools/demo-reset.sql tools/demo-status.sql openemr-test:/tmp/ --zone us-central1-a --project claimrev-development --tunnel-through-iap
gcloud compute ssh openemr-test --zone us-central1-a --project claimrev-development --tunnel-through-iap --command "sudo docker exec -i mysql803 mariadb -uroot -proot openemr803 < /tmp/demo-reset.sql"
gcloud compute ssh openemr-test --zone us-central1-a --project claimrev-development --tunnel-through-iap --command "sudo docker exec -i mysql803 mariadb -uroot -proot openemr803 < /tmp/demo-status.sql"
```

## Prep (before the audience arrives)

1. Run `demo-reset.sql`. In the readiness report, the **problems** column must be
   empty for the billing facility and the rendering provider, and the X12 partner
   must send as DEMO1. A facility problem means ClaimRev will reject every claim
   at loop 2010AA; fix it under Administration → Clinic → Facilities.
2. Note the encounter numbers it printed. Each reset makes new ones
   (`YDDDHHMMp`: year digit, day of year, hour, minute, patient slot), so old
   ClaimRev claims and ERAs can't match the new encounters.
3. Module settings: ClaimRev Environment **P**, credentials for the **DEMO1**
   account, auto-send on.
4. During the demo, **filter the Claims and Payment Advice tabs by today's date**.
   ClaimRev keeps every past run's claims and ERAs; they can't post to the new
   encounters (they show "encounter not found"), but they clutter the lists.

## Running the demo

1. **Bill.** Fees → Billing Manager, select the TP- patients, send to ClaimRev.
2. **Wait for ClaimRev.** It batches to TEST01 on its normal schedule. A claim is
   ready for a payer response at **Merged To Payer** or **Sent To Payer** (Claims tab).
3. **Send the 277CA.** ClaimRev portal → Test Payer → Claims → select → Send payer 277CA
   (or `POST /api/PayerSimulator/v1/SendPayer277Ca`). Ray Rejecta now shows the
   payer's errors on the Claims tab, with the code `A7-562-85`.
4. **Send the 835s.** Portal or `POST /api/PayerSimulator/v1/SendPayer835`.
5. **Post.** Payment Advice tab → search → review → **Post All on This Page**, or tick
   rows and **Post Selected**. Reversals and pended claims ask for approval one at a time.
6. **Send the 835 again** for Reversal, Correction and Split to get their follow-ups,
   then post again.
7. **Show it landed.** Run `demo-status.sql`, or in OpenEMR: Billing Manager (claim
   status and history), Fees → Posting (payments), the module's Claim Status tab
   (timeline).

## Expected results after the whole run

Charges are 99214 $186 + 36415 $15 = $201 (Split adds 80053 $42 and 85025 $28 = $271).
The simulator allows 80% of the charge, pays 80% of the allowed amount, adjusts the
rest of the charge as CO-45, and makes 20% of the allowed amount the patient's
coinsurance (PR-2).

| pid | Scenario | OE claim | Paid | Adjusted | Patient share | Balance | Closed |
|---|---|---|---|---|---|---|---|
| 9101 | Accept | processed | 128.64 | 40.20 | 32.16 | 32.16 | 1 |
| 9102 | Reject A7 | billed (denied after Sync on the Claims tab) | 0 | 0 | 0 | 201.00 | 0 |
| 9103 | Deny CO-50 | denied | 0 | 0 | 0 | 201.00 | 0 |
| 9104 | Reversal | billed | 0 (paid, then reversed) | 0 | 0 | 201.00 | **0** |
| 9105 | Correction | processed | 144.72 | 20.10 | 36.18 | 36.18 | 1 |
| 9106 | Split | processed | 173.44 | 54.20 | 43.36 | 43.36 | 1 |
| 9107 | Pend | billed | 0 | 0 | 0 | 201.00 | 0 |
| 9108 | No response | billed | 0 | 0 | 0 | 201.00 | 0 |
| 9109 | Default | processed | 128.64 | 40.20 | 32.16 | 32.16 | 1 |

What to check:
- **Closed = 1 means the balance is the patient's.** Reversal must end at 0, or the
  patient would be billed the whole $201.
- **Payment sessions:** every row in `demo-status.sql` section 2 must say `ok`, i.e.
  each session's total equals what it paid. A reversal's session total is negative.
- **Denial** is recorded once: one denied claim version and one timeline event,
  however many times Post All runs. Its row shows as posted.
- **Correction** posts two 835 entries (reversal and repayment); either order ends
  at the same numbers.
- **Split** after only the first 835: the first half of the lines is paid and the
  encounter is already closed at 1, so the unpaid lines briefly look like patient
  balance until the second 835 posts. Worth mentioning if the audience asks.

## Known rough edges (as of 2.2.1)

- The payment session's check number field holds the module's reference
  (`ePay - ClaimRev-<id>`); the real check number is in each line's memo.
- OpenEMR's own claim status does not change on a 277CA rejection until someone
  presses Sync on the Claims tab. An automatic sync is designed but not built
  (`docs/superpowers/specs/2026-10-20-277-claim-status-sync-design.md`).
