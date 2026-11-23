# Automatic claim status sync (277CA / ClaimRev rejections) — DRAFT

**Status:** draft. Paused 2026-10-20; resumed 2026-11-23 when ClaimRev shipped webhooks
(https://developer.claimrev.com/guides/webhooks). Parts 1–2 agreed in discussion. **The open decision
(how the module finds out what changed) is resolved: webhooks first, tapered polling as the fallback**
— see "How the module finds out what changed". Error handling and duplicates are now designed. The
module's ZITADEL account gets `webhooks:manage` (decided 2026-11-23). The items under "Still open"
need Brad's answer or a verification run.

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
| How it learns about changes | **ClaimRev webhooks when the install can receive them; tapered polling (Option A) when it can't, plus a daily sweep either way.** Option B (a ClaimRev "changes since" endpoint) is dropped — webhooks do its job. |
| How it runs | **New background service `ClaimRev_Claim_Status`**, with its own enable switch in Globals. Thin wrapper around a new `ClaimStatusSyncService` that (1) drains the webhook event queue and (2) runs the polling pass when one is due. |
| 835s | **Out of scope.** Payment Advice posting (`PaymentAdvicePostingService`) already posts money and already logs `payment_posted` / `denied` timeline events. Payer acceptance is the sync's last job. `era.*` webhooks are not subscribed for now (see "Later"). |

## What it reacts to

Applies the same way whether the change arrived by webhook or by polling. Acts only when the value
changed since it was last recorded in `mod_claimrev_claims`.

| Signal | Field (polling) | Webhook event | Action |
|---|---|---|---|
| ClaimRev edit rejection | `statusId` in (10, 16, 17) | `claim.rejected` | Fetch reason via `GetClaimErrors`; mark denied with the real error text; `rejected` event, source `claimrev`. |
| Payer rejected (277CA) | `payerAcceptanceStatusId` = 3 | `claim.payer_rejected` | Same, with the payer's codes (e.g. `A7:562:85`); source `payer_277`. |
| Payer accepted | `payerAcceptanceStatusId` = 4 | `claim.payer_accepted` | `accepted` event. OpenEMR status stays billed. Claim is final. |
| Payer pended | `payerAcceptanceStatusId` = 5 | `claim.payer_status` | `pending` event; keep checking. |
| First seen by ClaimRev | claim appears in results | `claim.accepted` | `submitted` ("received by ClaimRev") event — fills the gap above. |
| Held / sent / acknowledged | | `claim.held`, `claim.sent_to_payer`, `claim.payer_acknowledged` | Update the mirror row quietly, no event. |
| Anything else | | | Update the mirror row quietly, no event. |

Reason text: `GetClaimErrors` (`/api/ClaimView/v1/GetClaimErrors?claimId=<objectId>`). **Verified
2026-10-20** with the TESTREJECTA7 claim (pid 9102): payer errors come back with `errorType` 7
("Rejected At Payer") and the STC code in `claimStatusCode` (`A0-19`, `A0-16-PR`, `A7-562-85`).
**Do not gate on `errorCount`** from the search result: it counts only ClaimRev edit errors and is 0 for
a payer rejection. A0 (acknowledged/forwarded) codes arrive alongside the A7, so the reason saved should
prefer the rejection category (A3/A6/A7/A8) codes.

Webhook payloads never carry the rejection text (ids and control numbers only, by design), so a
rejection event always costs one `GetClaimErrors` call. Every other event type is applied from the
payload alone, with no API call.

The manual Claims-tab sync button and the Claim Status tab's batch sync should go through the same
code, so all three paths behave identically. The existing batch sync (`ClaimTrackingService::syncFromClaimRev`)
logs a `claimrev_sync` event on every run even when nothing changed — replace with change detection.

## How the module finds out what changed

### Why webhooks, and why polling stays

The load concern (hundreds of installs each polling `SearchClaimsPaged`, the expensive portal-grid
query, mostly re-asking about claims that have not changed) goes away for any install that receives
webhooks: ClaimRev pushes only the changes, and the module calls back only for rejection reasons.

Polling cannot be removed, for two reasons:

1. **Many installs cannot receive webhooks.** ClaimRev delivers only to an `https://` URL on a public
   hostname — no IPs, no `localhost`, no `.local` / internal names. A practice running OpenEMR on a
   LAN behind NAT never qualifies. Those installs use Option A (tapered polling) as their only path.
2. **ClaimRev's own guide says to keep a daily sweep** even with webhooks. It catches events from
   before the subscription existed, while it was paused or disabled, after the 30-day delivery purge,
   and anything the module failed to process locally.

| Install | Webhook path | Polling path |
|---|---|---|
| Webhooks registered and healthy | Primary — every change | Daily sweep of all in-flight claims |
| No webhooks (not reachable, or switched off) | — | Option A taper: hourly days 0–2, every 6 h days 3–14, daily days 15–60 |
| Webhooks registered but subscription not `Active` | — | Falls back to the Option A taper until it is `Active` again |

### Receiving: `public/webhook.php`

URL: `https://<public host>/interface/modules/custom_modules/oe-module-claimrev-connect/public/webhook.php?site=<site_id>`.
`site` picks the OpenEMR site in a multisite install. Each site registers its own subscription and has
its own secret.

The receiver does as little as possible, because ClaimRev waits only **10 seconds** for a 2xx:

1. Set `$ignoreAuth = true` before including `globals.php`. ClaimRev sends a signature, not an OpenEMR
   session or bearer token. On 8.3+, any session-less request that is not flagged this way gets the empty
   HTTP 400 (see the blank-400 note), so this is the one thing that must be right. **Verify on oe8
   (8.5.0) and oe7 (7.0.2)** that `$ignoreAuth` still lets a session-less POST through.
2. Read the raw body with `file_get_contents('php://input')` **before** any JSON decode (decoding changes
   the bytes and breaks the signature).
3. Verify the signature:
   - Parse `ClaimRev-Signature: t=<unix>,v1=<hex>[,v1=<hex>]`.
   - Reject if `|now − t| > 300` s.
   - Key = base64-decode of the secret with its `whsec_` prefix removed.
   - Expected = lowercase hex `HMAC-SHA256(key, t . "." . rawBody)`.
   - Accept if `hash_equals()` matches **any** `v1`. There are two `v1` values during a secret rotation.
   - Unit-test against the guide's test vector.
4. `INSERT IGNORE` into `mod_claimrev_webhook_events` keyed on the event `id`. A duplicate (ClaimRev keeps
   the same id across retries and redeliveries) is a success.
5. Return 2xx. No claim processing, no API calls, no OpenEMR claim writes in the request.

Responses:

| Case | Status | Effect at ClaimRev |
|---|---|---|
| Stored, or already stored | 204 | Delivered |
| Bad / missing signature, stale timestamp | 401 | Retried, then counted as a failure |
| Body not JSON / no `id` | 400 | Retried, then failure |
| Unknown `site`, or the site's DB is unreachable | 503 | Retried on the schedule (1 m, 5 m, 15 m, 1 h, 2 h, then every 4 h to 24 h) |
| Webhooks switched off in Globals, module disabled | 410 | **ClaimRev pauses the subscription** and holds new events. Turning webhooks back on calls `resume` with "redeliver failed since" the pause date. |

The receiver never returns 3xx (ClaimRev treats redirects as failures). Register the final URL.

Events arriving after ClaimRev's 24-hour retry window, or redelivered by hand from the portal, are
accepted like any other if the signature is valid. Duplicates are already harmless.

`webhook.test` events are stored and marked processed, and the Setup page shows "last test received at".

### Storing: `mod_claimrev_webhook_events`

| Column | Notes |
|---|---|
| `event_id` VARCHAR(64) PK | ClaimRev `id` (`evt_…`) — the dedupe key |
| `event_type` VARCHAR(50) | |
| `occurred_utc` DATETIME | from the payload; processing order |
| `received_at` DATETIME | |
| `claimrev_claim_id` VARCHAR(64) | `data.claimId`, indexed |
| `patient_control_number` VARCHAR(64) | `data.patientControlNumber` |
| `payload` TEXT | raw body as received |
| `status` ENUM(`pending`,`processed`,`ignored`,`failed`) | |
| `attempts` TINYINT, `last_error` TEXT, `processed_at` DATETIME | |

Processed and ignored rows are purged after 90 days. Failed rows are kept until a person looks at them.

Secret and subscription live in `mod_claimrev_auth` (the singleton credentials row), not in a global:
`webhook_subscription_id`, `webhook_url`, `webhook_signing_secret` (encrypted with `CryptoGen`, like
`zitadel_key`), `webhook_status`, `webhook_checked_at`.

### Processing: the `ClaimRev_Claim_Status` service

Each run drains `pending` events, oldest `occurred_utc` first, in batches (e.g. 200).

- **Match the claim.** Match `data.claimId` against `mod_claimrev_claims.claimrev_object_id` (already
  indexed). If that finds nothing, fall back to the patient control number, `pid-encounter`. If neither
  matches an OpenEMR claim (not ours, or the encounter was deleted), mark the event `ignored`.
- **Out-of-order events.** Store `last_event_utc` on `mod_claimrev_claims`. An event whose `occurredUtc`
  is older than that only updates history and never moves the status backwards. A late `claim.payer_status`
  (pended) after a `claim.payer_rejected` must not reopen the claim.
- **Apply** through the same change-detection code as polling (the table above), so a webhook, the
  sweep and the manual buttons can never disagree.
- **Failures.** If `GetClaimErrors` is down or the DB write fails, the event stays `pending`,
  `attempts++`, and is retried next run. After 5 attempts it is marked `failed` and the claim is left
  for the daily sweep. One bad event never blocks the rest of the batch.

The event queue fills between runs. OpenEMR background services run only while a user has OpenEMR open,
or from cron. Draining an empty queue is a single local query, so a short interval (1 minute) is cheap.
The sweep runs at most once a day per site (`next_sweep_at` stored in Globals or `mod_claimrev_auth`).

### Registering the subscription (Setup page)

The Setup page gets a "Webhooks" section:

- **Public URL.** Pre-filled from OpenEMR's site address global (`site_addr_oath`) with the receiver
  path appended; editable. Checked up front with ClaimRev's rules (https, hostname not IP, not
  `localhost` / `.local`) so the user gets a clear message instead of an API error.
