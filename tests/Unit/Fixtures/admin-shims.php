<?php

/**
 * Shims for the internal functions `Admin` calls and a test has to observe: `header()`, `set_time_limit()` and
 * `ignore_user_abort()`, plus the JSON responder stand-in.
 *
 * PHP resolves an unqualified function call inside a namespace to a function of the same name in that namespace
 * before the global one (see process-shims.php), so these intercept the calls with no Patchwork internals.
 */

declare(strict_types=1);

namespace {
    if (! class_exists('AdminTestSpy', false)) {
        /** What the shims recorded. */
        class AdminTestSpy
        {
            /** @var list<array<int, mixed>> */
            public static array $calls = [];

            public static function reset(): void
            {
                self::$calls = [];
            }
        }
    }

    if (! class_exists('AdminTestSent', false)) {
        /**
         * Thrown by the `wp_send_json_*()` stand-ins, which stop the request where the real ones `die()`.
         */
        class AdminTestSent extends RuntimeException
        {
            public function __construct(public readonly bool $success, public readonly mixed $data, public readonly ?int $status = null)
            {
                parent::__construct($success ? 'success' : 'error');
            }
        }
    }

    if (! class_exists('AdminTestDied', false)) {
        /** Thrown by the `wp_die()` stand-in. */
        class AdminTestDied extends RuntimeException {}
    }

    if (! class_exists('AdminTestFinished', false)) {
        /** Thrown by the redefined `Admin::finish()`. */
        class AdminTestFinished extends RuntimeException {}
    }
}

namespace UAMSWP_ACFCDT_Migrator {
    function header(string $header): void
    {
        \AdminTestSpy::$calls[] = ['header', $header];
    }

    function set_time_limit(int $seconds): bool
    {
        \AdminTestSpy::$calls[] = ['set_time_limit', $seconds];

        return true;
    }

    function ignore_user_abort(?bool $enable = null): int
    {
        \AdminTestSpy::$calls[] = ['ignore_user_abort', $enable];

        return 0;
    }
}
