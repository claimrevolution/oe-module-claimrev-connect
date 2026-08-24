# ClaimRevApi dependency-injection seam and first test suite

Design for GitHub issue #24.

Date: 2026-08-24
Status: approved, ready for implementation planning

## Problem

`ClaimRevApi` already supports constructor injection —
`__construct(ClientInterface $client, string $accessToken)` — but no consumer
uses it. Twelve classes acquire the client by calling the static
`ClaimRevApi::makeFromGlobals()` inline, at 18 call sites, inside otherwise
static methods:

| Class | `makeFromGlobals()` call sites |
| --- | --- |
| `ClaimSearch` | 1 |
| `EraSearch` | 2 |
| `ReportDownload` | 2 |
| `ConnectivityInfo` | 1 |
| `ClaimUpload` | 1 |
| `NotificationPollService` | 1 |
| `ClaimsPage` | 2 |
| `PaymentAdvicePage` | 2 |
| `ReconciliationService` | 1 |
| `EligibilityTransfer` | 2 |
| `ClaimTrackingService` | 2 |
| `PaymentAdvicePostingService` | 1 |

Three further call sites live in `public/` entry points
(`claim_errors.php`, `claim_mark_worked.php`, `eligibility_chat.php`) and are
out of scope — those are request handlers, not units under test.

Because the client is constructed internally rather than passed in, none of
these consumers can be exercised without a live OpenEMR bootstrap, real global
configuration, and a real OAuth token exchange. In practice they are not
tested at all: the module ships no test suite, no `require-dev`, and no CI.

## Goals

1. Every consumer above can be constructed with a caller-supplied
   `ClaimRevApi`, real or a double built on a mocked HTTP client.
2. Every existing call site and the globals-based production path keep working
   unchanged.
3. Each converted consumer has tests covering its ClaimRev-interaction path.
4. The suite runs automatically on push and pull request.

## Non-goals

- Changing runtime behaviour. Token acquisition, caching, and error semantics
  stay exactly as they are.
- Testing the DB-bound logic of the heavy services. See "Known limits".
- Converting the three `public/` entry-point call sites.
- Any refactoring not required by the above.

## Design

### The injection pattern

Each consumer gains a constructor that takes `ClaimRevApi`. Its logic moves
into an instance method. The existing static method becomes a thin wrapper that
resolves the client from globals and delegates.

PHP does not permit a static and an instance method to share a name, so the
instance method takes a distinct, more specific verb phrase while the static
wrapper keeps the existing public name. The `Impl` suffix suggested in the
issue is avoided as it carries no meaning.

```php
class EraSearch
{
    public function __construct(private readonly ClaimRevApi $api)
    {
    }

    /**
     * Static entry point. Resolves the client from globals and delegates.
     *
     * @return array<string, mixed>|false Returns false on error.
     */
    public static function search(object $search): array|false
    {
        try {
            return (new self(ClaimRevApi::makeFromGlobals()))
                ->searchDownloadableFiles($search);
        } catch (ClaimRevException) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     * @throws ClaimRevApiException on API error
     */
    public function searchDownloadableFiles(object $search): array
    {
        return $this->api->searchDownloadableFiles($search);
    }
}
```

Two rules follow from this shape:

- **Error suppression belongs in the static wrapper.** The instance method
  throws; the wrapper catches and converts to the legacy `false` return. Tests
  then assert on exceptions rather than on a sentinel, and the instance
  method's declared return type stops carrying `|false`.
- **The wrapper constructs a fresh client per call**, exactly as the current
  inline code does. No caching is introduced, so there is no behaviour change.

### Naming map

| Class | Static entry point (unchanged) | New instance method |
| --- | --- | --- |
| `ClaimSearch` | `search` | `searchClaims` |
| `EraSearch` | `search` | `searchDownloadableFiles` |
| `EraSearch` | `downloadEra` | `fetchFileForDownload` |
| `ReportDownload` | `getWaitingFiles` | `saveWaitingFiles` |
| `ReportDownload` | `download835` | `save835` |
| `ClaimUpload` | `sendWaitingFiles` | `uploadWaitingFiles` |
| `NotificationPollService` | `run` | `pollNotifications` |
| `ClaimsPage` | `exportCsv` | `exportClaimsCsv` |
| `ClaimsPage` | `getClaimStatuses` | `fetchClaimStatuses` |
| `PaymentAdvicePage` | `searchPaymentInfo` | `fetchPaymentInfo` |
| `PaymentAdvicePage` | `getPaymentAdviceById` | `fetchPaymentAdviceById` |

The remaining heavy services (`ReconciliationService`, `EligibilityTransfer`,
`ClaimTrackingService`, `PaymentAdvicePostingService`) follow the same rule;
their exact method names are settled during implementation, since several have
API calls buried mid-method that need extracting first.

### `ConnectivityInfo` is a special case

`ConnectivityInfo` has no static entry point. It is a plain object whose
constructor does all the work and exposes the result as public properties, and
its callers do `new ConnectivityInfo()`. The static-wrapper pattern does not
apply.

Instead its constructor takes an optional client, defaulting to the current
behaviour:

```php
public function __construct(?ClaimRevApi $api = null)
```

When `$api` is null the constructor resolves one via `makeFromGlobals()` inside
the existing try/catch, exactly as today; when supplied, it is used directly.
Callers stay unchanged and tests can inject a double. The class also reads
`GlobalConfig` directly for the authority, scope, and API-server fields, so its
tests additionally need the `OEGlobalsBag` stub to supply those settings.

### Test harness

