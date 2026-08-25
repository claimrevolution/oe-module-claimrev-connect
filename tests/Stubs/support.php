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
