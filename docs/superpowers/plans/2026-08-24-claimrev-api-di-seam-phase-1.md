# ClaimRevApi DI Seam — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the module's first test harness and CI, then convert the three consumers with no OpenEMR dependencies (`ClaimSearch`, `EraSearch`, `ReportDownload`) to constructor-injected `ClaimRevApi`, proving the pattern end to end.

**Architecture:** Each consumer gains a constructor taking `ClaimRevApi` and moves its logic into an instance method; the existing static method stays as a thin wrapper that calls `ClaimRevApi::makeFromGlobals()` and delegates, so no call site changes. Tests build a real `GuzzleHttp\Client` over a `MockHandler` so request construction is genuinely exercised. OpenEMR core is absent during tests, so the small slice the module touches is hand-stubbed.

**Tech Stack:** PHP 8.2+, PHPUnit 11, Guzzle 7 (`MockHandler`, `HandlerStack`, `Middleware::history`), GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-08-24-claimrev-api-di-seam-design.md`

**Shell:** every command below is POSIX shell. This repo lives on Windows, so
run them through the Bash tool (Git Bash), not PowerShell — `mkdir -p`,
`vendor/bin/phpunit`, and the `for f in ...` loops are not PowerShell syntax.

---

## Scope note

The spec covers all 12 consumers. This plan is **phase 1 of 2** and deliberately
stops after three of them. The split is by stub depth, not by whim:

- `ClaimSearch`, `EraSearch`, and `ReportDownload` reach their ClaimRev calls
  with almost no OpenEMR involvement, so they can be converted and tested
  against a two-class stub layer.
- The other nine (`ConnectivityInfo`, `ClaimUpload`, `NotificationPollService`,
  `ClaimsPage`, `PaymentAdvicePage`, `ReconciliationService`,
  `EligibilityTransfer`, `ClaimTrackingService`, `PaymentAdvicePostingService`)
  need `Bootstrap`, `GlobalConfig`, `KernelCompat`, `QueryUtils`, and the
  `OpenEMR\Billing\*` classes stubbed before their tests can run at all.

Phase 1 delivers working, shippable software: a green suite in CI and three
converted consumers. Phase 2 is planned separately once phase 1 lands, when the
stub layer's real shape is known rather than guessed.

## File structure

**Created:**

| Path | Responsibility |
| --- | --- |
| `phpunit.xml` | Test-runner config; one `unit` suite. |
| `tests/bootstrap.php` | Loads the Composer autoloader, then the stubs. Nothing else. |
| `tests/Stubs/openemr-classes.php` | Hand-written doubles for the OpenEMR classes phase-1 code touches: `OEGlobalsBag`, `ServiceContainer`, and a null logger. |
| `tests/Support/MockApiFactory.php` | Builds a `ClaimRevApi` over a `MockHandler`, and exposes the recorded request history. Every consumer test uses this. |
| `tests/Unit/ClaimRevApiTest.php` | Proves the harness works by testing `ClaimRevApi` itself. |
| `tests/Unit/ClaimSearchTest.php` | Tests for the converted `ClaimSearch`. |
| `tests/Unit/EraSearchTest.php` | Tests for the converted `EraSearch`. |
| `tests/Unit/ReportDownloadTest.php` | Tests for the converted `ReportDownload`. |
| `.github/workflows/tests.yml` | Runs the suite on push and pull request. |

**Modified:**

| Path | Change |
| --- | --- |
| `composer.json` | Declare Guzzle, raise the PHP floor, add `require-dev`, `autoload-dev`, and a `test` script. |
| `.gitignore` | Ignore `vendor/` and `.phpunit.cache/`. |
| `src/ClaimSearch.php` | Inject `ClaimRevApi`; add the missing `catch` its docblock promises. |
| `src/EraSearch.php` | Inject `ClaimRevApi`. |
| `src/ReportDownload.php` | Inject `ClaimRevApi`; drop the unused `extends BaseService`. |
| `CHANGELOG.md` | Record the phase-1 changes under 2.1.8. |

---

### Task 1: Packaging foundation

The suite cannot install or run until Composer knows about Guzzle and PHPUnit.
No tests exist yet, so this task's verification is that PHPUnit runs and
reports zero tests rather than crashing.

**Files:**
- Modify: `composer.json`
- Modify: `.gitignore`
- Create: `phpunit.xml`

- [ ] **Step 1: Rewrite `composer.json`**

Replace the whole file with:

```json
{
  "name": "claimrevolution/oe-module-claimrev-connect",
  "description": "OpenEMR Custom Module Claim Revolution, LLC Connector",
  "type": "openemr-module",
  "license": "GPL-3.0",
  "authors": [
    {
      "name": "Brad Sharp",
      "email": "brad.sharp@claimrev.com",
      "role": "Developer"
    }
  ],
  "keywords": ["openemr", "openemr-module","clearinghouse","claimrev"],
  "minimum-stability": "stable",
  "autoload": {
    "psr-4": {"OpenEMR\\Modules\\ClaimRevConnector\\": "src/"}
  },
  "autoload-dev": {
    "psr-4": {"OpenEMR\\Modules\\ClaimRevConnector\\Tests\\": "tests/"}
  },
  "require": {
    "openemr/oe-module-installer-plugin": "^0.1.0",
    "php": ">=8.2",
    "symfony/event-dispatcher": "^4.4 || ^5.0 || ^6.0 || ^7.0",
    "nyholm/psr7": "^1.4",
    "guzzlehttp/guzzle": "^7.5"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  },
  "scripts": {
    "test": "phpunit"
  },
  "config": {
    "allow-plugins": {
      "openemr/oe-module-installer-plugin": true
    }
  }
}
```

Three deliberate changes beyond adding the dev dependency. `guzzlehttp/guzzle`
is now declared — `src/ClaimRevApi.php` imports `GuzzleHttp\Client` but the
manifest never required it, which worked only because OpenEMR core ships
Guzzle. The PHP floor moves from `>=7.1` to `>=8.2` because
`ClaimRevApi` is declared `readonly class`, which is PHP 8.2+; the old floor
was simply wrong. `allow-plugins` is required or Composer 2.2+ blocks the
OpenEMR installer plugin non-interactively in CI.

- [ ] **Step 2: Add build artefacts to `.gitignore`**

Append these lines to `.gitignore`:

```
vendor/
composer.lock
.phpunit.cache/
.phpunit.result.cache
```

`composer.lock` is ignored because this is a library installed into OpenEMR,
not an application; a committed lock file would fight the host project's
resolution.

- [ ] **Step 3: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         beStrictAboutOutputDuringTests="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4: Install dependencies**

Run: `composer install`

Expected: completes and creates `vendor/`, including `guzzlehttp/guzzle` and
`phpunit/phpunit`. If Composer complains that the root package requires a PHP
version above the running interpreter, stop — the environment needs PHP 8.2+.

- [ ] **Step 5: Create the empty test directory and confirm PHPUnit runs**

Run: `mkdir -p tests/Unit && vendor/bin/phpunit`

Expected: PHPUnit banner, then `No tests executed!`. This is success for this
task — it proves config and autoloading parse.

- [ ] **Step 6: Commit**

```bash
git add composer.json .gitignore phpunit.xml
git commit -m "build: add PHPUnit harness, declare Guzzle, fix the PHP floor

