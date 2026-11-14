<?php

/**
 * Shims for the process, environment and file-close functions `Command::run_child()` calls.
 *
 * PHP resolves an unqualified function call inside a namespace to a function of the same name in that namespace
 * before the global one, so defining these in the plugin's namespace intercepts the calls without Patchwork's
 * internal-function redefinition. Each answers from `CommandTestProcess`, which a test configures.
 */

declare(strict_types=1);

namespace {
    if (! class_exists('CommandTestProcess', false)) {
        class CommandTestProcess
        {
            /** @var list<array<int, mixed>> */
            public static array $calls = [];

            /** @var resource|false */
            public static mixed $handle = false;

            public static int $exit = 0;

            public static string|false $env = false;

            public static function reset(): void
            {
                self::$calls = [];
                self::$handle = false;
                self::$exit = 0;
                self::$env = false;
            }
        }
    }
}

namespace UAMSWP_ACFCDT_Migrator {
    /**
     * Records the close, then closes: a plain file reads the same whether or not it was closed, so the call is the
     * only thing a test can see.
     *
     * @param  resource  $stream
     */
    function fclose(mixed $stream): bool
    {
        \CommandTestProcess::$calls[] = ['fclose'];

        return \fclose($stream);
    }

    function getenv(string $name): string|false
    {
        \CommandTestProcess::$calls[] = ['getenv', $name];

        return \CommandTestProcess::$env;
    }

    /**
     * @param  array<int, mixed>  $descriptors
     *
     * @param-out array<int, resource>  $pipes
     *
     * @return resource|false
     */
    function proc_open(string $command, array $descriptors, ?array &$pipes = null): mixed
    {
        \CommandTestProcess::$calls[] = ['open', $command, $descriptors];
        // No pipes are handed back: the child's output is not read in these tests.
        $pipes = [];

        return \CommandTestProcess::$handle;
    }

    /**
     * @param  resource  $process
     */
    function proc_close(mixed $process): int
    {
        \CommandTestProcess::$calls[] = ['close'];

        // Like the real function, answer -1 for a handle that is not the one `proc_open()` returned.
        return $process === \CommandTestProcess::$handle ? \CommandTestProcess::$exit : -1;
    }
}