- **Turn on webhooks.** `POST /api/Webhooks/v1/subscriptions` with the module's own token, `eventTypes` =
  the seven `claim.*` types above, `schemaVersion` 1. Store the one-time `signingSecret` immediately and
  encrypted (it is never returned again). Then `POST …/subscriptions/{id}/test` and wait for the
  `webhook.test` to arrive. That proves end to end that this host is reachable and the signature check
  works, and the page shows pass/fail.
- **Status.** Daily `GET …/subscriptions/{id}` updates `webhook_status`. Anything other than `Active`
  (paused, or disabled after 3 days of failures) shows a warning on the Setup page and switches that
  site to the Option A taper.
- **Rotate secret.** `POST …/rotate-secret`; save the new secret. The old one keeps signing for 24 h and
  the receiver accepts either, so there is no gap.
- **Turn off / module disable / uninstall.** `DELETE …/subscriptions/{id}` from the Setup page and from
  `ModuleManagerListener` (disable, uninstall). If the delete fails (offline), the receiver's 410 pauses it
  at ClaimRev on the next delivery anyway.
- **Permission.** **Decided 2026-11-23: the module's ZITADEL service account gets `webhooks:manage`**,
  so one-click registration, the health check, rotation and delete all run with the module's own token.
  If a call still returns 403 (an older key without the grant), the Setup page says the key needs the
  webhooks permission and to contact ClaimRev support. There is no manual paste-the-secret path; a
  secret registered in the portal could not be health-checked or rotated by the module anyway.

