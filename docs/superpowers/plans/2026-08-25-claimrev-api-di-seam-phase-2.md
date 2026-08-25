# ClaimRevApi DI Seam — Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the substance of issue #24 — leave no consumer with an inline `makeFromGlobals()` where injection would actually unlock coverage — and claim the pure-function coverage that needs no seam at all.

**Architecture:** Same seam as phase 1: constructor takes `ClaimRevApi`, logic moves to a distinctly-named instance method, the existing entry point becomes a wrapper that resolves the client and delegates. Phase 2 differs in that the consumers have three distinct shapes, and the wrapper must be written to match each one's *existing* error contract rather than a single template.

**Tech Stack:** PHP 8.2+, PHPUnit 11, Guzzle 7 `MockHandler`, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-08-24-claimrev-api-di-seam-design.md`
**Phase 1 plan:** `docs/superpowers/plans/2026-08-24-claimrev-api-di-seam-phase-1.md`

**Shell:** every command is POSIX. This repo is on Windows — run them through the Bash tool (Git Bash), not PowerShell.

**Branch:** continue on `feature/claimrev-api-di-seam`. Do not merge; phase 1 and phase 2 ship together.

---

## What recon changed, and why this plan is not "convert all nine"

Four read-only explorers mapped the nine remaining consumers before this plan was written. Three findings reshaped the scope:

1. **The intimidating files are the easy ones.** `PaymentAdvicePostingService` (884 lines, writes billing tables) has exactly **one** `makeFromGlobals()` call site, in a 14-line best-effort method that runs last and already swallows failures. Its money-moving code never touches `ClaimRevApi`. `ClaimTrackingService` has two call sites, both cleanly shaped `[API] → [DB]`, and writes only to module-owned tables.

2. **Some consumers need nothing.** `EligibilityTransfer::retryEligibility()` and `::sendEligibility()` already take `ClaimRevApi $api` as a parameter. Issue #24's goal is met for them; converting them would be motion, not progress.

3. **A mechanical conversion would break real behaviour in two places.** `ReconciliationService::reconcile()` catches `ClaimRevException` around its buried API call and returns real OpenEMR data with `claimRevLookupFailed = true`, which drives a warning banner on a page that still shows results. A phase-1-style wrapper returning early would destroy that. And several methods have **no catch at all** today, so their wrappers must have none either.

### Deliberately out of scope

- **`ConnectivityInfo`** — no static entry point; its constructor does everything and reads `Bootstrap`/`KernelCompat`/`GlobalConfig` before any API call. Converting it means stubbing that whole chain for a diagnostics screen. Poor value; deferred.
- **`EligibilityTransfer::sendWaitingEligibility()` and `::sendImmediate()`** — the API call in `sendImmediate()` sits ~55 lines deep, entangled with `EligibilityData` and `EligibilityObjectCreator` DB writes; `sendWaitingEligibility()` has a complete parallel test-mode branch. The class also `extends BaseService`, whose constructor needs a live DB and `OEGlobalsBag::getKernel()`, and `pollForResults()` calls `sleep(3)` up to twenty times. Reshaping these safely is its own project.
- **`ReconciliationService::reconcile()` itself** — only its `lookupClaimRev()` helper is converted.
- **All of `PaymentAdvicePostingService` except `markWorkedOnClaimRev()`** — the billing code has no API dependency, so touching it would add financial-write risk for zero testability gain.

Every exclusion is recorded in the changelog in Task 10, so the gap is visible rather than silently unfinished.

## The three wrapper shapes

Getting this wrong is how phase 1 introduced a regression. Match the shape to the consumer's existing contract.

**Shape A — no catch today.** The wrapper gets **no try/catch**. Exceptions must keep propagating to the caller exactly as now.

```php
public static function entryPoint(array $in): array
{
    return (new self(ClaimRevApi::makeFromGlobals()))->instanceMethod($in);
}
```

Applies to: `ClaimsPage::exportCsv`, `PaymentAdvicePage::searchPaymentInfo`, `PaymentAdvicePage::getPaymentAdviceById`.

**Shape B — catch wraps `makeFromGlobals()` today.** Construction must stay inside the try, or an unconfigured module escapes where it previously returned a value.

```php
public static function entryPoint(int $x): array
{
    try {
        $service = new self(ClaimRevApi::makeFromGlobals());
    } catch (ClaimRevException) {
        return [/* the exact same value the old catch returned */];
    }
    return $service->instanceMethod($x);
}
```

Applies to: `ClaimsPage::getClaimStatuses`, `ClaimTrackingService::checkStatus276`, `ClaimTrackingService::batchSyncFromClaimRev`, `NotificationPollService::run`, `ClaimUpload::sendWaitingFiles`.

**Shape C — private helper whose caller owns the try.** The helper delegates; the caller's existing try is untouched, so `makeFromGlobals()` stays inside it.

```php
private static function helper(array $in): array
{
    return (new self(ClaimRevApi::makeFromGlobals()))->instanceMethod($in);
}
```

Applies to: `ReconciliationService::lookupClaimRev`, `PaymentAdvicePostingService::markWorkedOnClaimRev`.

**The rule, restated:** never widen or narrow an existing catch. Preserve each consumer's error contract exactly. Two consumers here (`ClaimsPage::getClaimStatuses`, `ClaimTrackingService`) already catch the `ClaimRevException` base class — the anti-pattern phase 1 fixed elsewhere. **Preserve them verbatim.** Fixing them is a separate, deliberate change, recorded as follow-up.

## File structure

**Created:**

| Path | Responsibility |
| --- | --- |
| `tests/Stubs/OpenEMR/Core/OEGlobalsBag.php` | Moved from the single stub file. |
| `tests/Stubs/OpenEMR/BC/ServiceContainer.php` | Moved. |
| `tests/Stubs/OpenEMR/Common/Database/QueryUtils.php` | New. Queue-driven fake. |
| `tests/Stubs/OpenEMR/Billing/BillingProcessor/X12RemoteTracker.php` | New, for `ClaimUpload`. |
| `tests/Stubs/support.php` | `ClaimRevStubState`, `ClaimRevStubLogger`, and the global functions. |
| `tests/Stubs/load.php` | Manifest requiring every stub in dependency order. |
| `tests/Unit/PureFunctionsTest.php` | Coverage for the eight seam-free pure functions. |
| `tests/Unit/ClaimsPageTest.php`, `PaymentAdvicePageTest.php`, `ClaimTrackingServiceTest.php`, `PaymentAdvicePostingServiceTest.php`, `ReconciliationServiceTest.php`, `NotificationPollServiceTest.php`, `ClaimUploadTest.php` | One per converted consumer. |

**Modified:** `tests/bootstrap.php`, and the seven `src/` consumers listed above. `tests/Stubs/openemr-classes.php` is deleted by Task 1.

---

### Task 1: Restructure and extend the stub layer

Phase 1 left this as an explicit prerequisite. Four classes in one file was fine; the dozen phase 2 needs is not, and path-mirroring is what made phase 1's fidelity audit easy.

**Files:**
- Create: `tests/Stubs/support.php`, `tests/Stubs/load.php`, `tests/Stubs/OpenEMR/Core/OEGlobalsBag.php`, `tests/Stubs/OpenEMR/BC/ServiceContainer.php`, `tests/Stubs/OpenEMR/Common/Database/QueryUtils.php`, `tests/Stubs/OpenEMR/Billing/BillingProcessor/X12RemoteTracker.php`
- Delete: `tests/Stubs/openemr-classes.php`
- Modify: `tests/bootstrap.php`

- [ ] **Step 1: Create `tests/Stubs/support.php`**

Carry `ClaimRevStubState` and `ClaimRevStubLogger` over unchanged in behaviour, extended with DB state, and add the global functions the module calls.

```php
<?php

