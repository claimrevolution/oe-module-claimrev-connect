# 2.1.5
Critical fix — installing the module could make all calendar appointments disappear:
- The calendar eligibility indicator (`CalendarEligibilityIndicator::filterCalendarEvents`, enabled by "Enable Calendar Eligibility Indicators") listens on OpenEMR's `CalendarUserGetEventsFilter`. Core dispatches that filter inside `postcalendar_userapi_pcGetEvents()` with no error handling, then returns whatever the listeners produce. The listener ran an unguarded `SELECT ... FROM mod_claimrev_eligibility` (referencing `payer_responsibility`, `last_checked`, `create_date`, `individual_json`, `status`). On an install whose `mod_claimrev_eligibility` schema was out of date (missing column) or otherwise unhappy, that query threw — and because core has no try/catch around the dispatch, the exception aborted the whole calendar event fetch, so **every appointment vanished from the calendar**. Disabling the module removed the listener and the appointments reappeared, which is why the symptom looked like the module was deleting appointments. It never touched appointment data.
- Fix: wrap the indicator logic in a fault boundary. A cosmetic color indicator must never be able to remove appointments, so `filterCalendarEvents` now delegates to `applyEligibilityIndicators` inside a `try/catch (\Throwable)`; on any failure it logs via `error_log` and returns the events unmodified. The calendar always renders; at worst the eligibility colors are missing.

# 2.1.4
Cross-version compatibility fix:
- Resolve the OpenEMR kernel via a new `Compat\KernelCompat::resolve()` helper instead of calling `OEGlobalsBag::getInstance()->getKernel()` directly. `getKernel()` only exists on core's flex/master line; the 8.0.x patch releases ship an `OEGlobalsBag` without it. Because the real class is present on 8.0.x, the `OEGlobalsBagShim` swap never activates there, so the direct call fatalled with "Call to undefined method OEGlobalsBag::getKernel()" during login bootstrap — taking the whole site to a 500. The helper reads the kernel from the `'kernel'` global (with a type guard), exactly as flex's `getKernel()` does internally, so the same binary works on 7.x, 8.0.x, and flex. Fixes the regression introduced in 2.1.3.

# 2.1.3
Cross-version compatibility and security hardening:
- Read stored credentials with `CryptoGen::decryptStandard` instead of `decryptFromDatabase`. The newer helper was added to OE core in the 8.x line but does not exist on OE 7.x; reverting to `decryptStandard` lets the same module binary work on both branches.
- Close findings from the external Aisle Analyzer security review of PR #11265: tighten IDOR and CSRF gaps on AJAX endpoints, refetch ERA and claim status server-side rather than trusting the browser to echo them back, and tighten property-access hardening on several response shapes.

Test mode coverage extended to every gated page:
- ERA tab and `EraDownload` short-circuit to mock data when test mode is enabled, gated by the global setting (the per-tab checkbox is removed).
- Payment Advice tab reads the test-mode global directly; per-tab checkbox removed.
- Reconciliation tab returns mock rows via `ReconciliationMockService`.
- Eligibility Chat returns mock AI answers in test mode.
- `claim_sync_status` and `claim_requeue` short-circuit cleanly without contacting the API.
- README's test-mode coverage row documents every gated page.

Maintenance:
- Rector/PHPStan cleanups across the module.
- Fix `STATUS_UPLOAD_ERROR` constant typo.
- Miscellaneous narrowing of array/object access patterns flagged by PHPStan.

# 2.1.2
Bug fixes:
- Send `serviceTypeCodes` as a JSON array (`List<string>`) instead of a comma-separated string. The ClaimRev API tightened request validation and started rejecting the old shape with HTTP 400, breaking Check Now eligibility requests. Empty configuration still asks for all benefits.
- Always emit `isRevenueToolsPayerId: false` on each payer in the eligibility request so the API can disambiguate ClaimRev-internal payer IDs from clearinghouse payer numbers.
- Make MBI Finder mutually exclusive with Eligibility (matches Coverage Discovery). Drop the `payers` array from the request when only non-eligibility products are selected, since the API ignores it for those products and the presence corrupts MBI Finder results. When MBI Finder is requested, copy the subscriber number to the top-level `subscriberId` field.
- Render Coverage Discovery results with the full Quick Info / Deductibles / Benefits / Medicare / Validations layout used by Eligibility. The API returns the same `SharpRevenueEligibilityResponse` shape for both products, but the old Coverage Discovery view only showed the flat top-level coverage fields and dropped the nested `mapped271` data.
- Allow Coverage Discovery, Demographics, and MBI Finder to run on a patient with no insurance on file. These products query the payer using patient demographics and don't need a payer row, but the form previously rendered nothing without insurance and the backend returned "No insurance data found for patient" if a check was somehow submitted. The form now shows a "No Insurance" tab that exposes those three products (without the Eligibility option), and `EligibilityObjectCreator::buildObject` falls back to a patient-data-only request in that case.
- Stop intermittently popping "Error communicating with server" on `Check Now`. The eligibility AJAX endpoint can sit through a Cloud Run cold start (~60s) plus a `retryLater` poll loop (~60s) on Coverage Discovery, which exceeded PHP's default 30 second `max_execution_time`; PHP killed the script mid-flight and the browser saw a non-JSON response. Bump `set_time_limit` to 180s on the eligibility and appointment Check Now endpoints, set explicit Guzzle `connect_timeout`/`timeout` (30s/60s) on the auth and main API clients so a stuck call can't burn the whole budget, and retry the OAuth token POST up to two extra times with brief backoff to absorb transient B2C hiccups.
- Add a "Reset" button next to "Check Now" on the patient's eligibility tab. Clicking it (after a confirm prompt) deletes every cached eligibility row for that patient across all payer responsibilities, so testers can re-run a check from a clean slate without poking the database directly.

# 2.1.1
Maintenance release: apply phpcbf style fixes, rector modernization, refresh PHPStan baseline, and refactor CSV downloads + migration helpers to avoid Semgrep XSS/SQLi false positives. No functional changes.

# 2.1.0
Adds patient balance, KPI dashboard, AR aging report, denial analytics, recoupment report, eligibility sweep with calendar indicators and appointment filters, payment-advice posting, claim status dashboard with timeline, reconciliation page, and OpenEMR 7.x compatibility shims.

# 1.0.12
Added new setup helpers to stop the sftp service from interfering with the file sending service of this module.