An account can have at most 10 subscriptions. Multisite installs and practices sharing one ClaimRev
account count against that limit. If the create call returns the limit error, show that message as-is.

## Which claims are "in flight" (polling path and the daily sweep)

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
- Option A taper: store `next_check_at` on `mod_claimrev_claims`; each run asks only about claims that are
  due. Cuts calls ~80–90% vs hourly.
- Per-run safety cap: 2,000 claims (40 calls), oldest bill date first.

## Duplicates

- **Duplicate deliveries of the same event:** the `event_id` primary key; the second insert is a no-op 2xx.
- **Duplicate 277CAs** (the TEST01 payer sends a new 277 on every trigger): these arrive as *different*
  events with the same status. Change detection on the mirror row makes the second a no-op, so there is
  no second timeline event and no second claim version. The same rule covers a webhook and the sweep
  both seeing the same change.

## Testing

- **Unit** (existing `MockHandler` harness): signature verification against the guide's test vector, the
  stale timestamp, the rotation case with two `v1` values and a tampered body. Receiver status codes per
  case. Out-of-order handling (rejected then pended stays rejected). Dedupe. Match by `claimId` then PCN.
  Retry → `failed` after 5 attempts.
- **Live on oe8** (`oe8.claimrev.com` is behind Cloudflare on a real hostname, so it qualifies): register
  through the Setup page, confirm the test event, then run the TEST01 simulator claims (pids 9101–9109,
  `tools/test-payer-seed.sql`). TESTREJECTA7 (pid 9102) should go to denied with the A7 reason within a
  minute of the 277CA, without the sweep. Confirm Cloudflare passes the `ClaimRev-*` headers through
  untouched.
