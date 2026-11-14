<?php

/**
 * HookGuard: switching ACF Custom Database Tables' hook callbacks off and on, with WordPress mocked.
 *
 * `$wp_filter` is built by hand from a `WP_Hook` stand-in and stand-ins for the plugin's intercept objects (see
 * tests/Unit/Fixtures), and `remove_filter()` / `add_filter()` are Brain Monkey expectations. Whether the real
 * hooks come off a real `$wp_filter` is for the Integration suite.
 *
 * Mutation run: `vendor/bin/pest tests/Unit/HookGuardTest.php --mutate --path=includes
 * --class='UAMSWP_ACFCDT_Migrator\HookGuard' --covered-only`, 75 mutants, 0 survivors (macOS, PHP 8.4.27, PCOV; a
 * working run, not the Linux run that closes the coverage ticket).
 *
 * The plugin-loaded test runs the "not loaded" case first and loads the core class second, because a class cannot
 * be unloaded; the `acf_custom_database_tables()` function branch is not exercised, for the same reason (a mocked
 * function cannot be removed either), and no mutant depends on it.
 *
 * @see includes/class-hook-guard.php
 */

declare(strict_types=1);

use ACFCustomDatabaseTables\Intercept\ACFGetFieldIntercept;
use ACFCustomDatabaseTables\Intercept\ACFUpdateFieldIntercept;
use ACFCustomDatabaseTables\Intercept\NoResetACFGetFieldIntercept;
use Brain\Monkey\Functions;
use UAMSWP_ACFCDT_Migrator\HookGuard;

use function Brain\Monkey\Functions\when;

require_once __DIR__.'/Fixtures/wordpress-hook-stand-in.php';
require_once __DIR__.'/Fixtures/acf-cdt-intercepts.php';

beforeEach(function (): void {
    // WordPress always has a `$wp_filter`; start each test with an empty one.
    $GLOBALS['wp_filter'] = [];
});

afterEach(function (): void {
    unset($GLOBALS['wp_filter']);
});

/**
 * Builds a `$wp_filter` from `[hook => [priority => [[function, accepted_args], ...]]]`.
 *
 * @param  array<string, array<int, array<int, array{0: mixed, 1: int}>>>  $hooks
 * @return array<string, WP_Hook>
 */
function hook_guard_wp_filter(array $hooks): array
{
    $filter = [];

    foreach ($hooks as $name => $priorities) {
        $hook = new WP_Hook;

        foreach ($priorities as $priority => $callbacks) {
            foreach ($callbacks as $i => [$function, $args]) {
                $hook->callbacks[$priority][$name.'_'.$priority.'_'.$i] = ['function' => $function, 'accepted_args' => $args];
            }
        }

        $filter[$name] = $hook;
    }

    return $filter;
}

it('finds no callbacks when nothing of the plugin is hooked', function (): void {
    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        'the_content' => [10 => [['wpautop', 1], [[new Elsewhere\ACFGetFieldIntercept, 'filter'], 1]]],
    ]);

    $guard = new HookGuard;

    expect($guard->has_intercepts())->toBeFalse()
        ->and($guard->callback_count())->toBe(0)
        ->and($guard->hook_names())->toBe([]);
});

it('skips a hook entry that is not a WP_Hook', function (): void {
    $GLOBALS['wp_filter'] = ['current_filter' => ['not', 'a hook'], 'doing_it' => false]
        + hook_guard_wp_filter(['acf/load_value' => [10 => [[[new ACFGetFieldIntercept, 'filter'], 3]]]]);

    $guard = new HookGuard;

    expect($guard->callback_count())->toBe(1)
        ->and($guard->hook_names())->toBe(['acf/load_value']);
});

it('collects only array callbacks whose object is in the plugin namespace', function (): void {
    $plugin = new ACFGetFieldIntercept;

    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        // The plugin's callback comes last, so a skipped entry that stopped the scan would lose it.
        'acf/load_value' => [10 => [
            ['wpautop', 1],                                     // a string callback
            [fn (mixed $v): mixed => $v, 1],                    // a closure
            [[ACFGetFieldIntercept::class, 'filter'], 1], // a static callable, no object
            [[new Elsewhere\ACFGetFieldIntercept, 'filter'], 1], // an object outside the namespace
            [[$plugin, 'filter'], 3],
        ]],
    ]);

    $guard = new HookGuard;

    expect($guard->callback_count())->toBe(1);

    Functions\expect('remove_filter')->once()->with('acf/load_value', [$plugin, 'filter'], 10);

    $guard->detach();
});

it('reports hook names once each, in the order first met', function (): void {
    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        'acf/load_value' => [
            10 => [[[new ACFGetFieldIntercept, 'filter'], 3]],
            20 => [[[new ACFUpdateFieldIntercept, 'filter'], 3]],
        ],
        'acf/update_value' => [10 => [[[new ACFUpdateFieldIntercept, 'filter'], 3]]],
    ]);

    $guard = new HookGuard;

    expect($guard->has_intercepts())->toBeTrue()
        ->and($guard->callback_count())->toBe(3)
        ->and($guard->hook_names())->toBe(['acf/load_value', 'acf/update_value']);
});

it('starts attached', function (): void {
    expect((new HookGuard)->is_detached())->toBeFalse();
});