Guzzle is imported by ClaimRevApi but was never declared; it resolved
only because OpenEMR core provides it. The PHP floor said 7.1 while
ClaimRevApi is a readonly class, which needs 8.2."
```

---

### Task 2: OpenEMR stub layer and test bootstrap

OpenEMR core is not installed during tests. Phase-1 code touches exactly two
core classes plus a logger, so the stub layer stays small on purpose — it grows
in phase 2 when the deeper consumers need it.

**Files:**
- Create: `tests/bootstrap.php`
- Create: `tests/Stubs/openemr-classes.php`

- [ ] **Step 1: Create `tests/bootstrap.php`**

```php
<?php

/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader, then hand-written stubs for the slice of
 * OpenEMR core the module touches. OpenEMR is not installable as a dev
 * dependency, so unit tests run against these doubles instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Must load before any module class, so the stubbed OpenEMR classes win.
require_once __DIR__ . '/Stubs/openemr-classes.php';
```

- [ ] **Step 2: Create `tests/Stubs/openemr-classes.php`**

These are declared in OpenEMR's real namespaces so module code resolves them
unchanged. They are plain classes rather than PHPUnit mocks because the module
reaches them through static calls and a singleton, which mock objects cannot
intercept.

```php
<?php

/**
 * Minimal doubles for the OpenEMR core classes the module touches.
 *
 * Declared in OpenEMR's real namespaces so production code resolves them with
 * no changes. Each exposes a reset()/seed() hook so tests can control state;
 * OEGlobalsBagStubState::reset() must run in setUp() to keep tests isolated.
 */

