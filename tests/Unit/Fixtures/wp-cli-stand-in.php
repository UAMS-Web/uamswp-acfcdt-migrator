<?php

/**
 * A recording stand-in for the `WP_CLI` class: every call is appended to `CommandTestCli::$calls`, and `error()` and
 * `halt()` throw so a test can stop where the real ones exit the process.
 */

declare(strict_types=1);

if (! class_exists('CommandTestCli', false)) {
    /**
     * What the `WP_CLI` stand-in recorded, and the runner it hands out. Kept off `WP_CLI` itself because static
     * analysis reads that class from the WP-CLI stubs, which have no such properties.
     */
    class CommandTestCli
    {
        /** @var list<array{0: string, 1: mixed}> */
        public static array $calls = [];

        public static object $runner;
    }
}

if (! class_exists('WP_CLI', false)) {
    class WP_CLI
    {
        public static function log(string $message): void
        {
            CommandTestCli::$calls[] = ['log', $message];
        }

        public static function warning(string $message): void
        {
            CommandTestCli::$calls[] = ['warning', $message];
        }

        public static function success(string $message): void
        {
            CommandTestCli::$calls[] = ['success', $message];
        }

        public static function error(string $message): never
        {
            CommandTestCli::$calls[] = ['error', $message];

            throw new RuntimeException("WP_CLI::error: $message");
        }

        public static function halt(int $code): never
        {
            CommandTestCli::$calls[] = ['halt', $code];

            throw new RuntimeException("WP_CLI::halt: $code");
        }

        public static function colorize(string $string): string
        {
            return $string;
        }

        public static function get_runner(): object
        {
            return CommandTestCli::$runner;
        }
    }
}

if (! class_exists('CommandTestProgressBar', false)) {
    /**
     * Counts what `Command` does to the progress bar `make_progress_bar()` returns.
     */
    class CommandTestProgressBar
    {
        public int $ticks = 0;

        public bool $finished = false;

        public function __construct(public string $message, public int $count) {}

        public function tick(): void
        {
            // A loop that ticks without end fails here instead of hanging a mutation run.
            if (++$this->ticks > 1000) {
                throw new LogicException('The progress bar was ticked more than 1000 times.');
            }
        }

        public function finish(): void
        {
            $this->finished = true;
        }
    }
}