it('removes every collected callback on detach with its priority', function (): void {
    $get = new ACFGetFieldIntercept;
    $update = new ACFUpdateFieldIntercept;

    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        'acf/load_value' => [10 => [[[$get, 'filter'], 3]]],
        'acf/update_value' => [25 => [[[$update, 'filter'], 4]]],
    ]);

    $guard = new HookGuard;

    Functions\expect('remove_filter')->once()->with('acf/load_value', [$get, 'filter'], 10);
    Functions\expect('remove_filter')->once()->with('acf/update_value', [$update, 'filter'], 25);

    $guard->detach();

    expect($guard->is_detached())->toBeTrue();
});

it('does nothing on a second detach', function (): void {
    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        'acf/load_value' => [10 => [[[new ACFGetFieldIntercept, 'filter'], 3]]],
    ]);

    $guard = new HookGuard;

    Functions\expect('remove_filter')->once();

    $guard->detach();
    $guard->detach();
});

it('does nothing on attach while attached', function (): void {
    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        'acf/load_value' => [10 => [[[new ACFGetFieldIntercept, 'filter'], 3]]],
    ]);

    $guard = new HookGuard;

    Functions\expect('add_filter')->never();

    $guard->attach();

    expect($guard->is_detached())->toBeFalse();
});

it('re-adds each callback with its priority and accepted arguments on attach', function (): void {
    $get = new ACFGetFieldIntercept;
    $update = new ACFUpdateFieldIntercept;

    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        'acf/load_value' => [10 => [[[$get, 'filter'], 3]]],
        'acf/update_value' => [25 => [[[$update, 'filter'], 4]]],
    ]);

    $guard = new HookGuard;

    when('remove_filter')->justReturn(true);
    $guard->detach();

    Functions\expect('add_filter')->once()->with('acf/load_value', [$get, 'filter'], 10, 3);
    Functions\expect('add_filter')->once()->with('acf/update_value', [$update, 'filter'], 25, 4);

    $guard->attach();

    expect($guard->is_detached())->toBeFalse();
});

it('can detach again after attaching', function (): void {
    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        'acf/load_value' => [10 => [[[new ACFGetFieldIntercept, 'filter'], 3]]],
    ]);

    $guard = new HookGuard;

    Functions\expect('remove_filter')->twice();
    when('add_filter')->justReturn(true);

    $guard->detach();
    $guard->attach();
    $guard->detach();

    expect($guard->is_detached())->toBeTrue();
});

it('resets the get-field intercept on every mode switch and on request', function (): void {
    $get = new ACFGetFieldIntercept;

    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        'acf/load_value' => [10 => [[[$get, 'filter'], 3], [[$get, 'filter'], 3]]],
    ]);

    $guard = new HookGuard;

    when('remove_filter')->justReturn(true);
    when('add_filter')->justReturn(true);

    $guard->detach();
    expect($get->resets)->toBe(1);

    $guard->attach();
    expect($get->resets)->toBe(2);

    $guard->reset_caches();
    expect($get->resets)->toBe(3);
});

it('resets only the get-field intercepts that can be reset', function (): void {
    $noReset = new NoResetACFGetFieldIntercept;
    $update = new ACFUpdateFieldIntercept;
    $other = new Elsewhere\ACFGetFieldIntercept;

    $GLOBALS['wp_filter'] = hook_guard_wp_filter([
        'acf/load_value' => [10 => [[[$noReset, 'filter'], 1], [[$update, 'filter'], 1], [[$other, 'filter'], 1]]],
    ]);

    $guard = new HookGuard;

    when('remove_filter')->justReturn(true);

    $guard->detach();

    expect($update->resets)->toBe(0)
        ->and($other->resets)->toBe(0);
});

it('flushes ACF values only when ACF is loaded, and resets the values store', function (): void {
    expect(function_exists('acf_get_store'))->toBeFalse();

    HookGuard::flush_acf_values();

    $store = Mockery::mock('stdClass');
    $store->shouldReceive('reset')->once();

    Functions\expect('acf_get_store')->once()->with('values')->andReturn($store);

    HookGuard::flush_acf_values();
});

it('tolerates acf_get_store() returning nothing', function (): void {
    Functions\expect('acf_get_store')->once()->with('values')->andReturn(false);

    HookGuard::flush_acf_values();
});

it('flushes the ACF values store whenever the caches are reset', function (): void {
    $store = Mockery::mock('stdClass');
    $store->shouldReceive('reset')->once();

    Functions\expect('acf_get_store')->once()->with('values')->andReturn($store);

    (new HookGuard)->reset_caches();
});

it('reports the plugin as not loaded, then loaded once its core class exists', function (): void {
    // Order matters: a class cannot be unloaded, so the negative case comes first.
    $asked = [];
    spl_autoload_register(function (string $class) use (&$asked): void {
        $asked[] = $class;
    });

    expect(function_exists('acf_custom_database_tables'))->toBeFalse()
        ->and(HookGuard::plugin_loaded())->toBeFalse()
        ->and($asked)->toBe([]); // the check must not trigger an autoload of the plugin's class

    require_once __DIR__.'/Fixtures/acf-cdt-core.php';

    expect(HookGuard::plugin_loaded())->toBeTrue();
});

it('names the plugin namespace', function (): void {
    expect(HookGuard::PLUGIN_NS)->toBe('ACFCustomDatabaseTables\\');
});