declare(strict_types=1);

namespace {
    /** Shared mutable state for the stubs, kept out of the stubbed classes. */
    final class ClaimRevStubState
    {
        /** @var array<string, mixed> */
        public static array $globals = [];

        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public static array $logs = [];

        public static function reset(): void
        {
            self::$globals = [];
            self::$logs = [];
        }
    }
}

namespace OpenEMR\Core {

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

        public function getString(string $key, string $default = ''): string
        {
            $value = \ClaimRevStubState::$globals[$key] ?? $default;
            return is_string($value) ? $value : $default;
        }
    }
}

namespace OpenEMR\BC {

    class ServiceContainer
    {
        public static function getLogger(): \ClaimRevStubLogger
        {
            return new \ClaimRevStubLogger();
        }
    }
}

namespace {
    /** Records log calls so tests can assert on error reporting. */
    final class ClaimRevStubLogger
    {
        /** @param array<string, mixed> $context */
        public function error(string $message, array $context = []): void
        {
            ClaimRevStubState::$logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        }

        /** @param array<string, mixed> $context */
        public function warning(string $message, array $context = []): void
        {
            ClaimRevStubState::$logs[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
        }

        /** @param array<string, mixed> $context */
        public function info(string $message, array $context = []): void
        {
            ClaimRevStubState::$logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        }
    }
}
```

- [ ] **Step 3: Verify the stubs parse**

Run: `php -l tests/Stubs/openemr-classes.php && php -l tests/bootstrap.php`

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add tests/bootstrap.php tests/Stubs/openemr-classes.php
git commit -m "test: add PHPUnit bootstrap and OpenEMR core stubs"
```

---

### Task 3: Mock API factory and first passing test

Before converting any consumer, prove the harness can drive `ClaimRevApi`
itself. This test is also the reference every later test copies.

**Files:**
- Create: `tests/Support/MockApiFactory.php`
- Create: `tests/Unit/ClaimRevApiTest.php`

- [ ] **Step 1: Write the mock factory**

Create `tests/Support/MockApiFactory.php`:

```php
<?php

/**
 * Builds a ClaimRevApi backed by a Guzzle MockHandler.
 *
 * Tests get a real GuzzleHttp\Client, so URL building, auth headers, and JSON
 * encoding are genuinely exercised; only the wire is faked. The recorded
 * request history lets a test assert what the module actually sent.
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;

final class MockApiFactory
{
    /** @var list<array<string, mixed>> Guzzle's recorded transaction history. */
    public array $history = [];

    public readonly ClaimRevApi $api;

    /**
     * @param list<Response|\Throwable> $queue Responses returned in order, one per request.
     */
    public function __construct(array $queue, string $accessToken = 'test-token')
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.example.test',
        ]);
        $this->api = new ClaimRevApi($client, $accessToken);
    }

    /**
     * Convenience constructor for the common single-JSON-response case.
     *
     * @param array<string|int, mixed> $payload
     */
    public static function withJson(array $payload, int $status = 200): self
    {
        return new self([
            new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
    }

    /** The path (with query string) of the nth recorded request, zero-indexed. */
    public function requestTarget(int $index = 0): string
    {
        return (string) $this->history[$index]['request']->getRequestTarget();
    }

    /** The decoded JSON body of the nth recorded request, zero-indexed. */
    public function requestBody(int $index = 0): mixed
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    /** The value of a header on the nth recorded request, zero-indexed. */
    public function requestHeader(string $name, int $index = 0): string
    {
        return $this->history[$index]['request']->getHeaderLine($name);
    }
}
```

Note `$this->history` is passed to `Middleware::history()` by reference, which
is why it is a public mutable property rather than readonly.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/ClaimRevApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ClaimRevApiTest extends TestCase
{
    public function testSearchClaimsPostsToTheClaimViewEndpoint(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        $factory->api->searchClaims((object) ['patientLastName' => 'Sharp']);

        self::assertSame('/api/ClaimView/v1/SearchClaimsPaged', $factory->requestTarget());
        self::assertSame(['patientLastName' => 'Sharp'], $factory->requestBody());
    }

    public function testRequestsCarryTheBearerToken(): void
    {
        $factory = MockApiFactory::withJson([]);

        $factory->api->getClaimStatuses();

        self::assertSame('Bearer test-token', $factory->requestHeader('Authorization'));
    }

    public function testNon200ResponsesRaiseAnApiException(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'upstream exploded')]);

        $this->expectException(ClaimRevApiException::class);
        $this->expectExceptionMessage('ClaimRev API returned HTTP 500');

        $factory->api->getClaimStatuses();
    }

    public function testApiExceptionCarriesStatusBodyAndEndpoint(): void
    {
        $factory = new MockApiFactory([new Response(404, [], 'nope')]);

        try {
            $factory->api->getClaimStatuses();
            self::fail('Expected ClaimRevApiException');
        } catch (ClaimRevApiException $e) {
            self::assertSame(404, $e->httpStatusCode);
            self::assertSame('nope', $e->responseBody);
            self::assertSame('/api/ClaimView/v1/GetClaimStatuses', $e->endpoint);
        }
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --testdox`

Expected: FAIL. The likely first error is
`Class "OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory" not found`
if `composer install` ran before `autoload-dev` was added.

- [ ] **Step 4: Regenerate the autoloader**

Run: `composer dump-autoload`

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --testdox`

Expected: PASS, 4 tests. If `testSearchClaimsPostsToTheClaimViewEndpoint`
fails on the request target, check whether `base_uri` is being prepended —
`getRequestTarget()` returns the path only, which is what the assertion wants.

- [ ] **Step 6: Commit**

```bash
git add tests/Support/MockApiFactory.php tests/Unit/ClaimRevApiTest.php
git commit -m "test: cover ClaimRevApi request building against a mocked transport"
```

---

### Task 4: Convert `ClaimSearch`

The smallest consumer, and the one with the contract bug: its docblock promises
"Returns false on error for backward compatibility" but it catches nothing, so
it cannot return `false`. The conversion adds the missing `catch`, which also
makes the already-present `if ($raw === false)` branches in `ClaimsPage` live
instead of dead.

**Files:**
- Modify: `src/ClaimSearch.php`
- Test: `tests/Unit/ClaimSearchTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ClaimSearchTest.php`:

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\ClaimSearch;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ClaimSearchTest extends TestCase
{
    public function testSearchClaimsReturnsTheDecodedPayload(): void
    {
        $factory = MockApiFactory::withJson([
            'results' => [['objectId' => 'abc-123']],
            'totalRecords' => 1,
        ]);

        $result = (new ClaimSearch($factory->api))
            ->searchClaims((object) ['patientLastName' => 'Sharp']);

        self::assertSame(1, $result['totalRecords']);
        self::assertSame('abc-123', $result['results'][0]['objectId']);
    }

    public function testSearchClaimsSendsTheModelAsTheRequestBody(): void
    {
        $factory = MockApiFactory::withJson(['results' => [], 'totalRecords' => 0]);

        (new ClaimSearch($factory->api))
            ->searchClaims((object) ['payerNumber' => '99999']);

        self::assertSame('/api/ClaimView/v1/SearchClaimsPaged', $factory->requestTarget());
        self::assertSame(['payerNumber' => '99999'], $factory->requestBody());
    }

    public function testSearchClaimsLetsApiFailuresPropagate(): void
    {
        $factory = new MockApiFactory([new Response(503, [], 'unavailable')]);

        $this->expectException(ClaimRevApiException::class);

        (new ClaimSearch($factory->api))->searchClaims((object) []);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ClaimSearchTest.php --testdox`

Expected: FAIL with
`ArgumentCountError: Too few arguments to function ...ClaimSearch::__construct()`
or `Call to undefined method ...ClaimSearch::searchClaims()`.

- [ ] **Step 3: Rewrite `src/ClaimSearch.php`**

Replace the class body (keep the existing file docblock above it):

```php
class ClaimSearch
{
    public function __construct(private readonly ClaimRevApi $api)
    {
    }

    /**
     * Static entry point. Resolves the API client from globals and delegates.
     *
     * Returns false when the module is unconfigured or the API call fails,
     * which is the contract callers such as ClaimsPage already test for.
     *
     * @return array<string, mixed>|false Returns false on error
     */
    public static function search(object $search): array|false
    {
        try {
            return (new self(ClaimRevApi::makeFromGlobals()))->searchClaims($search);
        } catch (ClaimRevException) {
            return false;
        }
    }

    /**
     * Search for claims.
     *
     * @return array<string, mixed>
     * @throws ClaimRevApiException on API error
     */
    public function searchClaims(object $search): array
    {
        return $this->api->searchClaims($search);
    }
}
```

The `catch (ClaimRevException)` is the fix for the docblock's unmet promise.
`ModuleNotConfiguredException` now extends `ClaimRevException` (changed in
2.1.8), so a missing client ID is caught here too.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ClaimSearchTest.php --testdox`

Expected: PASS, 3 tests.

- [ ] **Step 5: Run the whole suite for regressions**

Run: `vendor/bin/phpunit --testdox`

Expected: PASS, 7 tests total.

- [ ] **Step 6: Commit**

```bash
git add src/ClaimSearch.php tests/Unit/ClaimSearchTest.php
git commit -m "refactor: inject ClaimRevApi into ClaimSearch (#24)

Also adds the catch its docblock already promised. search() documented a
false return but caught nothing, so the ($raw === false) branches in
ClaimsPage were unreachable; they now work as written."
```

---

### Task 5: Convert `EraSearch`

Two static methods, both already catching correctly, so this is a pure
structural change with no behaviour difference.

**Files:**
- Modify: `src/EraSearch.php`
- Test: `tests/Unit/EraSearchTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EraSearchTest.php`:

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApiException;
use OpenEMR\Modules\ClaimRevConnector\EraSearch;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class EraSearchTest extends TestCase
{
    public function testSearchDownloadableFilesPostsToFileManagement(): void
    {
        $factory = MockApiFactory::withJson([['id' => 'era-1', 'payerName' => 'Acme Health']]);

        $result = (new EraSearch($factory->api))
            ->searchDownloadableFiles((object) ['ediType' => '835']);

        self::assertSame('/FileManagement/SearchOutboundClientFiles', $factory->requestTarget());
        self::assertSame(['ediType' => '835'], $factory->requestBody());
        self::assertSame('era-1', $result[0]['id']);
    }

    public function testFetchFileForDownloadPassesTheObjectIdAsQuery(): void
    {
        $factory = MockApiFactory::withJson(['fileText' => 'ISA*00*...', 'ediType' => '835']);

        $result = (new EraSearch($factory->api))->fetchFileForDownload('era-1');

        self::assertSame('/FileManagement/GetFileForDownload?id=era-1', $factory->requestTarget());
        self::assertSame('ISA*00*...', $result['fileText']);
    }

    public function testInstanceMethodsLetApiFailuresPropagate(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        $this->expectException(ClaimRevApiException::class);

        (new EraSearch($factory->api))->fetchFileForDownload('era-1');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/EraSearchTest.php --testdox`

Expected: FAIL with `ArgumentCountError` on `EraSearch::__construct()`.

- [ ] **Step 3: Rewrite `src/EraSearch.php`**

Replace the class body (keep the existing file docblock):

```php
class EraSearch
{
    public function __construct(private readonly ClaimRevApi $api)
    {
    }

    /**
     * Static entry point for ERA file search. Resolves the client from
     * globals and delegates.
     *
     * @return array<string, mixed>|false Returns false on error
     */
    public static function search(object $search): array|false
    {
        try {
            return (new self(ClaimRevApi::makeFromGlobals()))->searchDownloadableFiles($search);
        } catch (ClaimRevException) {
            return false;
        }
    }

    /**
     * Static entry point for ERA download. Resolves the client from globals
     * and delegates.
     *
     * @return array<string, mixed>|false Returns false on error
     */
    public static function downloadEra(string $objectId): array|false
    {
        try {
            return (new self(ClaimRevApi::makeFromGlobals()))->fetchFileForDownload($objectId);
        } catch (ClaimRevException) {
            return false;
        }
    }

    /**
     * Search for downloadable ERA files.
     *
     * @return array<string, mixed>
     * @throws ClaimRevApiException on API error
     */
    public function searchDownloadableFiles(object $search): array
    {
        return $this->api->searchDownloadableFiles($search);
    }

    /**
     * Fetch a single ERA file's contents by object ID.
     *
     * @return array<string, mixed>
     * @throws ClaimRevApiException on API error
     */
    public function fetchFileForDownload(string $objectId): array
    {
        return $this->api->getFileForDownload($objectId);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/EraSearchTest.php --testdox`

Expected: PASS, 3 tests.

- [ ] **Step 5: Run the whole suite**

Run: `vendor/bin/phpunit --testdox`

Expected: PASS, 10 tests total.

- [ ] **Step 6: Commit**

```bash
git add src/EraSearch.php tests/Unit/EraSearchTest.php
git commit -m "refactor: inject ClaimRevApi into EraSearch (#24)"
```

---

### Task 6: Convert `ReportDownload`

This one writes files to disk and needs the `OEGlobalsBag` stub for
`OE_SITE_DIR`. It also requires dropping `extends BaseService`: the parent
constructor runs `QueryUtils::listTableFields()` against a live database and
calls `OEGlobalsBag::getInstance()->getKernel()`, the method that does not
exist on OpenEMR 8.0.x and caused the 2.1.7 outage. `ReportDownload` uses
nothing from `BaseService` — every method is static and no member of the parent
is referenced — so adding an injection constructor is only safe once the
inheritance is gone.

**Files:**
- Modify: `src/ReportDownload.php`
- Test: `tests/Unit/ReportDownloadTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ReportDownloadTest.php`:

```php
<?php

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Unit;

use ClaimRevStubState;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ReportDownload;
use OpenEMR\Modules\ClaimRevConnector\Tests\Support\MockApiFactory;
use PHPUnit\Framework\TestCase;

final class ReportDownloadTest extends TestCase
{
    private string $siteDir;

    protected function setUp(): void
    {
        ClaimRevStubState::reset();
        $this->siteDir = sys_get_temp_dir() . '/claimrev-test-' . bin2hex(random_bytes(6));
        mkdir($this->siteDir, 0777, true);
        ClaimRevStubState::$globals['OE_SITE_DIR'] = $this->siteDir;
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->siteDir);
        ClaimRevStubState::reset();
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testSave835WritesTheFileTextToTheEraDirectory(): void
    {
        $factory = MockApiFactory::withJson(['fileText' => 'ISA*00*ERA-BODY']);

        (new ReportDownload($factory->api))->save835('era-1');

        $written = $this->siteDir . '/documents/era/era-1.edi';
        self::assertFileExists($written);
        self::assertSame('ISA*00*ERA-BODY', file_get_contents($written));
    }

    public function testSave835LogsAnErrorWhenFileTextIsAbsent(): void
    {
        $factory = MockApiFactory::withJson(['somethingElse' => true]);

        (new ReportDownload($factory->api))->save835('era-1');

        self::assertFileDoesNotExist($this->siteDir . '/documents/era/era-1.edi');
        self::assertSame('error', ClaimRevStubState::$logs[0]['level']);
        self::assertStringContainsString('fileText', ClaimRevStubState::$logs[0]['message']);
    }

    public function testSave835LogsAndReturnsWhenTheApiFails(): void
    {
        $factory = new MockApiFactory([new Response(500, [], 'boom')]);

        (new ReportDownload($factory->api))->save835('era-1');

        self::assertFileDoesNotExist($this->siteDir . '/documents/era/era-1.edi');
        self::assertSame('error', ClaimRevStubState::$logs[0]['level']);
        self::assertStringContainsString('Unable to download file', ClaimRevStubState::$logs[0]['message']);
    }

    public function testSaveWaitingFilesWritesEachReportUnderItsTypeFolder(): void
    {
        // Two requests: one per report type, 999 then 277.
        $factory = new MockApiFactory([
            new Response(200, [], json_encode([['fileText' => 'NINE-NINE-NINE', 'fileName' => 'r999']], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([['fileText' => 'TWO-SEVEN-SEVEN', 'fileName' => 'r277']], JSON_THROW_ON_ERROR)),
        ]);

        (new ReportDownload($factory->api))->saveWaitingFiles();

        // 999 reports land in f997, which is intentional and long-standing.
        self::assertSame(
            'NINE-NINE-NINE',
            file_get_contents($this->siteDir . '/documents/edi/history/f997/r999.txt'),
        );
        self::assertSame(
            'TWO-SEVEN-SEVEN',
            file_get_contents($this->siteDir . '/documents/edi/history/f277/r277.txt'),
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ReportDownloadTest.php --testdox`

Expected: FAIL with `ArgumentCountError` on `ReportDownload::__construct()`, or
a `BaseService` not-found error from the stub layer.

- [ ] **Step 3: Rewrite `src/ReportDownload.php`**

Replace the whole file below the docblock:

```php
declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Core\OEGlobalsBag;

/**
 * Downloads ClaimRev report files (999/277 acknowledgements and 835 ERAs) to
 * the site's documents tree.
 *
 * Does not extend BaseService: nothing here used it, and its constructor
 * queries the database and calls OEGlobalsBag::getKernel(), which does not
 * exist on OpenEMR 8.0.x.
 */
class ReportDownload
{
    /** @var list<string> Report types fetched by saveWaitingFiles(). */
    private const REPORT_TYPES = ['999', '277'];

    public function __construct(private readonly ClaimRevApi $api)
    {
    }

    /** Static entry point. Resolves the client from globals and delegates. */
    public static function getWaitingFiles(): void
    {
        try {
            $service = new self(ClaimRevApi::makeFromGlobals());
        } catch (ClaimRevException) {
            return;
        }
        $service->saveWaitingFiles();
    }

    /** Static entry point. Resolves the client from globals and delegates. */
    public static function download835(string $objectId): void
    {
        try {
            $service = new self(ClaimRevApi::makeFromGlobals());
        } catch (ClaimRevException) {
            return;
        }
        $service->save835($objectId);
    }

    /**
     * Fetch every waiting 999 and 277 report and write it under the site's
     * EDI history tree. A failure on one report type does not stop the others.
     */
    public function saveWaitingFiles(): void
    {
        $siteDir = OEGlobalsBag::getInstance()->get('OE_SITE_DIR');

        foreach (self::REPORT_TYPES as $reportType) {
            // 999s have always been filed under f997; kept for continuity.
            $reportFolder = $reportType === '999' ? 'f997' : 'f' . $reportType;
            $savePath = $siteDir . '/documents/edi/history/' . $reportFolder . '/';

            if (!file_exists($savePath)) {
                mkdir($savePath, 0750, true);
            }

            try {
                $datas = $this->api->getReportFiles($reportType);
            } catch (ClaimRevApiException) {
                continue;
            }

            foreach ($datas as $data) {
                if (!isset($data['fileText'])) {
                    ServiceContainer::getLogger()->error(
                        'Unable to find property fileText in response',
                        ['class' => self::class, 'method' => 'saveWaitingFiles'],
                    );
                    continue;
                }
                $filePathName = $savePath . $data['fileName'] . '.txt';
                file_put_contents($filePathName, $data['fileText']);
                chmod($filePathName, 0640);
            }
        }
    }

    /** Fetch one 835 by object ID and write it to the site's ERA directory. */
    public function save835(string $objectId): void
    {
        $siteDir = OEGlobalsBag::getInstance()->get('OE_SITE_DIR');
        $savePath = $siteDir . '/documents/era/';

        if (!file_exists($savePath)) {
            mkdir($savePath, 0750, true);
        }

        try {
            $data = $this->api->getFileForDownload($objectId);
        } catch (ClaimRevApiException $e) {
            ServiceContainer::getLogger()->error(
                'Unable to download file',
                ['class' => self::class, 'method' => 'save835', 'exception' => $e->getMessage()],
            );
            return;
        }

        if (!isset($data['fileText'])) {
            ServiceContainer::getLogger()->error(
                'Unable to find property fileText in response',
                ['class' => self::class, 'method' => 'save835'],
            );
            return;
        }

        $filePathName = $savePath . $objectId . '.edi';
        file_put_contents($filePathName, $data['fileText']);
        chmod($filePathName, 0640);
    }
}
```

Two behaviour-preserving tidies came along with the move: the report-type list
is now a named constant instead of an inline array, and the `fileText` checks
use an early `continue`/`return` rather than an `else`. Neither changes what the
code does.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ReportDownloadTest.php --testdox`

Expected: PASS, 4 tests.

- [ ] **Step 5: Confirm nothing else depended on the removed inheritance**

Run: `grep -rn "ReportDownload" --include=*.php src public | grep -v "^src/ReportDownload.php"`

Expected: only static calls such as `ReportDownload::getWaitingFiles()`. If any
caller does `new ReportDownload()` with no argument, or reaches a `BaseService`
member on it, stop and reconsider before continuing.

- [ ] **Step 6: Run the whole suite**

Run: `vendor/bin/phpunit --testdox`

Expected: PASS, 14 tests total.

- [ ] **Step 7: Commit**

```bash
git add src/ReportDownload.php tests/Unit/ReportDownloadTest.php
git commit -m "refactor: inject ClaimRevApi into ReportDownload (#24)

Drops the unused 'extends BaseService'. Nothing here used the parent, and
its constructor queries the DB and calls OEGlobalsBag::getKernel(), absent
on OpenEMR 8.0.x, so an injection constructor could not safely call it."
```

---

### Task 7: Continuous integration

**Files:**
- Create: `.github/workflows/tests.yml`

- [ ] **Step 1: Create the workflow**

```yaml
name: tests

on:
  push:
    branches: [main]
  pull_request:

jobs:
  phpunit:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3']

    name: PHPUnit on PHP ${{ matrix.php }}

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress --no-interaction

      - name: Run test suite
        run: vendor/bin/phpunit --testdox
```

- [ ] **Step 2: Validate the YAML parses**

Run: `php -r "var_dump(is_array(yaml_parse_file('.github/workflows/tests.yml')));"`

If the `yaml` extension is unavailable, skip this step — GitHub validates on
push, and step 4 below confirms the run.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/tests.yml
git commit -m "ci: run PHPUnit on push and pull request"
```

- [ ] **Step 4: Confirm the workflow ran green after pushing**

Run: `gh run list --limit 3`

Expected: the `tests` workflow appears with `completed  success` for both PHP
versions. If it fails on `composer install` with an allow-plugins prompt,
re-check the `config.allow-plugins` block added in Task 1.

---

### Task 8: Changelog

Phase 1 rides on the unreleased 2.1.8 entry created by the earlier
credentials fix, rather than opening a new version heading.

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Extend the 2.1.8 entry**

Insert these lines at the end of the existing `# 2.1.8` section, immediately
before the `# 2.1.7` heading:

```markdown

Testability — first test suite, and a dependency-injection seam for `ClaimRevApi` (#24):
- The module shipped no tests. `ClaimRevApi` supported constructor injection but no consumer used it: twelve classes called the static `ClaimRevApi::makeFromGlobals()` inline, so none could run without a live OpenEMR bootstrap and a real OAuth exchange.
- Add a PHPUnit 11 harness with hand-written stubs for the slice of OpenEMR core the module touches, and a GitHub Actions workflow running the suite on PHP 8.2 and 8.3. Tests drive a real Guzzle client over a `MockHandler`, so URL building, auth headers, and JSON encoding are genuinely exercised.
- Convert `ClaimSearch`, `EraSearch`, and `ReportDownload` to constructor-injected `ClaimRevApi`. Each keeps its existing static method as a thin wrapper that resolves the client from globals and delegates, so no call site changes. The remaining nine consumers follow in a later release.
- Behaviour fix: `ClaimSearch::search()` documented "returns false on error" but caught nothing, so it could not return `false` and a credentials or API failure propagated as a fatal. It now catches `ClaimRevException` as documented, which makes the existing `($raw === false)` branches in `ClaimsPage` reachable — the Claims tab degrades to an empty result set instead of raising.
- `ReportDownload` no longer extends `BaseService`. It used nothing from the parent, whose constructor queries the database and calls `OEGlobalsBag::getKernel()` — absent on OpenEMR 8.0.x.
- Packaging: declare `guzzlehttp/guzzle`, which `ClaimRevApi` imports but the manifest never required (it resolved only because OpenEMR core provides it), and raise the PHP floor from `>=7.1` to `>=8.2`, which `ClaimRevApi`'s `readonly class` has required since it landed.
```

- [ ] **Step 2: Verify the suite and linters are still green**

Run: `vendor/bin/phpunit --testdox && for f in src/ClaimSearch.php src/EraSearch.php src/ReportDownload.php; do php -l "$f"; done`

Expected: PASS, 14 tests, and `No syntax errors detected` three times.

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: changelog for the phase-1 DI seam and test harness (#24)"
```

---

## Done criteria

- `composer install && vendor/bin/phpunit` passes from a clean checkout with no
  OpenEMR present.
- 14 tests green locally and in CI on PHP 8.2 and 8.3.
- `ClaimSearch`, `EraSearch`, and `ReportDownload` each accept a `ClaimRevApi`
  via constructor, and no call site elsewhere in the module changed.
- `grep -rn "makeFromGlobals" src/` shows the three converted classes calling it
  only from their static wrappers.

## Follow-up

Phase 2 covers the remaining nine consumers and is planned once this lands. It
begins by extending the stub layer with `QueryUtils`, `Bootstrap`,
`GlobalConfig`, `KernelCompat`, and the `OpenEMR\Billing\*` classes, which is
the real cost in those conversions.
