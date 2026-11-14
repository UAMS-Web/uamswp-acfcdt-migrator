<?php

/**
 * Loader: how the plugin file's load-time branching behaves for each way the file can be loaded.
 *
 * The plugin directory is a temporary one whose `includes/` files only record that they were included, so a require
 * the Loader skips or reaches for the wrong path shows in the record instead of being satisfied by the classes the
 * bootstrap already loaded. `WP_CLI` is a recording stand-in (tests/Unit/Fixtures/wp-cli-stand-in.php), and
 * `defined()`, `constant()` and `function_exists()` are namespace shims (loader-shims.php, engine-shims.php) so each
 * branch can be reached in one process. `Admin::init()` is redefined with Patchwork.
 *
 * Helper names carry the `loader_` prefix so the sibling test files can define their own without a clash.
 *
 * @see includes/class-loader.php
 */

declare(strict_types=1);

use UAMSWP_ACFCDT_Migrator\Admin;
use UAMSWP_ACFCDT_Migrator\Command;
use UAMSWP_ACFCDT_Migrator\Loader;

use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;

require_once __DIR__.'/Fixtures/wp-cli-stand-in.php';
require_once __DIR__.'/Fixtures/engine-shims.php';
require_once __DIR__.'/Fixtures/loader-shims.php';

beforeEach(function (): void {
    LoaderTestEnv::reset();
    EngineTestFn::reset();
    // Once Brain Monkey has stubbed a function it exists for the rest of the process, so a test starts from "absent".
    EngineTestFn::$answers = ['add_action' => false, 'is_admin' => false];
    CommandTestCli::$calls = [];
    $GLOBALS['loader_included'] = [];
    $GLOBALS['loader_admin_inits'] = 0;
    redefine(Admin::class.'::init', function (): void {
        $GLOBALS['loader_admin_inits']++;
    });
});

afterEach(function (): void {
    foreach ($GLOBALS['loader_dirs'] ?? [] as $dir) {
        array_map(unlink(...), glob($dir.'/includes/*.php') ?: []);
        rmdir($dir.'/includes');
        rmdir($dir);
    }

    unset($GLOBALS['loader_dirs']);
    LoaderTestEnv::reset();
    EngineTestFn::reset();
    unset($GLOBALS['loader_included'], $GLOBALS['loader_admin_inits']);
});

/** Makes a plugin directory whose `includes/` files each record their own name when included. */
function loader_dir(): string
{
    $dir = sys_get_temp_dir().'/loader-test-'.bin2hex(random_bytes(6));
    mkdir($dir.'/includes', 0777, true);

    foreach (['hook-guard', 'normalizer', 'site', 'store', 'engine', 'command', 'admin'] as $name) {
        file_put_contents($dir.'/includes/class-'.$name.'.php', "<?php\n\$GLOBALS['loader_included'][] = '".$name."';\n");
    }

    $GLOBALS['loader_dirs'][] = $dir;

    return $dir;
}

/** Runs `Loader::load()` over a fresh directory and returns what was included, in order. */
function loader_run(bool $cli, bool $hooks, bool $admin): array
{
    LoaderTestEnv::$defined['WP_CLI'] = $cli;
    LoaderTestEnv::$constants['WP_CLI'] = true;
    EngineTestFn::$answers['add_action'] = $hooks;
    EngineTestFn::$answers['is_admin'] = $hooks;
    when('is_admin')->justReturn($admin);

    Loader::load(loader_dir());

    return $GLOBALS['loader_included'];
}

it('always loads the five engine classes, in dependency order, from the includes folder it is given', function (): void {
    expect(loader_run(false, false, false))->toBe(['hook-guard', 'normalizer', 'site', 'store', 'engine']);
});

it('registers the command only when WP-CLI is running', function (bool $defined, mixed $value, bool $registers): void {
    LoaderTestEnv::$defined['WP_CLI'] = $defined;
    LoaderTestEnv::$constants['WP_CLI'] = $value;

    Loader::load(loader_dir());

    expect(in_array('command', $GLOBALS['loader_included'], true))->toBe($registers)
        ->and(CommandTestCli::$calls)->toBe($registers ? [['add_command', 'acfcdt-migrate', Command::class]] : []);
})->with([
    'WP-CLI running' => [true, true, true],
    'constant defined but false' => [true, false, false],
    'constant not defined' => [false, true, false],
]);

it('does not read the constant when it is not defined', function (): void {
    LoaderTestEnv::$defined['WP_CLI'] = false;

    Loader::load(loader_dir());

    expect(LoaderTestEnv::$asked)->toContain('defined:WP_CLI')
        ->and(LoaderTestEnv::$asked)->not->toContain('constant:WP_CLI');
});

it('loads the command class after the engine classes and before the admin classes', function (): void {
    expect(loader_run(true, true, true))->toBe(['hook-guard', 'normalizer', 'site', 'store', 'engine', 'command', 'admin']);
});

it('leaves the admin screens alone when the hook API is missing, as under wp --require', function (bool $add_action, bool $is_admin): void {
    LoaderTestEnv::$defined['WP_CLI'] = true;
    LoaderTestEnv::$constants['WP_CLI'] = true;
    EngineTestFn::$answers['add_action'] = $add_action;
    EngineTestFn::$answers['is_admin'] = $is_admin;

    Loader::load(loader_dir());

    expect(in_array('admin', $GLOBALS['loader_included'], true))->toBeFalse()
        ->and($GLOBALS['loader_admin_inits'])->toBe(0);
})->with([
    'neither function' => [false, false],
    'add_action only' => [true, false],
    'is_admin only' => [false, true],
]);

it('loads the admin class on any request but starts it only on an admin request', function (bool $admin, int $inits): void {
    $included = loader_run(false, true, $admin);

    expect($included)->toBe(['hook-guard', 'normalizer', 'site', 'store', 'engine', 'admin'])
        ->and($GLOBALS['loader_admin_inits'])->toBe($inits);
})->with([
    'admin request' => [true, 1],
    'front end request' => [false, 0],
]);

it('treats a truthy is_admin() as an admin request', function (): void {
    EngineTestFn::$answers['add_action'] = true;
    EngineTestFn::$answers['is_admin'] = true;
    when('is_admin')->justReturn(1);

    Loader::load(loader_dir());

    expect($GLOBALS['loader_admin_inits'])->toBe(1);
});
