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