- `require-dev`: `phpunit/phpunit ^11`.
- `autoload-dev`: PSR-4 mapping `OpenEMR\Modules\ClaimRevConnector\Tests\` to
  `tests/`.
- `phpunit.xml` at the repo root, one `unit` suite pointing at `tests/`.
- `tests/bootstrap.php` loads the Composer autoloader, then the stub layer.
- `composer test` script alias for `vendor/bin/phpunit`.

### Mocking the HTTP layer

Tests build a real `GuzzleHttp\Client` on a `MockHandler` and `HandlerStack`
rather than mocking `ClientInterface`:

```php
$mock = new MockHandler([new Response(200, [], json_encode($payload))]);
$history = [];
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$api = new ClaimRevApi(new Client(['handler' => $stack]), 'test-token');
```

This exercises the module's real request construction — URL, `Authorization`
header, JSON body — instead of asserting against a mock's recorded
expectations. The history middleware lets a test assert what was sent, which is
where regressions actually occur.

### OpenEMR stub layer

The consumers depend on a small OpenEMR surface, hand-written as stubs under
`tests/Stubs/` and loaded by `tests/bootstrap.php`. No attempt is made to pull
OpenEMR core in as a dev dependency.

Classes: `OpenEMR\Common\Database\QueryUtils` (6 files),
`OpenEMR\Core\OEGlobalsBag` (4), `OpenEMR\Services\BaseService` (3),
`OpenEMR\BC\ServiceContainer` (2), and one use each of
`OpenEMR\Billing\SLEOB`, `InvoiceSummary`, `EDI270`, `BillingUtilities`, and
`BillingProcessor\X12RemoteTracker`.

Global functions: `text`, `xlt`, `xl`, `attr`, `sqlInsert`,
`convert_safe_file_dir_name`.

The `QueryUtils` stub records calls and returns caller-configured rows, so a
test can drive a consumer's DB-dependent branches without a database.

## Packaging fixes

Two pre-existing defects block the test suite and are fixed as part of this
work.

**Undeclared Guzzle dependency.** `ClaimRevApi` imports `GuzzleHttp\Client`,
`ClientInterface`, and `GuzzleException`, but `composer.json` does not require
`guzzlehttp/guzzle`. This works in production only because OpenEMR core
provides Guzzle. A standalone `composer install` for the test suite does not
get it. Add `guzzlehttp/guzzle` to `require`.

**Wrong PHP floor.** `composer.json` declares `"php": ">=7.1"` while
`ClaimRevApi` is declared `readonly class`, a PHP 8.2 feature. The stated floor
has been wrong since that class landed; the module already cannot run below
8.2. Raise the constraint to `>=8.2` so the manifest matches reality. This
narrows nothing in practice — it documents an existing requirement.

## Contract corrections

Each consumer's docblock is checked against what its code actually does during
conversion, and corrected where the two disagree. An audit found one genuine
mismatch rather than a widespread pattern:

**`ClaimSearch::search()`** documents "Returns false on error for backward
compatibility" but contains no `catch`, so it cannot return `false`. A
credentials or API failure propagates as an uncaught exception. The conversion
adds the `catch (ClaimRevException)` the docblock already promises, matching
its sibling `EraSearch::search()`, which does catch.

This has a knock-on effect that is the point of the fix. `ClaimsPage`, at both
`searchClaims()` and `getClaimByObjectId()`, already tests `if ($raw === false)`
and returns an empty result. Those branches are unreachable dead code today.
Once `ClaimSearch::search()` can actually return `false`, they become live and
the Claims tab degrades to an empty result set instead of surfacing an
exception. Tests cover both the `false` return and the now-reachable branches.

Where a consumer's docblock is already accurate — `PaymentAdvicePage` declares
no `false` return and correctly lets exceptions propagate to its page-level
handler — nothing changes. Contract corrections are limited to genuine
mismatches; this is not a licence to restructure error handling that already
works.

## CI

`.github/workflows/tests.yml`, triggered on push and pull request:

1. `actions/checkout`
2. `shivammathur/setup-php` across a PHP 8.2 and 8.3 matrix
3. `composer install --prefer-dist --no-progress`
4. `vendor/bin/phpunit`

PHPStan is deliberately left out of CI in this pass. The repo has no
`phpstan.neon`; adding static analysis to CI is a separate decision.

## Sequencing

The harness, packaging fixes, and CI land first as one commit, so later commits
have something to run against. Consumers then convert in ascending order of
risk, each conversion landing with its tests so a bisect points at one class:

1. Harness, `composer.json` changes, `phpunit.xml`, stubs, CI.
2. Thin consumers: `ClaimSearch`, `EraSearch`, `ReportDownload`,
   `ConnectivityInfo`, `ClaimUpload`, `NotificationPollService`.
3. Page controllers: `ClaimsPage`, `PaymentAdvicePage`.
4. Heavy services: `ReconciliationService`, `EligibilityTransfer`,
   `ClaimTrackingService`, `PaymentAdvicePostingService`.

## Known limits

The four heavy services total roughly 2,500 lines and reach their ClaimRev
calls only after substantial database work. Their tests cover the
ClaimRev-interaction slice — that the right endpoint is called with the right
payload, and that failures are handled — and not the surrounding posting,
reconciliation, or transfer logic. That slice is what issue #24 asks for, but
these files will not have meaningful overall coverage when this work is done,
and the spec should not be read as claiming otherwise.

One further note: verification for this work is the test suite itself, run
locally and in CI. The only intended runtime behaviour change is the
`ClaimSearch` contract correction described above, which is called out in the
changelog; everything else is structural and should be invisible at runtime.

## Acceptance criteria

- All 12 consumers accept a `ClaimRevApi` via constructor.
- No existing call site changed.
- Each converted consumer has tests exercising its ClaimRev path, success and
  failure.
- `composer install && vendor/bin/phpunit` passes from a clean checkout with no
  OpenEMR present.
- CI runs the suite on push and pull request.
