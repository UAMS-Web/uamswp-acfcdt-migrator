<?php

/**
 * The real plugin stack the Integration suite loads, and the manifest that pins it.
 *
 * Plain PHP with no WordPress and no Composer dependency, so the bootstrap can verify the stack before it
 * boots anything and a failed check stops the run before a test database is touched. The manifest holds,
 * per plugin directory, the version its main file declares and a fingerprint of its files. A label that
 * matches while the files differ does not pass.
 */

declare(strict_types=1);

namespace UAMSWP_ACFCDT_Migrator\Tests\Integration;

final class PluginStack
{
    /**
     * The directory name of each plugin and the file that carries its header, in load order.
     *
     * @var array<string, string>
     */
    public const MAIN_FILES = [
        'advanced-custom-fields-pro' => 'acf.php',
        'acf-extended' => 'acf-extended.php',
        'acf-custom-database-tables' => 'acf-custom-database-tables.php',
    ];

    /**
     * The `Version:` header of a plugin's main file, or an empty string when it has none in the first 8 KB.
     */
    public static function version(string $mainFile): string
    {
        $head = (string) file_get_contents($mainFile, false, null, 0, 8192);

        return preg_match('/^[ \t\/*#@]*Version:\s+(.*)$/mi', $head, $matches) === 1 ? trim($matches[1]) : '';
    }

    /**
     * A fingerprint of every file under a plugin directory: SHA-256 over the sorted relative paths, each
     * with the SHA-256 of its bytes. File names that only the operating system adds are left out.
     */
    public static function fingerprint(string $directory): string
    {
        $directory = rtrim($directory, '/');
        $lines = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getFilename() === '.DS_Store') {
                continue;
            }

            $lines[] = substr($file->getPathname(), strlen($directory) + 1)."\t".hash_file('sha256', $file->getPathname());
        }

        sort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * The manifest for the stack found in a plugins directory, as the committed file records it.
     *
     * @return array<string, array{main: string, version: string, sha256: string}>
     */
    public static function describe(string $pluginsDirectory): array
    {
        $pluginsDirectory = rtrim($pluginsDirectory, '/');
        $stack = [];

        foreach (self::MAIN_FILES as $slug => $main) {
            $stack[$slug] = [
                'main' => $main,
                'version' => self::version($pluginsDirectory.'/'.$slug.'/'.$main),
                'sha256' => self::fingerprint($pluginsDirectory.'/'.$slug),
            ];
        }

        return $stack;
    }

    /**
     * What differs between the manifest and the plugins directory, one sentence per difference.
     *
     * @param  array<string, array{main: string, version: string, sha256: string}>  $manifest
     * @return list<string>
     */
    public static function problems(string $pluginsDirectory, array $manifest): array
    {
        $pluginsDirectory = rtrim($pluginsDirectory, '/');
        $problems = [];

        foreach (self::MAIN_FILES as $slug => $main) {
            $pinned = $manifest[$slug] ?? null;

            if ($pinned === null) {
                $problems[] = "{$slug}: not in the manifest.";

                continue;
            }

            if (! is_readable($pluginsDirectory.'/'.$slug.'/'.$main)) {
                $problems[] = "{$slug}: not found at {$pluginsDirectory}/{$slug}/{$main}.";

                continue;
            }

            $version = self::version($pluginsDirectory.'/'.$slug.'/'.$main);

            if ($version !== $pinned['version']) {
                $problems[] = "{$slug}: version is '{$version}', the manifest pins '{$pinned['version']}'.";

                continue;
            }

            $hash = self::fingerprint($pluginsDirectory.'/'.$slug);

            if ($hash !== $pinned['sha256']) {
                $problems[] = "{$slug}: version {$version} matches but its files do not (sha256 {$hash}, the manifest pins {$pinned['sha256']}).";
            }
        }

        return $problems;
    }
}

// cspell:ignore fingerprint