/**
 * Test-only helpers that double nothing in OpenEMR core: the shared state
 * holder, the recording logger, and the handful of global functions the
 * module calls. Classes that double a real core class live under
 * tests/Stubs/OpenEMR/, mirroring the real path.
 */

declare(strict_types=1);

/** Shared mutable state for every stub. Reset it in setUp(). */
final class ClaimRevStubState
{
    /** @var array<string, mixed> Backing store for OEGlobalsBag. */
    public static array $globals = [];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public static array $logs = [];

    /**
     * FIFO queue of values QueryUtils hands back, one per call, in order.
     * Empty queue means each method falls back to its own empty default.
     *
     * @var list<mixed>
     */
    public static array $queryResults = [];

    /** @var list<array{sql: string, binds: array<int|string, mixed>}> Every query attempted. */
    public static array $queries = [];

    /** @var list<array<string, mixed>> Rows X12RemoteTracker hands back. */
    public static array $x12Rows = [];

    /** @var list<array<string, mixed>> Rows X12RemoteTracker was asked to update. */
    public static array $x12Updates = [];

    /** @var list<array<string, mixed>> Notes addPnote() was asked to create. */
    public static array $pnotes = [];

    public static function reset(): void
    {
        self::$globals = [];
        self::$logs = [];
        self::$queryResults = [];
        self::$queries = [];
        self::$x12Rows = [];
        self::$x12Updates = [];
        self::$pnotes = [];
    }

    /** Take the next queued query result, or the given default when the queue is dry. */
    public static function nextQueryResult(mixed $default): mixed
    {
        if (self::$queryResults === []) {
            return $default;
        }
        return array_shift(self::$queryResults);
    }
}

