<?php

/**
 * Minimal doubles for the OpenEMR core classes the module touches.
 *
 * Declared in OpenEMR's real namespaces so production code resolves them with
 * no changes. Tests control state through ClaimRevStubState, whose reset()
 * must run in setUp() to keep tests isolated.
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
