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

    /**
     * Stateless by design: every read goes through ClaimRevStubState::$globals,
     * so ClaimRevStubState::reset() alone is sufficient to isolate tests.
     * Do not add instance state here without also updating reset().
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

        public function getString(string $key, string $default = ''): string
        {
            $value = $this->get($key, $default);
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                throw new \UnexpectedValueException(sprintf('Parameter value "%s" cannot be converted to "string".', $key));
            }
            return (string) $value;
        }
    }
}

namespace OpenEMR\BC {

    class ServiceContainer
    {
        public static function getLogger(): \Psr\Log\LoggerInterface
        {
            return new \ClaimRevStubLogger();
        }
    }
}

namespace {
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
}
