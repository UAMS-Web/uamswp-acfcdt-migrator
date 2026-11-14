<?php

/**
 * Shims for `defined()` and `constant()`, which `Loader` uses to ask whether WP-CLI is running. Its `function_exists()`
 * calls are answered by the shim in engine-shims.php, which the test file loads as well.
 *
 * PHP resolves an unqualified function call inside a namespace to a function of the same name in that namespace
 * before the global one (see process-shims.php). A constant can be defined once per process and a Brain Monkey stub,
 * once defined, makes a function exist for the rest of it, so a test sets an answer here to reach the branch it
 * names. With nothing set the shim passes the call through.
 */

declare(strict_types=1);

namespace {
    if (! class_exists('LoaderTestEnv', false)) {
        class LoaderTestEnv
        {
            /** @var array<string, bool> */
            public static array $defined = [];

            /** @var array<string, mixed> */
            public static array $constants = [];

            /** @var list<string> */
            public static array $asked = [];

            public static function reset(): void
            {
                self::$defined = [];
                self::$constants = [];
                self::$asked = [];
            }
        }
    }
}

namespace UAMSWP_ACFCDT_Migrator {
    function defined(string $constant): bool
    {
        \LoaderTestEnv::$asked[] = 'defined:'.$constant;

        return \LoaderTestEnv::$defined[$constant] ?? \defined($constant);
    }

    function constant(string $name): mixed
    {
        \LoaderTestEnv::$asked[] = 'constant:'.$name;

        return \LoaderTestEnv::$constants[$name] ?? \constant($name);
    }
}
