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

/**
 * @phpstan-type Pinned array{main: string, version: string, sha256: string, alternates?: list<string>}
 */
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
     * with the SHA-256 of its bytes after every CRLF is read as LF, and every backslash in a path read as a
     * slash. File names that only the operating system adds are left out. The two rules are there because one
     * plugin's published zip ships CRLF where a copy installed another way carries LF, and a Windows machine
     * writes `src\file.php` where macOS and Linux write `src/file.php`; the same files must not fail the check
     * for either.
     */
    public static function fingerprint(string $directory): string
    {
        $directory = rtrim($directory, '/');
        $lines = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            if (! $file->isFile() || $file->getFilename() === '.DS_Store') {
                continue;
            }

            $bytes = file_get_contents($file->getPathname());

            if ($bytes === false) {
                throw new \RuntimeException('Cannot read '.$file->getPathname().' to fingerprint it.');
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));

            $lines[] = $relative."\t".hash('sha256', str_replace("\r\n", "\n", $bytes));
        }

        sort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * The pinned stack read out of the manifest file's JSON, with every entry checked for the shape the comparison
     * relies on. A malformed manifest throws instead of being compared as though it were empty.
     *
     * @return array<string, Pinned>
     *
     * @throws \JsonException when the text is not JSON
     * @throws \UnexpectedValueException when the JSON does not carry a `plugins` map of `main`, `version` and `sha256` strings, or an `alternates` that is not a list of strings
     */
    public static function parseManifest(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! is_array($decoded['plugins'] ?? null)) {
            throw new \UnexpectedValueException('The plugin manifest has no `plugins` map.');
        }

        $stack = [];

        foreach ($decoded['plugins'] as $slug => $entry) {
            if (
                ! is_string($slug)
                || ! is_array($entry)
                || ! is_string($entry['main'] ?? null)
                || ! is_string($entry['version'] ?? null)
                || ! is_string($entry['sha256'] ?? null)
            ) {
                throw new \UnexpectedValueException('The plugin manifest entry for '.$slug.' needs string `main`, `version` and `sha256` values.');
            }

            $pinned = ['main' => $entry['main'], 'version' => $entry['version'], 'sha256' => $entry['sha256']];

            if (array_key_exists('alternates', $entry)) {
                $alternates = $entry['alternates'];

                if (! is_array($alternates) || ! array_is_list($alternates) || array_filter($alternates, is_string(...)) !== $alternates) {
                    throw new \UnexpectedValueException('The plugin manifest entry for '.$slug.' needs `alternates` to be a list of strings.');
                }

                $pinned['alternates'] = $alternates;
            }

            $stack[$slug] = $pinned;
        }

        return $stack;
    }

    /**
     * The manifest for the stack found in a plugins directory, as the committed file records it. `alternates`
     * (another distribution of the same version) are kept by hand and never written here.
     *
     * @return array<string, Pinned>
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
     * @param  array<string, Pinned>  $manifest
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

            $accepted = [$pinned['sha256'], ...($pinned['alternates'] ?? [])];

            if (! in_array($hash, $accepted, true)) {
                $problems[] = "{$slug}: version {$version} matches but its files do not (sha256 {$hash}, the manifest pins ".implode(' or ', $accepted).').';
            }
        }

        return $problems;
    }
}

// cspell:ignore fingerprint
