# Automatic claim status sync (277CA / ClaimRev rejections) — DRAFT

**Status:** draft, paused 2026-10-20. Parts 1–2 agreed in discussion. **Open decision: how the
module finds out what changed (Option A vs B below).** Error handling, duplicate 277CAs and
testing were not yet designed.

## Problem

OpenEMR never learns what happened to a claim after it is sent.

- Core OpenEMR only stores and displays 277 files (Fees → EDI History, `library/edihistory/edih_277_html.php`).
  Nothing reads a 277 to change a claim's status.
- The module downloads 999/277 files into `documents/edi/history/f277/` (`ReportDownload::saveWaitingFiles`)
  so EDI History can show them, and parses nothing.
- The Claim Status timeline defines `SOURCE_PAYER_277`, `EVENT_ACCEPTED` and `EVENT_SUBMITTED`
  (`ClaimTrackingService`), but no code ever records them.
- So a claim ClaimRev or the payer rejected stays **billed** in OpenEMR until someone presses the
  manual "Sync rejected status to OpenEMR" button on the Claims tab.

## Decisions made

| Question | Decision |
|---|---|
| What happens on a rejection | **Mark the claim denied** in OpenEMR (claims status 7, new claim version — what the sync button does today) and write a rejected event with the reason to the Claim Status timeline. A person still decides when to requeue. Not auto-requeued. |
| Which rejections | **Both** ClaimRev's own edit rejections (status Error / 10 / 16 / 17) and the payer's 277CA (`payerAcceptanceStatusId` 3). |
| Where the data comes from | **ClaimRev's API, not the 277 files.** ClaimRev has already parsed and matched the 277; its own edit rejections never produce a 277 at all. 277 files keep downloading for EDI History only. |
| How it runs | **New background service `ClaimRev_Claim_Status`**, hourly, with its own enable switch in Globals. Thin wrapper around a new `ClaimStatusPollService`. |
| 835s | **Out of scope.** Payment Advice posting (`PaymentAdvicePostingService`) already posts money and already logs `payment_posted` / `denied` timeline events. Payer acceptance is the sync's last job. |

## Design so far

### What it reacts to (only when the value changed since last recorded in `mod_claimrev_claims`)

| Signal | Field | Action |
|---|---|---|
| ClaimRev edit rejection | `statusId` in (10, 16, 17) | Fetch reason via `GetClaimErrors`; mark denied with the real error text; `rejected` event, source `claimrev`. |
| Payer rejected (277CA) | `payerAcceptanceStatusId` = 3 | Same, with the payer's codes (e.g. `A7:562:85`); source `payer_277`. |
| Payer accepted | `payerAcceptanceStatusId` = 4 | `accepted` event. OpenEMR status stays billed. Claim is final. |
| Payer pended | `payerAcceptanceStatusId` = 5 | `pending` event; keep checking. |
| First seen by ClaimRev | claim appears in results | `submitted` ("received by ClaimRev") event — fills the gap above. |
| Anything else | | Update the mirror row quietly, no event. |

Reason text: `GetClaimErrors` (`/api/ClaimView/v1/GetClaimErrors?claimId=<objectId>`). **Verified
2026-10-20** with the TESTREJECTA7 claim (pid 9102): payer errors come back with `errorType` 7
("Rejected At Payer") and the STC code in `claimStatusCode` (`A0-19`, `A0-16-PR`, `A7-562-85`).
**Do not gate on `errorCount`** from the search result: it counts only ClaimRev edit errors and is 0 for
a payer rejection. A0 (acknowledged/forwarded) codes arrive alongside the A7, so the reason saved should
prefer the rejection category (A3/A6/A7/A8) codes.

The manual Claims-tab sync button and the Claim Status tab's batch sync should go through the same
code, so all three paths behave identically. The existing batch sync (`ClaimTrackingService::syncFromClaimRev`)
logs a `claimrev_sync` event on every run even when nothing changed — replace with change detection.

### Which claims are "in flight" (used by Option A; Option B makes this mostly unnecessary)

- OpenEMR `claims`, latest version per encounter + payer type.
- Status **billed (2)**, sent through the ClaimRev X12 partner (`x12_partner_id`), `bill_time` within the
  last **60 days** — a hard stop that does not depend on any answer arriving.
- Not already final in `mod_claimrev_claims` (payer accepted/rejected, or ClaimRev rejected).
- Patient control number = `pid-encounter` (ClaimRev returns it as `patientAccountNumber`).
- If ClaimRev returns several claims for one control number (resubmission), use the most recently received.
- Missing from ClaimRev's results (still in the outbound queue): leave alone, try next run.
- On timing out at 60 days with no final answer: write one `no payer response` event so it surfaces in the
  Claim Status work queue (which already has a 45-day stale notion). Follow-up is a person, not more polling.
- Exits: rejected → denied (no longer billed); accepted → final; 60 days → aged out. Only a deliberate
  re-bill (new version, new bill date) puts a claim back.
- Per-run safety cap: 2,000 claims (40 calls), oldest bill date first.

## Open decision: load on ClaimRev

Concern raised: hundreds of installs across the country each polling claims.

Polling `SearchClaimsPaged` by patient control number (50 per call) costs one call per 50 in-flight
claims per install per hour — roughly 1 search/second across ~1,000 installs. `SearchClaimsPaged` is the
expensive one (aggregation with ERA joins, built for the portal grid), and most of those calls re-ask
about claims whose answer has not changed.

**Option A — taper the checks (module-only).** Hourly for the first 2 days after billing, every 6 hours
for days 3–14, daily for days 15–60. Store `next_check_at` on `mod_claimrev_claims`; each run asks only
about claims that are due. Cuts calls ~80–90% vs hourly. No ClaimRev change, can ship alone.

**Option B — a "what changed" endpoint on ClaimRev (recommended long-term).** e.g.
`GET /api/ClaimView/v1/GetClaimStatusChanges?since=<cursor>` returning only the account's claims whose
ClaimRev status or payer acceptance changed since the cursor, with PCN, statuses and reason codes.
One cheap indexed call per install per hour regardless of volume; nothing is re-asked; no in-flight list
needed. Cost: build the endpoint, and make sure the claim has a reliable last-status-change date
(`LatestPayerStatusDate` exists; ClaimRev's own edit rejections may not set it).

Recommendation given: build B, keep A as the fallback for a ClaimRev without the endpoint so the module
release does not wait on the API.

## Not yet designed

- Error handling (API down mid-run, partial batches, a claim whose encounter no longer exists).
- Duplicate 277CAs: the test payer sends a new 277 on every trigger; must not double-log or flip status.
  Change detection on the mirror row should cover it — confirm.
- Testing: unit tests with the existing `MockHandler` harness; live run against the TEST01 simulator
  (pids 9101–9109, `tools/test-payer-seed.sql`).

## Related fixes already made (2.2.1, uncommitted at time of writing)

- `ClaimSearchResult::objectId` was typed int; ClaimRev ids are hex strings → "Failed to load errors".
- The module read `patientControlNumber`, which the claim-search API does not return; it is
  `patientAccountNumber`. This broke the OE Status column, Requeue / Mark rejected buttons,
  Reconciliation matching, the tracking sync and the per-claim status sync. Now via
  `ClaimSearchResult::patientControlNumberOf()`.
- Offered, not done: make the manual sync button save the real error text instead of the status name
  (`public/claim_sync_status.php` passes `errorMessage => ''`). Folded into this design.
