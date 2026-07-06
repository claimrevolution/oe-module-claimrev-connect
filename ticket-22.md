## Problem

`src/EligibilityTransfer.php` has several inline `@var` docblocks that assert a specific array shape immediately above a call that returns a *different*, looser type. These overrides don't match what the called function actually returns, and they would silently hide a real type mismatch if one were ever introduced by a future change to the called function.

This pattern already caused a production incident in a downstream fork: two `EligibilityData` getters used to return a raw ADODB recordset object while their only consumers type-hinted `array`. A `@var array{...}` docblock directly above each call site told static analysis "trust me, this is an array" instead of letting the tooling flag the mismatch, so the bug shipped and ran silently in production for months (a background cron job threw a `TypeError` on every tick and the failure was swallowed by the job runner).  `EligibilityData`'s getters here already return plain arrays (fixed via the `f5baea0` "sync to v2.1.3" commit), so this particular file is not currently broken — but the stale overrides are still in place and would mask the same class of bug again if `EligibilityData` regressed.

## Background

- `EligibilityData::getEligibilityCheckByStatus()` and `getEligibilityResults()` were fixed to call `QueryUtils::fetchRecords()` and declare `: array` return types (see `src/EligibilityData.php`, `asListOfRecords()` helper). Their real return type is `list<array<string, mixed>>`.
- `EligibilityTransfer.php` still overrides the inferred type at four call sites with `@var` docblocks that don't match:
  - Line 53: `array<int, array{id: int|string, request_json?: string, pid?: int|string, payer_responsibility?: string}>`
  - Line 75: `array<int, array{id: int|string, request_json?: string}>`
  - Line 79: `array<int, array{id: int|string}>`

  None of these match the actual `list<array<string, mixed>>` shape. They happen to be harmless today only because nothing currently returns a non-array type there.
- Two further `@var` overrides in the same file narrow `mixed` data decoded from a third-party API JSON response without any runtime check:
  - Line 422: `array<int|string, array<string, mixed>>` for `$individuals` (from `$mappedData['individuals']`, itself from `json_decode()` of an external API response)
  - Line 441: `array<int|string, array<string, mixed>>` for `$eligibilities`

  These assert shape on data straight from an external HTTP response with no validation — if the API ever returns something else, this masks it instead of surfacing it as an error.

## Files / Symbols

- `src/EligibilityTransfer.php:53` — `@var` above `getEligibilityCheckByStatus()` call (test-mode branch)
- `src/EligibilityTransfer.php:75` — `@var` above `getEligibilityCheckByStatus()` call
- `src/EligibilityTransfer.php:79` — `@var` above `getEligibilityResults()` call
- `src/EligibilityTransfer.php:422` — `@var` on `$mappedData['individuals']`
- `src/EligibilityTransfer.php:441` — `@var` on `$individual['eligibility']`
- `src/EligibilityData.php` — `getEligibilityCheckByStatus()`, `getEligibilityResults()` (already return `list<array<string, mixed>>`, no changes needed here)

## Approach

1. For lines 53, 75, 79: delete the `@var` overrides and update the parameter docblocks on `retryEligibility()` and `sendEligibility()` (and the inline `foreach` in the test-mode branch) to the getters' real return type, `list<array<string, mixed>>`. Where the code reads a specific key (e.g. `$row['id']`, `$row['pid']`), narrow with an explicit check (`is_int()`/`is_string()`, `isset()`) rather than asserting the shape up front — this also lets malformed rows be skipped instead of causing a fatal error downstream.
2. For lines 422 and 441: replace the `@var` assertions with runtime `is_array()` narrowing (the surrounding code already does this in several other places in the same file, e.g. around `$mappedData` and `$sharpMapped`/`$visitMapped` in `pollForResults()` — follow that pattern) so a malformed API response degrades to the existing `STATUS_SEND_ERROR` path instead of a fatal error.
3. Run `composer` static analysis / lint scripts defined in this repo (there is currently no PHPStan configuration here, so this is a manual correctness pass rather than something CI will verify) and confirm the existing eligibility flows still work end to end (test-mode background send, real API send, retry).

## Definition of done

- [ ] No `@var` docblock in `src/EligibilityTransfer.php` asserts a shape that doesn't match the real return type of the call directly above it
- [ ] Reads from decoded external API JSON (`$mappedData['individuals']`, `$individual['eligibility']`, etc.) are narrowed with runtime checks, not `@var` assertions
- [ ] Existing eligibility send/retry code paths still behave the same for well-formed input (manual verification or existing test suite, if any)

## Out of scope

- Changing `EligibilityData.php`'s getters — already fixed and correct.
- Broader refactor of `EligibilityTransfer.php`'s error handling beyond the specific `@var` sites listed above.