/** Records log calls so tests can assert on error reporting. */
final class ClaimRevStubLogger extends \Psr\Log\AbstractLogger
{
    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        ClaimRevStubState::$logs[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

// --- OpenEMR global functions -------------------------------------------
// The module calls these unqualified. Real OpenEMR defines them in
// library/; here they are the simplest behaviour-compatible versions.

if (!function_exists('text')) {
    function text(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('attr')) {
    function attr(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('xl')) {
    function xl(string $s): string
    {
        return $s;
    }
}

if (!function_exists('xlt')) {
    function xlt(string $s): string
    {
        return $s;
    }
}

if (!function_exists('convert_safe_file_dir_name')) {
    function convert_safe_file_dir_name(string $s): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $s);
    }
}

if (!function_exists('addPnote')) {
    /** Records the note and returns a stable fake id. */
    function addPnote(
        int|string $pid,
        string $newtext,
        int $authorized = 0,
        int $activity = 1,
        string $title = 'Unassigned',
        string $assigned_to = '',
        string $datetime = '',
        string $message_status = 'New',
        string $background_user = ''
    ): int {
        ClaimRevStubState::$pnotes[] = [
            'pid' => $pid,
            'text' => $newtext,
            'title' => $title,
            'assigned_to' => $assigned_to,
            'message_status' => $message_status,
            'background_user' => $background_user,
        ];
        return count(ClaimRevStubState::$pnotes);
    }
}
```

- [ ] **Step 2: Create the four class stubs**

`tests/Stubs/OpenEMR/Core/OEGlobalsBag.php` — behaviour identical to the phase-1 version, including the Symfony-faithful `getString()`:

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Core;

/**
 * Doubles OpenEMR\Core\OEGlobalsBag, which extends Symfony's ParameterBag.
 *
 * Stateless by design: every read goes through ClaimRevStubState::$globals,
 * so ClaimRevStubState::reset() is sufficient. Do not add instance state
 * without updating reset().
 */
class OEGlobalsBag
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return \ClaimRevStubState::$globals[$key] ?? $default;
    }

    /** Mirrors ParameterBag::getString — casts scalars, throws otherwise. */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \UnexpectedValueException(
                sprintf('Parameter value "%s" cannot be converted to "string".', $key)
            );
        }
        return (string) $value;
    }
}
```

`tests/Stubs/OpenEMR/BC/ServiceContainer.php`:

```php
<?php

declare(strict_types=1);

namespace OpenEMR\BC;

class ServiceContainer
{
    public static function getLogger(): \Psr\Log\LoggerInterface
    {
        return new \ClaimRevStubLogger();
    }
}
```

`tests/Stubs/OpenEMR/Common/Database/QueryUtils.php` — queue-driven, and records every query so tests can assert what was asked:

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Common\Database;

/**
 * Doubles OpenEMR\Common\Database\QueryUtils.
 *
 * Every method records its SQL and binds to ClaimRevStubState::$queries and
 * returns the next value from ClaimRevStubState::$queryResults, falling back
 * to an empty default when the queue is dry. Tests seed the queue in call
 * order; a test that does not care about a read can leave the queue empty.
 */
class QueryUtils
{
    /** @param array<int|string, mixed> $binds */
    private static function record(string $sql, array $binds): void
    {
        \ClaimRevStubState::$queries[] = ['sql' => $sql, 'binds' => $binds];
    }

    /**
     * @param array<int|string, mixed> $binds
     * @return array<string, mixed>|false
     */
    public static function querySingleRow(string $sql, array $binds = []): array|false
    {
        self::record($sql, $binds);
        /** @var array<string, mixed>|false */
        return \ClaimRevStubState::nextQueryResult(false);
    }

    /**
     * @param array<int|string, mixed> $binds
     * @return list<array<string, mixed>>
     */
    public static function fetchRecords(string $sql, array $binds = []): array
    {
        self::record($sql, $binds);
        /** @var list<array<string, mixed>> */
        return \ClaimRevStubState::nextQueryResult([]);
    }

    /** @param array<int|string, mixed> $binds */
    public static function fetchSingleValue(string $sql, string $column, array $binds = []): mixed
    {
        self::record($sql, $binds);
        return \ClaimRevStubState::nextQueryResult(null);
    }

    /** @param array<int|string, mixed> $binds */
    public static function sqlInsert(string $sql, array $binds = []): int
    {
        self::record($sql, $binds);
        $next = \ClaimRevStubState::nextQueryResult(1);
        return is_int($next) ? $next : 1;
    }

    /** @param array<int|string, mixed> $binds */
    public static function sqlStatementThrowException(string $sql, array $binds = []): mixed
    {
        self::record($sql, $binds);
        return \ClaimRevStubState::nextQueryResult(true);
    }

    /**
     * @return list<string>
     */
    public static function listTableFields(string $table): array
    {
        self::record('LIST FIELDS ' . $table, []);
        /** @var list<string> */
        return \ClaimRevStubState::nextQueryResult([]);
    }
}
```

`tests/Stubs/OpenEMR/Billing/BillingProcessor/X12RemoteTracker.php`:

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Billing\BillingProcessor;

/**
 * Doubles the X12 remote tracker ClaimUpload uses to read waiting claim
 * files and record their outcome.
 */
class X12RemoteTracker
{
    /** @return list<array<string, mixed>> */
    public function fetchByStatus(string $status): array
    {
        return \ClaimRevStubState::$x12Rows;
    }

    /** @param array<string, mixed> $row */
    public function update(array $row): void
    {
        \ClaimRevStubState::$x12Updates[] = $row;
    }
}
```

- [ ] **Step 3: Create the manifest `tests/Stubs/load.php`**

```php
<?php

/**
 * Loads every stub in dependency order. support.php must come first: the
 * class stubs read ClaimRevStubState, and ServiceContainer returns
 * ClaimRevStubLogger.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/OpenEMR/Core/OEGlobalsBag.php';
require_once __DIR__ . '/OpenEMR/BC/ServiceContainer.php';
require_once __DIR__ . '/OpenEMR/Common/Database/QueryUtils.php';
require_once __DIR__ . '/OpenEMR/Billing/BillingProcessor/X12RemoteTracker.php';
```

- [ ] **Step 4: Point the bootstrap at the manifest and delete the old file**

Replace `tests/bootstrap.php` with:

```php
<?php

/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader, then hand-written stubs for the slice of
 * OpenEMR core the module touches. OpenEMR is not installable as a dev
 * dependency, so unit tests run against these doubles instead. The stubs are
 * not PSR-4 autoloadable (they live in OpenEMR's namespaces, not the
 * module's), so they must be required before any module class that
 * references them loads.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Stubs/load.php';
```

Then: `git rm tests/Stubs/openemr-classes.php`

- [ ] **Step 5: Verify the existing suite still passes unchanged**

Run: `vendor/bin/phpunit --testdox`

Expected: PASS, 17 tests, 37 assertions — the same as before. This task must not change a single test outcome; it only moves and extends stubs. **If any phase-1 test fails, stop and report** — something behavioural changed in the move.

- [ ] **Step 6: Commit**

```bash
git add tests/
git commit -m "test: split the stub layer per class and add QueryUtils, X12RemoteTracker, globals

Phase 1 recorded the split as a phase 2 prerequisite: one file was fine for
four classes, not for the dozen phase 2 needs. Paths now mirror the real
OpenEMR locations, which is what made the phase-1 fidelity audit tractable."
```

---

### Task 2: Cover the pure functions that need no seam

Eight already-public, side-effect-free functions can be tested today. This is the best coverage-per-line in either phase, and it is independent of every conversion that follows.

**Files:**
- Create: `tests/Unit/PureFunctionsTest.php`

- [ ] **Step 1: Read the functions under test**

Read each before writing assertions — do not guess behaviour:
- `src/PaymentAdvicePage.php` → `normalizeAdvice()`
- `src/PaymentAdvicePostingService.php` → `parsePatientControlNumber()`, `buildIdempotencyReference()`, `getClaimStatusLabel()`, `sumServiceAmounts()`
- `src/ClaimTrackingService.php` → `parsePcn()`
- `src/ReconciliationService.php` → `computeDiscrepancy()`
- `src/NotificationPollService.php` → `htmlToPlainText()`

- [ ] **Step 2: Write the test class**

Create `tests/Unit/PureFunctionsTest.php`. Write **at least two cases per function** — one ordinary input and one edge case (empty, malformed, or boundary). The skeleton below shows the required shape and three worked examples; fill in the rest from what you read in Step 1, asserting **actual** behaviour.

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use OpenEMR\Modules\ClaimRevConnector\ClaimTrackingService;
use OpenEMR\Modules\ClaimRevConnector\NotificationPollService;
use OpenEMR\Modules\ClaimRevConnector\PaymentAdvicePostingService;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the module's side-effect-free helpers.
 *
 * These need no DI seam and no stubs — they are pure functions that were
 * simply never tested. Grouped in one class because individually they are
 * too small to justify a file each.
 */
final class PureFunctionsTest extends TestCase
{
    public function testParsePatientControlNumberSplitsPidAndEncounter(): void
    {
        self::assertSame(
            ['pid' => 42, 'encounter' => 7],
            PaymentAdvicePostingService::parsePatientControlNumber('42-7'),
        );
    }

    public function testParsePatientControlNumberRejectsMalformedInput(): void
    {
        // Assert whatever the real implementation does for a PCN with no
        // separator — read it first, then pin it here.
        self::assertSame([], PaymentAdvicePostingService::parsePatientControlNumber('nonsense'));
    }

    public function testHtmlToPlainTextPreservesParagraphBreaks(): void
    {
        $html = '<p>First para</p><p>Second para</p>';

        self::assertSame("First para\n\nSecond para", NotificationPollService::htmlToPlainText($html));
    }

    public function testHtmlToPlainTextStripsScriptAndStyleContent(): void
    {
        $html = '<style>.x{color:red}</style><script>alert(1)</script><p>Visible</p>';

        $text = NotificationPollService::htmlToPlainText($html);

        self::assertStringNotContainsString('color:red', $text);
        self::assertStringNotContainsString('alert(1)', $text);
        self::assertStringContainsString('Visible', $text);
    }

    public function testParsePcnSplitsPidAndEncounter(): void
    {
        self::assertSame(['pid' => 42, 'encounter' => 7], ClaimTrackingService::parsePcn('42-7'));
    }

    // Add, following the same shape and asserting real behaviour:
    // - normalizeAdvice(): a full entry maps every field; a sparse entry
    //   falls back to defaults without notices.
    // - buildIdempotencyReference(): stable for identical input, different
    //   for different input.
    // - getClaimStatusLabel(): a known status id, and an unknown one.
    // - sumServiceAmounts(): a normal list, and an empty list.
    // - computeDiscrepancy(): at least one discrepancy case and one clean case.
}
```

**If any assertion above does not match the real implementation, change the assertion, not the implementation.** These functions are in production use; the tests document what they do, and a mismatch is a finding to report, not a bug to fix here.

- [ ] **Step 3: Run and iterate until green**

Run: `vendor/bin/phpunit tests/Unit/PureFunctionsTest.php --testdox`

Expected: all pass. Report any case where real behaviour surprised you.

- [ ] **Step 4: Run the whole suite**

Run: `vendor/bin/phpunit`

Expected: 17 phase-1 tests plus yours, zero failures.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/PureFunctionsTest.php
git commit -m "test: cover the module's untested pure helpers

Eight side-effect-free functions that need no DI seam and no stubs."
```

---

### Task 3: Convert `ClaimsPage`

Two of its four public methods touch `ClaimRevApi` directly. `searchClaims()` and `getClaimByObjectId()` route through the already-converted `ClaimSearch` — **do not touch them.** Converting them would add a layer with no seam gap to close.

Note the two methods need **different wrapper shapes**: `exportCsv()` has no catch today (Shape A), `getClaimStatuses()` catches `ClaimRevException` (Shape B).

**Files:**
- Modify: `src/ClaimsPage.php`
- Test: `tests/Unit/ClaimsPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\ClaimsPage;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ClaimsPageTest extends TestCase
{
    public function testExportClaimsCsvPostsTheSearchModelAndReturnsTheFile(): void
    {
        $factory = MockApiFactory::withJson(['fileText' => 'a,b,c', 'fileName' => 'claims.csv']);

        $result = (new ClaimsPage($factory->api))->exportClaimsCsv(['patLastName' => 'Sharp']);

        self::assertSame('/api/ClaimView/v1/SearchClaimsCsv', $factory->requestTarget());
        self::assertSame('a,b,c', $result['fileText']);
        self::assertSame('claims.csv', $result['fileName']);
    }

    public function testExportClaimsCsvSendsTheMappedFilters(): void
    {
        $factory = MockApiFactory::withJson(['fileText' => '', 'fileName' => 'x.csv']);

        (new ClaimsPage($factory->api))->exportClaimsCsv([
            'patLastName' => 'Sharp',
            'payerNumber' => '99999',
        ]);

        $body = $factory->requestBody();
        self::assertSame('Sharp', $body['patientLastName']);
        self::assertSame('99999', $body['payerNumber']);
    }

    public function testExportClaimsCsvLetsApiFailuresPropagate(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        $this->expectException(ClaimRevApiException::class);

        (new ClaimsPage($factory->api))->exportClaimsCsv([]);
    }

    public function testFetchClaimStatusesReturnsTheStatusList(): void
    {
        $factory = MockApiFactory::withJson([
            ['listItemId' => '1', 'listName' => 'Accepted'],
            ['listItemId' => '2', 'listName' => 'Rejected'],
        ]);

        $result = (new ClaimsPage($factory->api))->fetchClaimStatuses();

        self::assertSame('/api/ClaimView/v1/GetClaimStatuses', $factory->requestTarget());
        self::assertCount(2, $result);
        self::assertSame('Accepted', $result[0]['listName']);
    }

    public function testFetchClaimStatusesLetsApiFailuresPropagateToTheWrapper(): void
    {
        // The instance method throws; the static wrapper is what converts a
        // failure into an empty list, preserving long-standing behaviour.
        $factory = new MockApiFactory([new Response(503, [], 'down')]);

        $this->expectException(ClaimRevApiException::class);

        (new ClaimsPage($factory->api))->fetchClaimStatuses();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ClaimsPageTest.php --testdox`

Expected: FAIL — `ArgumentCountError` on `ClaimsPage::__construct()`.

- [ ] **Step 3: Modify `src/ClaimsPage.php`**

Add the constructor at the top of the class body:

```php
    public function __construct(private readonly ClaimRevApi $api)
    {
    }
```

Replace `exportCsv()` with the Shape A wrapper plus its instance method:

```php
    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * Deliberately has no catch: failures propagate to the caller, which is
     * public/claim_export_csv.php's own try/catch. Adding one here would
     * change what that endpoint sees.
     *
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    public static function exportCsv(array $postData): array
    {
        return (new self(ClaimRevApi::makeFromGlobals()))->exportClaimsCsv($postData);
    }

    /**
     * Export the current claims search as CSV.
     *
     * @param array<string, mixed> $postData
     * @return array<string, mixed> Contains fileText and fileName
     * @throws ClaimRevApiException on API error
     */
    public function exportClaimsCsv(array $postData): array
    {
        $model = self::buildSearchModel($postData, 0, 0);
        return $this->api->searchClaimsCsv($model);
    }
```

Replace `getClaimStatuses()` with the Shape B wrapper plus its instance method:

```php
    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * Returns an empty list on any ClaimRevException. This catch is broader
     * than the module's current convention would choose, but it is
     * long-standing behaviour that public/claims.php relies on for its status
     * dropdown, so it is preserved verbatim rather than narrowed here.
     *
     * @return list<array<string, mixed>>
     */
    public static function getClaimStatuses(): array
    {
        try {
            return (new self(ClaimRevApi::makeFromGlobals()))->fetchClaimStatuses();
        } catch (ClaimRevException) {
            return [];
        }
    }

    /**
     * Fetch the available claim statuses.
     *
     * @return list<array<string, mixed>>
     * @throws ClaimRevApiException on API error
     */
    public function fetchClaimStatuses(): array
    {
        return $this->api->getClaimStatuses();
    }
```

Leave `searchClaims()`, `getClaimByObjectId()`, `buildSearchModel()`, `nonEmptyString()`, and `nonEmptyFloat()` exactly as they are.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ClaimsPageTest.php --testdox`

Expected: PASS, 5 tests.

- [ ] **Step 5: Confirm no call site broke**

Run: `grep -rn "ClaimsPage::" --include=*.php src public templates`

Expected: only `searchClaims`, `exportCsv`, `getClaimByObjectId`, `getClaimStatuses`, all with unchanged signatures.

- [ ] **Step 6: Run the whole suite, then commit**

```bash
vendor/bin/phpunit
git add src/ClaimsPage.php tests/Unit/ClaimsPageTest.php
git commit -m "refactor: inject ClaimRevApi into ClaimsPage's two API methods (#24)

searchClaims() and getClaimByObjectId() are deliberately untouched: they
route through the already-converted ClaimSearch, so there is no seam gap."
```

---

### Task 4: Convert `PaymentAdvicePage`

Both convertible methods are Shape A — **neither has a catch today**, and neither call site has one either, so their wrappers must have none.

**Files:**
- Modify: `src/PaymentAdvicePage.php`
- Test: `tests/Unit/PaymentAdvicePageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\PaymentAdvicePage;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class PaymentAdvicePageTest extends TestCase
{
    public function testFetchPaymentInfoPostsToTheSearchEndpoint(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        $result = (new PaymentAdvicePage($factory->api))->fetchPaymentInfo(['checkNumber' => 'CHK-1']);

        self::assertSame('/api/PaymentAdvice/v1/SearchPaymentInfo', $factory->requestTarget());
        self::assertSame('CHK-1', $factory->requestBody()['checkNumber']);
        self::assertSame(0, $result['totalRecords']);
    }

    public function testFetchPaymentAdviceByIdReturnsTheMatchingEntry(): void
    {
        $factory = MockApiFactory::withJson([
            'results' => [['paymentAdviceId' => 'pa-1', 'paymentInfo' => []]],
            'totalRecords' => 1,
        ]);

        $result = (new PaymentAdvicePage($factory->api))->fetchPaymentAdviceById('pa-1');

        self::assertNotNull($result);
        self::assertSame('pa-1', $result['paymentAdviceId']);
    }

    public function testFetchPaymentAdviceByIdRejectsAMismatchedRow(): void
    {
        // Defensive re-check: the server filter is authoritative, but a bug
        // there must never leak a different advice through.
        $factory = MockApiFactory::withJson([
            'results' => [['paymentAdviceId' => 'SOMETHING-ELSE', 'paymentInfo' => []]],
            'totalRecords' => 1,
        ]);

        self::assertNull((new PaymentAdvicePage($factory->api))->fetchPaymentAdviceById('pa-1'));
    }

    public function testFetchPaymentAdviceByIdShortCircuitsOnAnEmptyId(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        self::assertNull((new PaymentAdvicePage($factory->api))->fetchPaymentAdviceById(''));
        self::assertSame(0, $factory->requestCount(), 'An empty id must not reach the API');
    }

    public function testInstanceMethodsLetApiFailuresPropagate(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        $this->expectException(ClaimRevApiException::class);

        (new PaymentAdvicePage($factory->api))->fetchPaymentInfo([]);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/PaymentAdvicePageTest.php --testdox`

Expected: FAIL — `ArgumentCountError` on `PaymentAdvicePage::__construct()`.

- [ ] **Step 3: Modify `src/PaymentAdvicePage.php`**

Add the constructor at the top of the class body:

```php
    public function __construct(private readonly ClaimRevApi $api)
    {
    }
```

Convert `searchPaymentInfo()` to a Shape A wrapper delegating to `fetchPaymentInfo()`, and `getPaymentAdviceById()` to a Shape A wrapper delegating to `fetchPaymentAdviceById()`. Move each method's existing body verbatim into the instance method, changing only `$api = ClaimRevApi::makeFromGlobals();` plus `$api->...` into `$this->api->...`. **Add no try/catch to either wrapper.**

```php
    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * No catch by design: public/payment_advice.php owns the error handling
     * for this path, and adding one here would hide failures from it.
     *
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    public static function searchPaymentInfo(array $postData): array
    {
        return (new self(ClaimRevApi::makeFromGlobals()))->fetchPaymentInfo($postData);
    }

    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * No catch by design, matching today's behaviour.
     *
     * @return array<string, mixed>|null
     */
    public static function getPaymentAdviceById(string $paymentAdviceId): ?array
    {
        return (new self(ClaimRevApi::makeFromGlobals()))->fetchPaymentAdviceById($paymentAdviceId);
    }
```

Leave `normalizeAdvice()` and `getOpenEmrClaimStatus()` untouched — neither calls `ClaimRevApi`.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/PaymentAdvicePageTest.php --testdox`

Expected: PASS, 5 tests.

- [ ] **Step 5: Confirm no call site broke**

Run: `grep -rn "PaymentAdvicePage::" --include=*.php src public templates`

Expected: `searchPaymentInfo`, `getPaymentAdviceById`, `normalizeAdvice`, `getOpenEmrClaimStatus`, all unchanged.

- [ ] **Step 6: Run the whole suite, then commit**

```bash
vendor/bin/phpunit
git add src/PaymentAdvicePage.php tests/Unit/PaymentAdvicePageTest.php
git commit -m "refactor: inject ClaimRevApi into PaymentAdvicePage (#24)"
```

---

### Task 5: Convert `ClaimTrackingService`

Both methods are Shape B: their existing try blocks wrap `makeFromGlobals()`, so construction must stay inside a try or an unconfigured module escapes where it previously returned a value. Both catch the `ClaimRevException` base class — **preserve verbatim.**

**Files:**
- Modify: `src/ClaimTrackingService.php`
- Test: `tests/Unit/ClaimTrackingServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use ClaimRevStubState;
use OpenEMR\Modules\ClaimRevConnector\ClaimTrackingService;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ClaimTrackingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        ClaimRevStubState::reset();
    }

    protected function tearDown(): void
    {
        ClaimRevStubState::reset();
    }

    public function testSyncBatchViaApiSendsEveryPcnInOneSearch(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        (new ClaimTrackingService($factory->api))->syncBatchViaApi(['1-1', '2-2']);

        self::assertSame('/api/ClaimView/v1/SearchClaimsPaged', $factory->requestTarget());
        self::assertSame(['1-1', '2-2'], $factory->requestBody()['patientControlNumbers']);
    }

    public function testSyncBatchViaApiShortCircuitsOnAnEmptyPcnList(): void
    {
        $factory = MockApiFactory::withJson(['results' => []]);

        $summary = (new ClaimTrackingService($factory->api))->syncBatchViaApi([]);

        self::assertSame(0, $factory->requestCount(), 'An empty list must not reach the API');
        self::assertSame(0, $summary['synced']);
        self::assertSame(0, $summary['errors']);
    }

    public function testBatchSyncReportsEveryPcnAsFailedWhenTheApiIsDown(): void
    {
        // The static wrapper owns this path: construction succeeds, the
        // instance call fails, and every requested PCN comes back tagged.
        $factory = new MockApiFactory([new \GuzzleHttp\Psr7\Response(500, [], 'boom')]);

        $summary = (new ClaimTrackingService($factory->api))->syncBatchViaApi(['1-1', '2-2']);

        self::assertSame(2, $summary['errors']);
        self::assertCount(2, $summary['results']);
        self::assertFalse($summary['results'][0]['success']);
        self::assertSame('ClaimRev connection failed', $summary['results'][0]['message']);
    }
}
```

**Note on the third test:** it assumes the instance method keeps the existing `catch (ClaimRevException)` internally. Verify that against the code you write in Step 3; if the catch ends up only in the static wrapper, move this assertion to match reality and say so in your report.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ClaimTrackingServiceTest.php --testdox`

Expected: FAIL — `ArgumentCountError` on `ClaimTrackingService::__construct()`.

- [ ] **Step 3: Modify `src/ClaimTrackingService.php`**

Add the constructor at the top of the class body:

```php
    public function __construct(private readonly ClaimRevApi $api)
    {
    }
```

For **`checkStatus276()`**: keep the static method's name and signature. Move the entire body into a new instance method `checkClaimStatusViaApi(int $pid, int $encounter, int $payerType): array`, changing only `$api = ClaimRevApi::makeFromGlobals();` to nothing and `$api->searchClaims($model)` to `$this->api->searchClaims($model)`. Keep the method's internal `try`/`catch (ClaimRevException)` exactly as it is. Then make the static:

```php
    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * Construction happens inside the try because makeFromGlobals() itself
     * can throw, and the original code caught that here.
     *
     * @return array<string, mixed>
     */
    public static function checkStatus276(int $pid, int $encounter, int $payerType): array
    {
        try {
            $service = new self(ClaimRevApi::makeFromGlobals());
        } catch (ClaimRevException) {
            return ['success' => false, 'message' => 'Failed to connect to ClaimRev', 'statusData' => []];
        }
        return $service->checkClaimStatusViaApi($pid, $encounter, $payerType);
    }
```

For **`batchSyncFromClaimRev()`**: same treatment. Instance method `syncBatchViaApi(array $pcns): array` holds the body verbatim (including its own `try`/`catch (ClaimRevException)` that tags every PCN as failed); the static becomes:

```php
    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * @param list<string> $pcns
     * @return array<string, mixed>
     */
    public static function batchSyncFromClaimRev(array $pcns): array
    {
        $summary = ['synced' => 0, 'errors' => 0, 'notFound' => 0, 'results' => []];
        if ($pcns === []) {
            return $summary;
        }
        try {
            $service = new self(ClaimRevApi::makeFromGlobals());
        } catch (ClaimRevException) {
            $summary['errors'] = count($pcns);
            foreach ($pcns as $pcn) {
                $summary['results'][] = ['pcn' => $pcn, 'success' => false, 'message' => 'ClaimRev connection failed'];
            }
            return $summary;
        }
        return $service->syncBatchViaApi($pcns);
    }
```

Leave every other method in the class untouched.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ClaimTrackingServiceTest.php --testdox`

Expected: PASS, 3 tests.

- [ ] **Step 5: Confirm no call site broke, then commit**

```bash
grep -rn "ClaimTrackingService::" --include=*.php src public
vendor/bin/phpunit
git add src/ClaimTrackingService.php tests/Unit/ClaimTrackingServiceTest.php
git commit -m "refactor: inject ClaimRevApi into ClaimTrackingService's API methods (#24)"
```

---

### Task 6: Convert `PaymentAdvicePostingService::markWorkedOnClaimRev` — and nothing else

**Scope discipline is the whole point of this task.** This file writes to OpenEMR billing tables. Exactly one private method touches `ClaimRevApi`, it runs last in the posting pipeline, and it already swallows failures by design so a successful OpenEMR post is never rolled back over a ClaimRev notification.

**Do not touch** `post()`, `postWithinLock()`, `preview()`, `isAlreadyPosted()`, `getPostingDetails()`, or anything involving `SLEOB`, `BillingUtilities`, `InvoiceSummary`, or `GET_LOCK`. None of them call `ClaimRevApi`, so there is no seam reason to go near them, and doing so would add financial-write risk for zero gain.

**Files:**
- Modify: `src/PaymentAdvicePostingService.php`
- Test: `tests/Unit/PaymentAdvicePostingServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use OpenEMR\Modules\ClaimRevConnector\PaymentAdvicePostingService;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class PaymentAdvicePostingServiceTest extends TestCase
{
    public function testNotifyClaimRevWorkedPostsTheAdviceToTheToggleEndpoint(): void
    {
        $factory = MockApiFactory::withJson([]);

        (new PaymentAdvicePostingService($factory->api))
            ->notifyClaimRevWorked(['paymentAdviceId' => 'pa-1', 'paymentInfo' => ['isWorked' => false]]);

        self::assertSame('/api/PaymentAdvice/v1/UpdateClaimPaymentAdviceIsWorked', $factory->requestTarget());
        self::assertSame('pa-1', $factory->requestBody()['paymentAdviceId']);
    }

    public function testNotifyClaimRevWorkedSendsTheWholeAdvicePayload(): void
    {
        $factory = MockApiFactory::withJson([]);
        $payload = ['paymentAdviceId' => 'pa-2', 'paymentInfo' => ['isWorked' => false, 'checkNumber' => 'CHK-9']];

        (new PaymentAdvicePostingService($factory->api))->notifyClaimRevWorked($payload);

        self::assertSame('CHK-9', $factory->requestBody()['paymentInfo']['checkNumber']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/PaymentAdvicePostingServiceTest.php --testdox`

Expected: FAIL — `ArgumentCountError` on `PaymentAdvicePostingService::__construct()`.

- [ ] **Step 3: Modify `src/PaymentAdvicePostingService.php`**

Add the constructor at the top of the class body:

```php
    public function __construct(private readonly ClaimRevApi $api)
    {
    }
```

Replace `markWorkedOnClaimRev()` with the Shape C wrapper plus a new instance method. The `isWorked` guard stays **before** the try, exactly where it is now:

```php
    /**
     * Tell ClaimRev this advice has been worked.
     *
     * The API toggles isWorked, so we only call it when currently unworked.
     * Best-effort by design: the OpenEMR posting has already succeeded by the
     * time this runs, and must not be failed over a notification error.
     *
     * @param array<string, mixed> $paymentData The full ClaimPaymentAggregation
     */
    private static function markWorkedOnClaimRev(array $paymentData): void
    {
        $paymentInfo = is_array($paymentData['paymentInfo'] ?? null) ? $paymentData['paymentInfo'] : [];
        if (TypeCoerce::asBool($paymentInfo['isWorked'] ?? false)) {
            // Already marked as worked, don't toggle it back
            return;
        }

        try {
            (new self(ClaimRevApi::makeFromGlobals()))->notifyClaimRevWorked($paymentData);
        } catch (ClaimRevException) {
            // Best-effort: OpenEMR posting already succeeded, don't fail over this
        }
    }

    /**
     * Send the worked-toggle to ClaimRev.
     *
     * @param array<string, mixed> $paymentData
     * @throws ClaimRevApiException on API error
     */
    public function notifyClaimRevWorked(array $paymentData): void
    {
        $this->api->markPaymentAdviceWorked($paymentData);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/PaymentAdvicePostingServiceTest.php --testdox`

Expected: PASS, 2 tests.

- [ ] **Step 5: Prove the billing code is untouched**

Run: `git diff --stat src/PaymentAdvicePostingService.php`

Expected: a small diff. Then run:

`git diff src/PaymentAdvicePostingService.php | grep -E "^[+-]" | grep -iE "SLEOB|BillingUtilities|InvoiceSummary|GET_LOCK|RELEASE_LOCK|arPost|updateClaim|isAlreadyPosted"`

**Expected: no output.** If this prints anything, you have touched money-moving code — stop and report immediately.

- [ ] **Step 6: Run the whole suite, then commit**

```bash
vendor/bin/phpunit
git add src/PaymentAdvicePostingService.php tests/Unit/PaymentAdvicePostingServiceTest.php
git commit -m "refactor: inject ClaimRevApi into PaymentAdvicePostingService's notify step (#24)

Only markWorkedOnClaimRev touches the API. The posting pipeline, its lock,
and its idempotency guard are deliberately untouched."
```

---

### Task 7: Extract `ReconciliationService::lookupClaimRev`

**Only the private helper is converted.** `reconcile()` stays a static method. Its `try { ... } catch (ClaimRevException) { $claimRevLookupFailed = true; }` is load-bearing: when ClaimRev is unreachable the page still shows real OpenEMR encounter data behind a warning banner. Because `lookupClaimRev()` is called from inside that try, moving `makeFromGlobals()` into the helper's delegation keeps it covered.

**Files:**
- Modify: `src/ReconciliationService.php`
- Test: `tests/Unit/ReconciliationServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\ReconciliationService;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ReconciliationServiceTest extends TestCase
{
    public function testFetchClaimsByPcnsSendsEveryPcnAndPagesToMatch(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        (new ReconciliationService($factory->api))->fetchClaimsByPcns(['1-1', '2-2', '3-3']);

        $body = $factory->requestBody();
        self::assertSame('/api/ClaimView/v1/SearchClaimsPaged', $factory->requestTarget());
        self::assertSame(['1-1', '2-2', '3-3'], $body['patientControlNumbers']);
        self::assertSame(3, $body['pagingSearch']['pageSize'], 'Page size must cover every requested PCN');
    }

    public function testFetchClaimsByPcnsReturnsOnlyArrayEntries(): void
    {
        $factory = MockApiFactory::withJson([
            'results' => [['patientControlNumber' => '1-1'], 'not-an-array', ['patientControlNumber' => '2-2']],
        ]);

        $result = (new ReconciliationService($factory->api))->fetchClaimsByPcns(['1-1', '2-2']);

        self::assertCount(2, $result, 'Non-array entries must be filtered out');
        self::assertSame('1-1', $result[0]['patientControlNumber']);
    }

    public function testFetchClaimsByPcnsReturnsEmptyWhenResultsIsNotAnArray(): void
    {
        $factory = MockApiFactory::withJson(['results' => 'unexpected']);

        self::assertSame([], (new ReconciliationService($factory->api))->fetchClaimsByPcns(['1-1']));
    }

    public function testFetchClaimsByPcnsLetsApiFailuresPropagate(): void
    {
        // reconcile()'s own catch converts this into the partial-results
        // banner; the instance method itself must not swallow it.
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        $this->expectException(ClaimRevApiException::class);

        (new ReconciliationService($factory->api))->fetchClaimsByPcns(['1-1']);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ReconciliationServiceTest.php --testdox`

Expected: FAIL — `ArgumentCountError` on `ReconciliationService::__construct()`.

- [ ] **Step 3: Modify `src/ReconciliationService.php`**

Add the constructor at the top of the class body:

```php
    public function __construct(private readonly ClaimRevApi $api)
    {
    }
```

Replace `lookupClaimRev()` with a Shape C wrapper plus the instance method holding its body:

```php
    /**
     * Resolve the API client from globals and delegate.
     *
     * Called from inside reconcile()'s try/catch, which turns any
     * ClaimRevException into the partial-results warning banner. Keeping
     * makeFromGlobals() here preserves that: an unconfigured or unreachable
     * ClaimRev still yields OpenEMR-only results rather than an error page.
     *
     * @param list<string> $pcns
     * @return list<array<string, mixed>> ClaimRev claim results
     */
    private static function lookupClaimRev(array $pcns): array
    {
        if ($pcns === []) {
            return [];
        }

        return (new self(ClaimRevApi::makeFromGlobals()))->fetchClaimsByPcns($pcns);
    }

    /**
     * Fetch ClaimRev claims for a batch of patient control numbers.
     *
     * @param list<string> $pcns
     * @return list<array<string, mixed>>
     * @throws ClaimRevApiException on API error
     */
    public function fetchClaimsByPcns(array $pcns): array
    {
        $model = new ClaimSearchModel();
        $model->patientControlNumbers = $pcns;
        $model->pagingSearch->pageSize = count($pcns);
        $model->pagingSearch->pageIndex = 0;

        $result = $this->api->searchClaims($model);
        $results = $result['results'] ?? [];
        if (!is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $r) {
            if (is_array($r)) {
                /** @var array<string, mixed> $rTyped */
                $rTyped = $r;
                $out[] = $rTyped;
            }
        }
        return $out;
    }
```

**Do not modify `reconcile()`.** Confirm with `git diff src/ReconciliationService.php` that its body is unchanged apart from nothing at all.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ReconciliationServiceTest.php --testdox`

Expected: PASS, 4 tests.

- [ ] **Step 5: Prove `reconcile()` is untouched**

Run: `git diff src/ReconciliationService.php | grep -E "^[+-].*(claimRevLookupFailed|fetchRecords|fetchSingleValue|upsertClaimRecord)"`

**Expected: no output.**

- [ ] **Step 6: Run the whole suite, then commit**

```bash
vendor/bin/phpunit
git add src/ReconciliationService.php tests/Unit/ReconciliationServiceTest.php
git commit -m "refactor: extract ReconciliationService's ClaimRev lookup behind the seam (#24)

Only the private lookupClaimRev helper is converted. reconcile() keeps its
catch, which is what lets the page show OpenEMR-only results with a warning
banner when ClaimRev is unreachable."
```

---

### Task 8: Convert `NotificationPollService`

Shape B: the existing `try { $api = ClaimRevApi::makeFromGlobals(); } catch (ClaimRevException) { return; }` wraps construction, so the wrapper must too. This is a background service — an escaping exception hits a cron tick, so preserving the swallow matters.

**Files:**
- Modify: `src/NotificationPollService.php`
- Test: `tests/Unit/NotificationPollServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use ClaimRevStubState;
use OpenEMR\Modules\ClaimRevConnector\NotificationPollService;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class NotificationPollServiceTest extends TestCase
{
    protected function setUp(): void
    {
        ClaimRevStubState::reset();
        ClaimRevStubState::$globals['oe_claimrev_notification_recipient'] = 'admin';
    }

    protected function tearDown(): void
    {
        ClaimRevStubState::reset();
    }

    public function testPollNotificationsRequestsUnreadNotifications(): void
    {
        $factory = MockApiFactory::withJson([]);

        (new NotificationPollService($factory->api))->pollNotifications(['admin']);

        self::assertStringContainsString('/api/NotificationMgmt/v1/GetPortalNotifications', $factory->requestTarget());
    }

    public function testPollNotificationsCreatesAPnoteForEachRecipient(): void
    {
        $factory = MockApiFactory::withJson([
            ['portalNotificationId' => 11, 'messageTitle' => 'Payer outage', 'messageBodyText' => 'Details here'],
        ]);
        // querySingleRow returns false → not previously delivered.
        ClaimRevStubState::$queryResults = [false];

        (new NotificationPollService($factory->api))->pollNotifications(['admin', 'biller1']);

        self::assertCount(2, ClaimRevStubState::$pnotes);
        self::assertSame('admin', ClaimRevStubState::$pnotes[0]['assigned_to']);
        self::assertSame('biller1', ClaimRevStubState::$pnotes[1]['assigned_to']);
        self::assertStringContainsString('Payer outage', ClaimRevStubState::$pnotes[0]['text']);
    }

    public function testPollNotificationsSkipsAlreadyDeliveredNotifications(): void
    {
        $factory = MockApiFactory::withJson([
            ['portalNotificationId' => 11, 'messageTitle' => 'Seen already', 'messageBodyText' => 'x'],
        ]);
        // querySingleRow returns a row → already delivered.
        ClaimRevStubState::$queryResults = [['id' => 5]];

        (new NotificationPollService($factory->api))->pollNotifications(['admin']);

        self::assertSame([], ClaimRevStubState::$pnotes);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/NotificationPollServiceTest.php --testdox`

Expected: FAIL — `ArgumentCountError` on `NotificationPollService::__construct()`.

- [ ] **Step 3: Modify `src/NotificationPollService.php`**

Add the constructor. Split `run()` so the static keeps the enabled-check, the `require_once` of `pnotes.inc.php`, and the recipient parsing — none of which are API concerns — and the instance method takes the parsed recipients and does the API work plus the delivery loop:

```php
    public function __construct(private readonly ClaimRevApi $api)
    {
    }

    /**
     * Static entry point for the background service. Resolves the API client
     * from globals and delegates.
     *
     * Construction stays inside the try because makeFromGlobals() can throw
     * and the original code returned silently on that. This runs on a cron
     * tick, so an escaping exception would surface very differently.
     */
    public static function run(): void
    {
        require_once OEGlobalsBag::getInstance()->getString('fileroot') . "/library/pnotes.inc.php";

        $enabledRaw = OEGlobalsBag::getInstance()->get(GlobalConfig::CONFIG_ENABLE_NOTIFICATIONS) ?? '1';
        if (in_array($enabledRaw, [false, '', '0', 0], true)) {
            return;
        }

        $recipientSetting = OEGlobalsBag::getInstance()->getString(GlobalConfig::CONFIG_NOTIFICATION_RECIPIENT, 'admin');
        if ($recipientSetting === '') {
            $recipientSetting = 'admin';
        }
        $recipients = array_values(array_filter(array_map(trim(...), explode(';', $recipientSetting))));
        if ($recipients === []) {
            $recipients = ['admin'];
        }

        try {
            $service = new self(ClaimRevApi::makeFromGlobals());
        } catch (ClaimRevException) {
            return;
        }

        $service->pollNotifications($recipients);
    }

    /**
     * Fetch unread portal notifications and deliver them as pnotes.
     *
     * @param list<string> $recipients OpenEMR usernames to deliver to
     */
    public function pollNotifications(array $recipients): void
    {
        try {
            $notifications = $this->api->getPortalNotifications(false);
        } catch (ClaimRevException) {
            return;
        }

        // ... the existing foreach body verbatim, with $api->setNotificationReadStatus
        // becoming $this->api->setNotificationReadStatus ...
    }
```

Move the existing `foreach ($notifications as $notification) { ... }` block into `pollNotifications()` unchanged, including its inner `try { $api->setNotificationReadStatus(...) } catch (ClaimRevException) { }`. Leave `htmlToPlainText()` alone.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/NotificationPollServiceTest.php --testdox`

Expected: PASS, 4 tests. If a test fails because the stubbed `QueryUtils` queue drains in a different order than assumed, adjust the seeded `$queryResults` to match real call order and report what the real order is.

- [ ] **Step 5: Run the whole suite, then commit**

```bash
vendor/bin/phpunit
git add src/NotificationPollService.php tests/Unit/NotificationPollServiceTest.php
git commit -m "refactor: inject ClaimRevApi into NotificationPollService (#24)"
```

---

### Task 9: Convert `ClaimUpload`

Shape B, plus the same `BaseService` problem `ReportDownload` had in phase 1. `ClaimUpload extends BaseService` and declares `__construct()` calling `parent::__construct(self::TABLE_NAME)` — but every entry point is static and nothing ever instantiates it, so that constructor is dead code today. The real parent constructor queries the database and calls `OEGlobalsBag::getKernel()`, absent on OpenEMR 8.0.x.

**Files:**
- Modify: `src/ClaimUpload.php`
- Test: `tests/Unit/ClaimUploadTest.php`

- [ ] **Step 1: Confirm the inheritance is genuinely unused**

Run: `grep -rn "new ClaimUpload\|ClaimUpload::" --include=*.php src public`

and inspect the class for `$this->`, `parent::`, or inherited members:

`grep -nE '\$this->|parent::|self::\$' src/ClaimUpload.php`

**If anything other than `self::` references to its own static members or constants appears — in particular if `$this->validationMessages` or an inherited `BaseService` member is used — STOP and report.** Only proceed if the parent is genuinely unused.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use ClaimRevStubState;
use OpenEMR\Modules\ClaimRevConnector\ClaimUpload;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ClaimUploadTest extends TestCase
{
    private string $siteDir;

    protected function setUp(): void
    {
        ClaimRevStubState::reset();
        $this->siteDir = sys_get_temp_dir() . '/claimrev-upload-' . bin2hex(random_bytes(6));
        mkdir($this->siteDir . '/documents/edi', 0777, true);
        ClaimRevStubState::$globals['OE_SITE_DIR'] = $this->siteDir;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->siteDir . '/documents/edi/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->siteDir . '/documents/edi');
        @rmdir($this->siteDir . '/documents');
        @rmdir($this->siteDir);
        ClaimRevStubState::reset();
    }

    public function testUploadWaitingFilesSendsEachClaimFileAndMarksItSuccessful(): void
    {
        file_put_contents($this->siteDir . '/documents/edi/claim1.txt', 'ISA*00*CLAIM');
        ClaimRevStubState::$x12Rows = [[
            'x12_filename' => 'claim1.txt',
            'x12_sftp_local_dir' => $this->siteDir . '/documents/edi/',
            'status' => 'waiting',
        ]];
        $factory = MockApiFactory::withJson([]);

        (new ClaimUpload($factory->api))->uploadWaitingFiles();

        self::assertSame('/api/InputFile/v1', $factory->requestTarget());
        $statuses = array_column(ClaimRevStubState::$x12Updates, 'status');
        self::assertContains(ClaimUpload::STATUS_SUCCESS, $statuses);
    }

    public function testUploadWaitingFilesMarksTheRowWhenTheFileCannotBeRead(): void
    {
        ClaimRevStubState::$x12Rows = [[
            'x12_filename' => 'missing.txt',
            'x12_sftp_local_dir' => $this->siteDir . '/documents/edi/',
            'status' => 'waiting',
        ]];
        $factory = MockApiFactory::withJson([]);

        (new ClaimUpload($factory->api))->uploadWaitingFiles();

        self::assertSame(0, $factory->requestCount(), 'An unreadable file must not be uploaded');
        $statuses = array_column(ClaimRevStubState::$x12Updates, 'status');
        self::assertContains(ClaimUpload::STATUS_CLAIM_FILE_ERROR, $statuses);
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ClaimUploadTest.php --testdox`

Expected: FAIL — `ArgumentCountError`, or a `BaseService` class-not-found error.

- [ ] **Step 4: Modify `src/ClaimUpload.php`**

Remove `extends BaseService`, remove the `use OpenEMR\Services\BaseService;` import, and delete the existing dead `__construct()`. Add a class docblock explaining the removal, mirroring `ReportDownload`:

```php
/**
 * Uploads waiting X12 claim files to ClaimRev.
 *
 * Does not extend BaseService: nothing here used it — every entry point is
 * static and the class was never instantiated — and its constructor queries
 * the database and calls OEGlobalsBag::getKernel(), which does not exist on
 * OpenEMR 8.0.x.
 */
class ClaimUpload
{
    public function __construct(private readonly ClaimRevApi $api)
    {
    }
```

Keep every existing constant. Move the body of `sendWaitingFiles()` into a new instance method `uploadWaitingFiles(): void`, replacing `$api->uploadClaimFile(...)` with `$this->api->uploadClaimFile(...)`, and keeping the version check, the `X12RemoteTracker` usage, and the per-file status updates exactly as they are. The static becomes:

```php
    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * On an authentication failure every waiting file is marked with the
     * login-error status, exactly as before — construction must therefore
     * stay inside the try.
     */
    public static function sendWaitingFiles(): void
    {
        $check = ModuleVersionCheckService::check();
        if ($check !== null && $check->disabled) {
            ServiceContainer::getLogger()->warning(
                'ClaimRev disabled module-driven claim send by remote flag',
                ['version' => Bootstrap::MODULE_VERSION, 'reason' => $check->disableReason]
            );
            return;
        }

        try {
            $service = new self(ClaimRevApi::makeFromGlobals());
        } catch (ClaimRevAuthenticationException) {
            $remoteTracker = new X12RemoteTracker();
            foreach ($remoteTracker->fetchByStatus(self::STATUS_WAITING) as $x12_remote) {
                $x12_remote['status'] = self::STATUS_LOGIN_ERROR;
                $x12_remote['messages'] = 'Invalid Username or Password.';
                $remoteTracker->update($x12_remote);
            }
            return;
        }

        $service->uploadWaitingFiles();
    }
```

Note the version check moves into the static wrapper — it gates whether any work happens at all and is not an API concern. The instance method starts from `$remoteTracker = new X12RemoteTracker();`.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ClaimUploadTest.php --testdox`

Expected: PASS, 2 tests. `ModuleVersionCheckService::check()` is only reached by the static wrapper, which these tests do not call, so it needs no stub.

- [ ] **Step 6: Confirm no call site broke, then commit**

```bash
grep -rn "ClaimUpload" --include=*.php src public | grep -v "^src/ClaimUpload.php"
vendor/bin/phpunit
git add src/ClaimUpload.php tests/Unit/ClaimUploadTest.php
git commit -m "refactor: inject ClaimRevApi into ClaimUpload (#24)

Drops the unused 'extends BaseService' for the same reason ReportDownload
did: nothing used the parent, whose constructor hits the DB and calls
OEGlobalsBag::getKernel(), absent on OpenEMR 8.0.x."
```

---

### Task 10: Close out the phase

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `docs/superpowers/plans/2026-08-24-claimrev-api-di-seam-phase-1.md`

- [ ] **Step 1: Extend the 2.1.8 changelog entry**

Add to the end of the existing `# 2.1.8` section, before `# 2.1.7`. Fill in the real final test count from `vendor/bin/phpunit`:

```markdown
- Phase 2 of the seam: `ClaimsPage`, `PaymentAdvicePage`, `ClaimTrackingService`, `ReconciliationService`, `NotificationPollService`, `ClaimUpload`, and `PaymentAdvicePostingService`'s ClaimRev notification step now take `ClaimRevApi` by constructor. Every entry point keeps its exact signature and its exact error contract — three shapes were needed, because some consumers had no catch at all, some caught around client construction, and two are private helpers whose caller owns the try.
- `ClaimUpload` no longer extends `BaseService`, for the same reason `ReportDownload` did not: nothing used the parent, and its constructor queries the database and calls `OEGlobalsBag::getKernel()`, absent on OpenEMR 8.0.x.
- Coverage for eight previously untested pure helpers — `normalizeAdvice`, `parsePatientControlNumber`, `buildIdempotencyReference`, `getClaimStatusLabel`, `sumServiceAmounts`, `parsePcn`, `computeDiscrepancy`, and `htmlToPlainText` — which needed no seam at all.
- Deliberately not converted, because injection would not make them meaningfully testable: `ConnectivityInfo` (its constructor reads Bootstrap/GlobalConfig before any API call), and `EligibilityTransfer`'s `sendWaitingEligibility()`/`sendImmediate()` (the API call sits deep inside DB-write sequences, and the class extends `BaseService`). `EligibilityTransfer::retryEligibility()` and `::sendEligibility()` already accept a `ClaimRevApi` parameter and needed no change. `PaymentAdvicePostingService`'s posting pipeline is untouched: none of it calls ClaimRev, so the refactor stays away from the billing writes entirely.
```

- [ ] **Step 2: Record the remaining follow-ups**

In the phase-1 plan's "Bugs found during phase 1" list, append:

```markdown
- **`ClaimsPage::getClaimStatuses()` catches the `ClaimRevException` base class**, so a ClaimRev outage silently yields an empty status dropdown on the Claims tab. Preserved verbatim through phase 2 rather than fixed as a drive-by, since changing it alters what `public/claims.php` renders.
- **`PaymentAdvicePage::getPaymentAdviceById()` has no catch at any layer.** `public/payment_advice_post.php` calls it with no try/catch, so a ClaimRev outage there produces a PHP fatal rather than the endpoint's JSON error shape.
```

- [ ] **Step 3: Full verification**

```bash
vendor/bin/phpunit --testdox
grep -rn "makeFromGlobals" --include=*.php src | grep -v "ClaimRevApi.php"
```

The grep should show `makeFromGlobals()` only inside static wrappers and the two private Shape C helpers — never inline in the middle of business logic. Report the output.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md docs/
git commit -m "docs: changelog and follow-ups for phase 2 of the DI seam (#24)"
```

---

## Done criteria

- `composer install && vendor/bin/phpunit` passes from a clean checkout with no OpenEMR present.
- Every converted entry point keeps its exact signature; no call site in `src/`, `public/`, or `templates/` changed.
- Every converted consumer's error contract is byte-for-byte what it was, verified per task.
- `src/PaymentAdvicePostingService.php`'s diff contains no billing-related line.
- `src/ReconciliationService.php`'s `reconcile()` is unchanged.
- CI green on PHP 8.2 and 8.3.

## Deliberately still open after phase 2

`ConnectivityInfo`; `EligibilityTransfer::sendWaitingEligibility()` and `::sendImmediate()`; the `http_errors` bug in `ClaimRevApi`; the ERA tab reporting outages as "No results found"; the missing `release/v7-compat` branch. All recorded in the phase-1 plan's follow-up section.
