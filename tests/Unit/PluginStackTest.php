<?php

/**
 * PluginStack: how the Integration suite's plugin fingerprint and manifest comparison behave.
 *
 * The class is plain PHP with no WordPress, so it runs here. Plugin directories are temporary ones built per test.
 *
 * @see tests/Integration/PluginStack.php
 */

declare(strict_types=1);

use UAMSWP_ACFCDT_Migrator\Tests\Integration\PluginStack;

require_once __DIR__.'/../Integration/PluginStack.php';

afterEach(function (): void {
    foreach (plugin_stack_dirs() as $dir) {
        plugin_stack_remove($dir);
    }

    unset($GLOBALS['plugin_stack_dirs']);
});

/**
 * The temporary directories this test file has made.
 *
 * @return list<string>
 */
function plugin_stack_dirs(): array
{
    $dirs = $GLOBALS['plugin_stack_dirs'] ?? [];

    return is_array($dirs) ? array_values(array_filter($dirs, is_string(...))) : [];
}

/**
 * Makes a temporary plugin directory holding the given files, keyed by relative path.
 *
 * @param  array<string, string>  $files
 */
function plugin_stack_make(array $files): string
{
    $dir = sys_get_temp_dir().'/plugin-stack-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    $GLOBALS['plugin_stack_dirs'] = [...plugin_stack_dirs(), $dir];

    foreach ($files as $path => $contents) {
        $full = $dir.'/'.$path;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }

        file_put_contents($full, $contents);
    }

    return $dir;
}

/** Removes a directory tree. */
function plugin_stack_remove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item instanceof SplFileInfo) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    }

    rmdir($dir);
}

it('gives the same fingerprint to the same files with CRLF or LF line endings', function (): void {
    $lf = plugin_stack_make(['a.php' => "<?php\n// x\n", 'src/b.php' => "one\ntwo\n"]);
    $crlf = plugin_stack_make(['a.php' => "<?php\r\n// x\r\n", 'src/b.php' => "one\r\ntwo\r\n"]);

    expect(PluginStack::fingerprint($crlf))->toBe(PluginStack::fingerprint($lf));
});

it('gives a different fingerprint when a file changes, is added or is removed', function (): void {
    $base = PluginStack::fingerprint(plugin_stack_make(['a.php' => 'one', 'b.php' => 'two']));

    expect(PluginStack::fingerprint(plugin_stack_make(['a.php' => 'one', 'b.php' => 'TWO'])))->not->toBe($base)
        ->and(PluginStack::fingerprint(plugin_stack_make(['a.php' => 'one', 'b.php' => 'two', 'c.php' => 'x'])))->not->toBe($base)
        ->and(PluginStack::fingerprint(plugin_stack_make(['a.php' => 'one'])))->not->toBe($base);
});

it('ignores a .DS_Store file the operating system added', function (): void {
    $plain = plugin_stack_make(['a.php' => 'one']);
    $noisy = plugin_stack_make(['a.php' => 'one', '.DS_Store' => 'junk', 'src/.DS_Store' => 'junk']);

    expect(PluginStack::fingerprint($noisy))->toBe(PluginStack::fingerprint($plain));
});

it('reads a backslash in a path as a slash', function (): void {
    $slashed = plugin_stack_make(['src/b.php' => 'one']);
    $backslashed = plugin_stack_make(['src\\b.php' => 'one']);

    expect(PluginStack::fingerprint($backslashed))->toBe(PluginStack::fingerprint($slashed));
})->skipOnWindows();

it('accepts the pinned fingerprint or an alternate and reports any other', function (): void {
    $stack = fn (string $contents): string => plugin_stack_make([
        'advanced-custom-fields-pro/acf.php' => "<?php\n// Version: 1.0\n * Version: 1.0\n{$contents}",
        'acf-extended/acf-extended.php' => "<?php\n * Version: 2.0\n",
        'acf-custom-database-tables/acf-custom-database-tables.php' => "<?php\n * Version: 3.0\n",
    ]);

    $original = $stack('original');
    $variant = $stack('variant');
    $other = $stack('other');

    $entry = fn (string $slug, string $main, string $version, string $dir): array => [
        'main' => $main,
        'version' => $version,
        'sha256' => PluginStack::fingerprint($dir.'/'.$slug),
    ];

    $manifest = [
        'advanced-custom-fields-pro' => [...$entry('advanced-custom-fields-pro', 'acf.php', '1.0', $original), 'alternates' => [PluginStack::fingerprint($variant.'/advanced-custom-fields-pro')]],
        'acf-extended' => $entry('acf-extended', 'acf-extended.php', '2.0', $original),
        'acf-custom-database-tables' => $entry('acf-custom-database-tables', 'acf-custom-database-tables.php', '3.0', $original),
    ];

    expect(PluginStack::problems($original, $manifest))->toBe([])
        ->and(PluginStack::problems($variant, $manifest))->toBe([])
        ->and(PluginStack::problems($other, $manifest))->toHaveCount(1)
        ->and(PluginStack::problems($other, $manifest)[0])->toContain('advanced-custom-fields-pro: version 1.0 matches but its files do not')
        ->and(PluginStack::problems($other, $manifest)[0])->toContain(' or ');
});

it('reads an alternates list from the manifest and rejects one that is not a list of strings', function (): void {
    $entry = static fn (mixed $alternates): string => json_encode(['plugins' => ['p' => ['main' => 'p.php', 'version' => '1', 'sha256' => 'a', 'alternates' => $alternates]]], JSON_THROW_ON_ERROR);

    expect(PluginStack::parseManifest($entry(['b', 'c']))['p']['alternates'] ?? null)->toBe(['b', 'c']);

    foreach (['b', ['k' => 'b'], [1], [['b']]] as $bad) {
        expect(fn (): array => PluginStack::parseManifest($entry($bad)))->toThrow(UnexpectedValueException::class);
    }
});

it('leaves alternates out of an entry that does not carry them', function (): void {
    $json = json_encode(['plugins' => ['p' => ['main' => 'p.php', 'version' => '1', 'sha256' => 'a']]], JSON_THROW_ON_ERROR);

    expect(PluginStack::parseManifest($json)['p'])->toBe(['main' => 'p.php', 'version' => '1', 'sha256' => 'a']);
});

// cspell:ignore bin2hex
