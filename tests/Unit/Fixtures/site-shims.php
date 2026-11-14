<?php

/**
 * Shims for the filesystem calls `Site` makes: `is_writable()`, `is_dir()`, `glob()` and `file_get_contents()`.
 *
 * PHP resolves an unqualified function call inside a namespace to a function of the same name in that namespace
 * before the global one (see process-shims.php). With nothing configured each shim passes the call through, so the
 * other classes that call these functions behave as before; a test sets an answer to reach the branch it names
 * without touching a real directory.
 */

declare(strict_types=1);

namespace {
    if (! class_exists('SiteTestFs', false)) {
        class SiteTestFs
        {
            /** @var list<array<int, mixed>> */
            public static array $calls = [];

            public static ?bool $writable = null;

            public static ?bool $dir = null;

            /** @var list<string>|false|null */
            public static array|false|null $glob = null;

            public static ?bool $unreadable = null;

            public static function reset(): void
            {
                self::$calls = [];
                self::$writable = null;
                self::$dir = null;
                self::$glob = null;
                self::$unreadable = null;
            }
        }
    }
}

namespace UAMSWP_ACFCDT_Migrator {
    function is_writable(string $filename): bool
    {
        \SiteTestFs::$calls[] = ['is_writable', $filename];

        return \SiteTestFs::$writable ?? \is_writable($filename);
    }

    function is_dir(string $filename): bool
    {
        \SiteTestFs::$calls[] = ['is_dir', $filename];

        return \SiteTestFs::$dir ?? \is_dir($filename);
    }

    /**
     * @return list<string>|false
     */
    function glob(string $pattern, int $flags = 0): array|false
    {
        \SiteTestFs::$calls[] = ['glob', $pattern];

        return \SiteTestFs::$glob ?? \glob($pattern, $flags);
    }

    function file_get_contents(string $filename): string|false
    {
        \SiteTestFs::$calls[] = ['file_get_contents', $filename];

        return \SiteTestFs::$unreadable ? false : \file_get_contents($filename);
    }
}
