<?php

/**
 * Shim for `function_exists()`, which `Engine` uses to ask whether ACF is loaded.
 *
 * PHP resolves an unqualified function call inside a namespace to a function of the same name in that namespace
 * before the global one (see process-shims.php). With nothing configured the shim passes the call through, so the
 * other classes that call `function_exists()` behave as before; a test sets an answer to reach the branch it names,
 * because a Brain Monkey stub, once defined, makes the function exist for the rest of the process.
 */

declare(strict_types=1);

namespace {
    if (! class_exists('EngineTestFn', false)) {
        class EngineTestFn
        {
            /** @var array<string, bool> */
            public static array $answers = [];

            /** @var list<string> */
            public static array $calls = [];

            public static function reset(): void
            {
                self::$answers = [];
                self::$calls = [];
            }
        }
    }
}

namespace UAMSWP_ACFCDT_Migrator {
    function function_exists(string $function): bool
    {
        \EngineTestFn::$calls[] = $function;

        return \EngineTestFn::$answers[$function] ?? \function_exists($function);
    }
}