- **Fallback:** pause the subscription in the portal → the Setup page warns and the taper takes over →
  resume with redelivery → no duplicate timeline events.
- **No-webhooks install:** the local Docker OpenEMR (localhost, cannot register) must run the taper alone,
  and the Setup page must explain why webhooks are unavailable.

## Still open

- **Event → signal mapping is inferred from the event names.** In particular, `claim.payer_status` = pended
  and `claim.accepted` = received by ClaimRev. Confirm against `GET /api/Webhooks/v1/event-types` (it
  returns samples) before building.
- **`$ignoreAuth` on 8.3+/8.5** — verify a session-less POST reaches the receiver (see Receiving, step 1).
- **Payer type.** The PCN is `pid-encounter` for primary and secondary alike. Confirm `data.claimId`
  always matches a stored `claimrev_object_id` for secondaries, or decide how a PCN-only match picks the
  payer type.
- **Cloudflare / WAF in front of customer installs** may block or challenge a POST with no browser
  cookies. Document an allow rule for the receiver path.

## Later (not in this design)

- Subscribe to `era.claim_paid` / `era.check_received` to trigger the Payment Advice download sooner
  instead of waiting for its schedule.
- Webhook-driven eligibility, if ClaimRev adds eligibility events.

## Related fixes already made (2.2.1, uncommitted at time of writing)

- `ClaimSearchResult::objectId` was typed int; ClaimRev ids are hex strings → "Failed to load errors".
- The module read `patientControlNumber`, which the claim-search API does not return; it is
  `patientAccountNumber`. This broke the OE Status column, Requeue / Mark rejected buttons,
  Reconciliation matching, the tracking sync and the per-claim status sync. Now via
  `ClaimSearchResult::patientControlNumberOf()`.
- Offered, not done: make the manual sync button save the real error text instead of the status name
  (`public/claim_sync_status.php` passes `errorMessage => ''`). Folded into this design.
