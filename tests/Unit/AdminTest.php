<?php

/**
 * Admin: the wp-admin screens, the AJAX endpoint and the downloads, with WordPress, the Engine, the Site and the
 * Store mocked.
 *
 * WordPress' escaping, URL and capability functions are Brain Monkey stand-ins that behave like the real ones for
 * the inputs used here. The JSON responders throw where the real ones `die()` (and ignore any send after the first,
 * as a `die()` would), `Admin::finish()` throws where the download would `exit`, and `header()`, `set_time_limit()`
 * and `ignore_user_abort()` are namespace shims (tests/Unit/Fixtures/admin-shims.php) that record their calls. The
 * Engine, Site and Store methods are replaced with Patchwork, so each test states what the layer below returns.
 * What those layers do with a real database is for the Integration suite.
 *
 * Mutation run: `vendor/bin/pest tests/Unit/AdminTest.php --mutate --path=includes
 * --class='UAMSWP_ACFCDT_Migrator\Admin' --covered-only`, 482 mutants, 0 survivors, no timeouts (macOS, PHP 8.4.27,
 * PCOV; a working run, not the Linux run that closes the coverage ticket). Nine mutants are annotated in the source,
 * each an equivalent: a `%d` slot converts to an integer itself, a name that is empty or unmatched reaches the same
 * error, and `! $has_tables` reads an array and a boolean alike. The paging stub throws on a fourth call, so a
 * download loop that stops advancing its cursor fails instead of timing out.
 *
 * `finish()` holds the one `exit` and is excluded from coverage; the three downloads that end in it are tested up
 * to that call.
 *
 * The inline script and stylesheet are static markup (`Admin::script()` and `styles()` only add the settings
 * object), so the tests pin that object and that the blocks are emitted, not the JavaScript, which is for a browser.
 *
 * @see includes/class-admin.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWP_ACFCDT_Migrator\Admin;
use UAMSWP_ACFCDT_Migrator\Engine;
use UAMSWP_ACFCDT_Migrator\Site;
use UAMSWP_ACFCDT_Migrator\Store;

use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;

require_once __DIR__.'/Fixtures/process-shims.php';
require_once __DIR__.'/Fixtures/admin-shims.php';

beforeEach(function (): void {
    AdminTestSpy::reset();
    CommandTestProcess::reset();

    $GLOBALS['adm_sent'] = null;
    $GLOBALS['adm_finished'] = false;
    $GLOBALS['adm_can'] = true;
    $GLOBALS['adm_multisite'] = false;
    $GLOBALS['adm_nonce_ok'] = true;
    $GLOBALS['adm_caps'] = [];
    $GLOBALS['adm_nonce_calls'] = [];
    $GLOBALS['adm_admin_nonce_calls'] = [];
    $GLOBALS['adm_blog'] = 0;
    $GLOBALS['adm_blog_log'] = [];
    $GLOBALS['adm_blog_types'] = [];
    $_POST = [];
    $_GET = [];

    when('is_multisite')->alias(fn (): bool => $GLOBALS['adm_multisite']);
    when('current_user_can')->alias(function (string $cap): bool {
        $GLOBALS['adm_caps'][] = $cap;

        return $GLOBALS['adm_can'];
    });
    when('check_ajax_referer')->alias(function (...$args): bool {
        $GLOBALS['adm_nonce_calls'][] = $args;

        return $GLOBALS['adm_nonce_ok'];
    });
    when('check_admin_referer')->alias(function (...$args): int {
        $GLOBALS['adm_admin_nonce_calls'][] = $args;

        return 1;
    });
    when('wp_send_json_success')->alias(function (mixed $data = null): never {
        $GLOBALS['adm_sent'] ??= new AdminTestSent(true, $data);

        throw $GLOBALS['adm_sent'];
    });
    when('wp_send_json_error')->alias(function (mixed $data = null, ?int $status = null): never {
        $GLOBALS['adm_sent'] ??= new AdminTestSent(false, $data, $status);

        throw $GLOBALS['adm_sent'];
    });
    when('wp_die')->alias(function (string $message = ''): never {
        // `finish()` ends the request, so a `wp_die()` its exception reaches would never have run.
        $GLOBALS['adm_finished'] && throw new AdminTestFinished;

        throw new AdminTestDied($message);
    });
    when('wp_unslash')->alias(fn (mixed $v): mixed => is_array($v) ? array_map(fn ($x): mixed => is_string($x) ? stripslashes($x) : $x, $v) : $v);
    when('sanitize_key')->alias(fn (string $k): string => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)));
    when('sanitize_file_name')->alias(fn (string $f): string => str_replace(' ', '-', $f));
    when('nocache_headers')->alias(function (): void {
        AdminTestSpy::$calls[] = ['nocache_headers'];
    });
    when('get_current_blog_id')->alias(fn (): int => $GLOBALS['adm_blog'] ?: 7);
    when('home_url')->justReturn('https://example.test/');
    when('esc_html')->alias(fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES));
    when('esc_attr')->alias(fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES));
    when('esc_url')->alias(fn (mixed $s): string => str_replace('&', '&#038;', (string) $s));
    when('admin_url')->alias(fn (string $p = ''): string => 'https://example.test/wp-admin/'.$p);
    when('get_admin_url')->alias(fn (int $blog, string $p = ''): string => "https://example.test/blog-$blog/wp-admin/$p");
    when('add_query_arg')->alias(function (mixed $a, mixed $b = null, mixed $c = null): string {
        [$args, $url] = is_array($a) ? [$a, (string) $b] : [[$a => $b], (string) $c];

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($args);
    });
    when('wp_nonce_url')->alias(fn (string $url, string $action): string => $url.'&_wpnonce=nonce-'.$action);
    when('wp_create_nonce')->alias(fn (string $a): string => 'nonce-'.$a);
    when('wp_json_encode')->alias(fn (mixed $v, int $flags = 0): string|false => json_encode($v, $flags));
    when('selected')->alias(function (mixed $selected, mixed $current = true, bool $echo = true): string {
        $out = (string) $selected === (string) $current ? " selected='selected'" : '';
        if ($echo) {
            echo $out;
        }

        return $out;
    });
    when('disabled')->alias(function (mixed $disabled, mixed $current = true, bool $echo = true): string {
        $out = (string) $disabled === (string) $current ? " disabled='disabled'" : '';
        if ($echo) {
            echo $out;
        }

        return $out;
    });
    when('switch_to_blog')->alias(function (mixed $id): bool {
        // The type is recorded because a typed parameter would coerce a numeric string without a word.
        $GLOBALS['adm_blog_types'][] = get_debug_type($id);
        $GLOBALS['adm_blog'] = $id;
        $GLOBALS['adm_blog_log'][] = "switch $id";

        return true;
    });
    when('restore_current_blog')->alias(function (): bool {
        $GLOBALS['adm_blog'] = 0;
        $GLOBALS['adm_blog_log'][] = 'restore';

        return true;
    });

    // The Engine's constructor installs the log table; nothing here has a database.
    redefine(Store::class.'::install', function (): void {
        $GLOBALS['adm_blog_log'][] = 'install';
    });
    redefine(Engine::class.'::acf_ready', fn (): bool => true);
    redefine(Admin::class.'::finish', function (): never {
        $GLOBALS['adm_finished'] = true;

        throw new AdminTestFinished;
    });
});

afterEach(function (): void {
    unset(
        $GLOBALS['adm_sent'], $GLOBALS['adm_finished'], $GLOBALS['adm_can'], $GLOBALS['adm_multisite'], $GLOBALS['adm_nonce_ok'], $GLOBALS['adm_caps'],
        $GLOBALS['adm_nonce_calls'], $GLOBALS['adm_admin_nonce_calls'], $GLOBALS['adm_blog'], $GLOBALS['adm_blog_log'], $GLOBALS['adm_defs']
    );
    $_POST = [];
    $_GET = [];
});

/**
 * Replaces Engine instance methods.
 *
 * @param  array<string, Closure>  $methods
 */
function adm_engine(array $methods): void
{
    foreach ($methods as $name => $fn) {
        redefine(Engine::class.'::'.$name, $fn);
    }
}

/**
 * Replaces Store static methods.
 *
 * @param  array<string, Closure>  $methods
 */
function adm_store(array $methods): void
{
    foreach ($methods as $name => $fn) {
        redefine(Store::class.'::'.$name, $fn);
    }
}

/**
 * Runs the AJAX endpoint with a request body and returns what it sent.
 *
 * @param  array<string, mixed>  $post
 */
function adm_ajax(array $post): AdminTestSent
{
    $_POST = $post;

    try {
        Admin::ajax();
    } catch (AdminTestSent $adminTestSent) {
        return $adminTestSent;
    }

    throw new LogicException('Nothing was sent.');
}

/**
 * Runs the download handler and returns what it printed, stopping where it would exit.
 *
 * @param  array<string, mixed>  $get
 */
function adm_download(array $get): string
{
    $_GET = $get;
    ob_start();

    try {
        Admin::download();
    } catch (AdminTestFinished) {
        // The download is complete.
    } finally {
        $out = (string) ob_get_clean();
    }

    return $out;
}

/** Runs a screen and returns its output with whitespace runs collapsed. */
function adm_capture(Closure $render, bool $collapse = true): string
{
    ob_start();

    try {
        $render();
    } finally {
        $out = (string) ob_get_clean();
    }

    return $collapse ? trim((string) preg_replace('/\s+/', ' ', $out)) : $out;
}

/**
 * The headers `Admin::headers()` sent.
 *
 * @return list<string>
 */
function adm_headers(): array
{
    return array_values(array_map(fn (array $c): string => $c[1], array_filter(AdminTestSpy::$calls, fn (array $c): bool => $c[0] === 'header')));
}

/**
 * An inventory with every key the site page reads.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function adm_inventory(array $over = []): array
{
    return $over + [
        'blog_id' => 3,
        'url' => 'https://example.test/',
        'json_dir' => '/data/json',
        'plugin_loaded' => true,
        'intercepts' => '4.9',
        'core_meta' => true,
        'cache' => [],
        'cache_problems' => [],
        'tables' => [],
    ];
}

/**
 * A table row of the inventory that exists.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function adm_table(array $over = []): array
{
    return $over + [
        'table' => 'wp_provider',
        'name' => 'provider',
        'post_type' => 'provider',
        'exists' => true,
        'rows' => 5,
        'missing_post' => 1,
        'wrong_type' => 2,
        'no_row' => 3,
        'columns' => [],
        'orphans' => [],
    ];
}

/**
 * Renders the site page with a given inventory and stored headers.
 *
 * @param  array<string, mixed>  $inv
 * @param  array<string, array<string, array<string, mixed>>>  $headers  Header rows by kind (snapinfo, run, rollback).
 */
function adm_page(array $inv, array $headers = [], ?Closure $posts_upto = null): string
{
    adm_engine([
        'inventory' => fn (): array => $inv,
        'posts_upto' => $posts_upto ?? fn ($cursor): int => (int) $cursor * 10,
    ]);
    adm_store([
        'headers' => fn ($kind): array => $headers[$kind] ?? [],
        'count' => fn ($kind, $ref, $where_sql = ''): int => 42,
        'change_summary' => fn ($stamp): array => [
            'stats' => ['fields' => 8, 'fill' => 5, 'overwrite' => 2, 'clear' => 1, 'skipped' => 0, 'invalid' => 0, 'write_mismatch' => 0, 'posts' => 3],
            'by_field' => [],
        ],
    ]);

    return adm_capture(fn () => Admin::page());
}

/* ====================================================================== registration and capability */

it('registers its four hooks', function (): void {
    Admin::init();

    expect(has_action('admin_menu', [Admin::class, 'menu']))->toBe(10)
        ->and(has_action('network_admin_menu', [Admin::class, 'network_menu']))->toBe(10)
        ->and(has_action('wp_ajax_uamswp_acfcdt_mig', [Admin::class, 'ajax']))->toBe(10)
        ->and(has_action('admin_post_uamswp_acfcdt_mig_dl', [Admin::class, 'download']))->toBe(10);
});

it('names its slug, action and nonce', function (): void {
    expect(Admin::SLUG)->toBe('uamswp-acfcdt-migration')
        ->and(Admin::ACTION)->toBe('uamswp_acfcdt_mig')
        ->and(Admin::NONCE)->toBe('uamswp_acfcdt_mig');
});

it('asks for manage_options on a single site', function (): void {
    expect(Admin::can())->toBeTrue()
        ->and($GLOBALS['adm_caps'])->toBe(['manage_options']);

    $GLOBALS['adm_can'] = false;

    expect(Admin::can())->toBeFalse();
});

it('asks for manage_network_options on a network', function (): void {
    $GLOBALS['adm_multisite'] = true;

    expect(Admin::can())->toBeTrue()
        ->and($GLOBALS['adm_caps'])->toBe(['manage_network_options']);
});

it('adds the Tools page for a user who may use it', function (): void {
    Functions\expect('add_management_page')->once()->with('ACFCDT Migration', 'ACFCDT Migration', 'manage_options', 'uamswp-acfcdt-migration', [Admin::class, 'page']);

    Admin::menu();
});

it('adds the Tools page with the network capability on a network', function (): void {
    $GLOBALS['adm_multisite'] = true;
    Functions\expect('add_management_page')->once()->with('ACFCDT Migration', 'ACFCDT Migration', 'manage_network_options', 'uamswp-acfcdt-migration', [Admin::class, 'page']);

    Admin::menu();
});

it('adds no Tools page for a user who may not use it', function (): void {
    $GLOBALS['adm_can'] = false;
    Functions\expect('add_management_page')->never();

    Admin::menu();
});

it('adds the network settings page', function (): void {
    Functions\expect('add_submenu_page')->once()->with('settings.php', 'ACFCDT Migration', 'ACFCDT Migration', 'manage_network_options', 'uamswp-acfcdt-migration', [Admin::class, 'network_page']);

    Admin::network_menu();
});

/* ====================================================================== network overview */

/**
 * Sets the sites and their active table definitions for the overview.
 *
 * @param  list<object>  $sites
 * @param  array<int, list<string>>  $tables  Active table names by blog id.
 * @param  array<int, array<string, array<string, array<string, mixed>>>>  $headers  Header rows by blog id and kind.
 */
function adm_network(array $sites, array $tables, array $headers = []): string
{
    $GLOBALS['adm_defs'] = $tables;
    Functions\expect('get_sites')->once()->with(['number' => 0, 'deleted' => 0])->andReturn($sites);
    redefine(Site::class.'::active_defs', function (): array {
        $names = $GLOBALS['adm_defs'][$GLOBALS['adm_blog']] ?? [];

        return array_fill_keys($names, ['name' => 'x']);
    });
    adm_store(['headers' => fn ($kind): array => $headers[$GLOBALS['adm_blog']][$kind] ?? []]);

    return adm_capture(fn () => Admin::network_page());
}

it('refuses the network overview to a user who may not use it', function (): void {
    $GLOBALS['adm_can'] = false;

    expect(fn () => Admin::network_page())->toThrow(AdminTestDied::class, 'Not allowed.');
});

it('says no site has tables when none does, and still visits every site', function (): void {
    $html = adm_network(
        [(object) ['blog_id' => 2, 'domain' => 'a.test', 'path' => '/'], (object) ['blog_id' => 5, 'domain' => 'b.test', 'path' => '/b/']],
        [],
    );

    expect($html)->toContain("<h1>ACFCDT Migration \u{2014} network overview</h1>")
        ->and($html)->toContain('Each site is migrated from its own <strong>Tools')
        ->and($html)->toContain('<tr><td colspan="5">No site has ACF Custom Database Tables tables.</td></tr>')
        ->and($html)->toEndWith('</tbody></table></div>')
        ->and($GLOBALS['adm_blog_log'])->toBe(['switch 2', 'restore', 'switch 5', 'restore']);
});

it('lists a site that has tables with its snapshots and latest real backfill', function (): void {
    $html = adm_network(
        [(object) ['blog_id' => 2, 'domain' => 'a.test', 'path' => '/'], (object) ['blog_id' => 5, 'domain' => 'b.test', 'path' => '/b/']],
        [5 => ['provider', 'location']],
        [5 => [
            'snapinfo' => ['before' => ['status' => 'complete'], 'mid' => ['status' => 'running'], 'odd' => []],
            'run' => [
                'dry-1' => ['dry' => true, 'status' => 'complete', 'stats' => ['fields' => 99]],
                'run-2' => ['status' => 'complete', 'stats' => ['fields' => '12.7', 'write_mismatch' => '3.9']],
                'run-1' => ['status' => 'complete', 'stats' => ['fields' => 1]],
            ],
        ]],
    );

    expect($html)->toContain('<tr><td><strong>5</strong> b.test/b/</td><td>provider, location</td>')
        ->and($html)->toContain('<td>before, mid <em>(incomplete)</em>, odd <em>(incomplete)</em></td>')
        ->and($html)->toContain('<td>run-2 (complete, 12 fields, 3 MISMATCHES)</td>')
        ->and($html)->toContain('<a class="button" href="https://example.test/blog-5/wp-admin/tools.php?page=uamswp-acfcdt-migration">Open</a>')
        ->and($html)->not->toContain('No site has')
        ->and($html)->not->toContain('a.test')
        ->and($GLOBALS['adm_blog_log'])->toBe(['switch 2', 'restore', 'switch 5', 'install', 'restore']);
});

it('switches to each site by integer ID when the site object holds it as a string', function (): void {
    adm_network(
        [(object) ['blog_id' => '5', 'domain' => 'b.test', 'path' => '/']],
        [5 => ['provider']],
        [5 => ['run' => ['run-x' => ['stats' => ['write_mismatch' => 0]]]]],
    );

    expect($GLOBALS['adm_blog_log'])->toBe(['switch 5', 'install', 'restore'])
        ->and($GLOBALS['adm_blog_types'])->toBe(['int']);
});

it('shows a dash when a site has no snapshots and no real run', function (): void {
    $html = adm_network(
        [(object) ['blog_id' => 5, 'domain' => 'b.test', 'path' => '/']],
        [5 => ['provider']],
        [5 => ['run' => ['dry-1' => ['dry' => true]]]],
    );

    expect($html)->toContain("<td>\u{2014}</td><td>\u{2014}</td><td><a")
        ->and($html)->toContain('<td>provider</td>');
});

it('fills in what a run header leaves out', function (): void {
    $html = adm_network(
        [(object) ['blog_id' => 5, 'domain' => 'b.test', 'path' => '/']],
        [5 => ['provider']],
        [5 => ['run' => ['run-x' => ['stats' => ['write_mismatch' => 0]]]]],
    );

    expect($html)->toContain('<td>run-x (?)</td>');
});

it('escapes the names it prints', function (): void {
    $html = adm_network(
        [(object) ['blog_id' => 5, 'domain' => 'b<i>.test', 'path' => '/']],
        [5 => ['a<b']],
        [5 => ['snapinfo' => ['s<1' => ['status' => 'complete']], 'run' => ['r<1' => ['status' => 'x<y']]]],
    );

    expect($html)->toContain('b&lt;i&gt;.test/')
        ->and($html)->toContain('<td>a&lt;b</td>')
        ->and($html)->toContain('<td>s&lt;1</td>')
        ->and($html)->toContain('<td>r&lt;1 (x&lt;y)</td>');
});

/* ====================================================================== AJAX */

it('refuses an AJAX call from a user who may not use it, without checking the nonce', function (): void {
    $GLOBALS['adm_can'] = false;

    $sent = adm_ajax(['op' => 'compare']);

    expect($sent->success)->toBeFalse()
        ->and($sent->status)->toBe(403)
        ->and($sent->data)->toBe(['message' => "Not allowed (permission or expired nonce \u{2014} reload the page)."])
        ->and($GLOBALS['adm_nonce_calls'])->toBe([]);
});

it('refuses an AJAX call with a bad nonce, and checks the nonce without dying', function (): void {
    $GLOBALS['adm_nonce_ok'] = false;

    $sent = adm_ajax(['op' => 'compare']);

    expect($sent->status)->toBe(403)
        ->and($GLOBALS['adm_nonce_calls'])->toBe([['uamswp_acfcdt_mig', 'nonce', false]]);
});

it('lifts the time limit and ignores a closed browser for each batch', function (): void {
    adm_ajax(['op' => 'nope']);

    expect(AdminTestSpy::$calls)->toBe([['set_time_limit', 120], ['ignore_user_abort', true]]);
});

it('stops when ACF is not active', function (): void {
    redefine(Engine::class.'::acf_ready', fn (): bool => false);

    $sent = adm_ajax(['op' => 'snapshot_batch']);

    expect($sent->success)->toBeFalse()
        ->and($sent->status)->toBeNull()
        ->and($sent->data)->toBe(['message' => 'ACF is not active.']);
});

it('rejects an unknown or missing operation', function (): void {
    expect(adm_ajax(['op' => 'nope'])->data)->toBe(['message' => 'Unknown operation.'])
        ->and(adm_ajax([])->data)->toBe(['message' => 'Unknown operation.']);
});

it('rejects a reference with characters it does not allow', function (): void {
    $sent = adm_ajax(['op' => 'snapshot_batch', 'ref' => 'bad name']);

    expect($sent->success)->toBeFalse()
        ->and($sent->data['message'])->toContain("'bad name'");
});

it('normalizes the operation name before matching it', function (): void {
    adm_engine(['snapshot_batch' => fn ($ref, $cursor, $limit): array => ['seen' => $ref]]);

    $sent = adm_ajax(['op' => 'Snapshot_Batch!', 'ref' => 'r']);

    expect($sent->success)->toBeTrue()
        ->and($sent->data)->toBe(['seen' => 'r']);
});

it('starts a snapshot with the label, mode and overwrite flag, and reports the post total', function (): void {
    $args = new ArrayObject;
    adm_engine([
        'snapshot_start' => function ($label, $mode, $overwrite = false) use ($args): string {
            $args[] = [$label, $mode, $overwrite];

            return 'before';
        },
        'total_posts' => fn ($ids = null): int => 31,
    ]);

    $sent = adm_ajax(['op' => 'snapshot_start', 'label' => 'be\\fore', 'mode' => 'meta', 'overwrite' => '1']);

    expect($sent->success)->toBeTrue()
        ->and($sent->data)->toBe(['ref' => 'before', 'total' => 31])
        ->and($args->getArrayCopy())->toBe([['before', 'meta', true]]);

    adm_ajax(['op' => 'snapshot_start', 'label' => 'x', 'mode' => 'effective']);
    adm_ajax(['op' => 'snapshot_start', 'label' => 'x', 'mode' => 'effective', 'overwrite' => '']);

    expect($args->getArrayCopy())->toBe([
        ['before', 'meta', true],
        ['x', 'effective', false],
        ['x', 'effective', false],
    ]);
});

it('runs a snapshot batch with the cursor and limit it was given', function (): void {
    $args = new ArrayObject;
    adm_engine(['snapshot_batch' => function ($label, $cursor, $limit, $ids = null) use ($args): array {
        $args[] = [$label, $cursor, $limit, $ids];

        return ['cursor' => 90, 'done' => false];
    }]);

    $sent = adm_ajax(['op' => 'snapshot_batch', 'ref' => 'before', 'cursor' => '40', 'limit' => '25']);

    expect($sent->data)->toBe(['cursor' => 90, 'done' => false])
        ->and($args->getArrayCopy())->toBe([['before', 40, 25, null]]);
});

it('defaults the cursor to the start, the limit to 10 and the reference to nothing', function (): void {
    $args = new ArrayObject;
    adm_engine(['backfill_batch' => function ($stamp, $cursor, $limit, $ids = null) use ($args): array {
        $args[] = [$stamp, $cursor, $limit];

        return [];
    }]);

    adm_ajax(['op' => 'backfill_batch']);

    expect($args->getArrayCopy())->toBe([['', 0, 10]]);
});

it('keeps the batch limit between 1 and 200', function (int|string $given, int $used): void {
    $limits = new ArrayObject;
    adm_engine(['rollback_batch' => function ($stamp, $cursor, $limit) use ($limits): array {
        $limits[] = $limit;

        return [];
    }]);

    adm_ajax(['op' => 'rollback_batch', 'ref' => 'r', 'limit' => $given]);

    expect($limits->getArrayCopy())->toBe([$used]);
})->with([
    'zero is raised to one' => [0, 1],
    'negative is raised to one' => [-5, 1],
    'one is kept' => [1, 1],
    'in range is kept' => ['50', 50],
    '200 is kept' => [200, 200],
    '201 is lowered to 200' => [201, 200],
    'large is lowered to 200' => [5000, 200],
]);

it('starts a backfill with its three flags and reports the post total', function (): void {
    $args = new ArrayObject;
    adm_engine([
        'backfill_start' => function ($dry, $no_clear, $stamp = null, $allow_stale_cache = false) use ($args): string {
            $args[] = [$dry, $no_clear, $stamp, $allow_stale_cache];

            return 'bf-1';
        },
        'total_posts' => fn ($ids = null): int => 12,
    ]);

    $all = adm_ajax(['op' => 'backfill_start', 'dry' => '1', 'no_clear' => '1', 'allow_stale' => '1']);
    $none = adm_ajax(['op' => 'backfill_start']);

    expect($all->data)->toBe(['ref' => 'bf-1', 'total' => 12])
        ->and($none->data)->toBe(['ref' => 'bf-1', 'total' => 12])
        ->and($args->getArrayCopy())->toBe([[true, true, null, true], [false, false, null, false]]);
});

it('runs a backfill batch', function (): void {
    adm_engine(['backfill_batch' => fn ($stamp, $cursor, $limit, $ids = null): array => ['stamp' => $stamp, 'cursor' => $cursor, 'limit' => $limit]]);

    expect(adm_ajax(['op' => 'backfill_batch', 'ref' => 'bf-1', 'cursor' => '7', 'limit' => '3'])->data)->toBe(['stamp' => 'bf-1', 'cursor' => 7, 'limit' => 3]);
});

it('starts a rollback forced and reports how many backups it will restore', function (): void {
    $args = new ArrayObject;
    adm_engine(['rollback_start' => function ($stamp, $force = false) use ($args): string {
        $args[] = [$stamp, $force];

        return 'x';
    }]);
    adm_store(['count' => function ($kind, $ref, $where_sql = '') use ($args): int {
        $args[] = [$kind, $ref];

        return 88;
    }]);

    $sent = adm_ajax(['op' => 'rollback_start', 'ref' => 'bf-1']);

    expect($sent->data)->toBe(['ref' => 'bf-1', 'total' => 88])
        ->and($args->getArrayCopy())->toBe([['bf-1', true], ['backup', 'bf-1']]);
});

it('runs a rollback batch', function (): void {
    adm_engine(['rollback_batch' => fn ($stamp, $cursor, $limit): array => [$stamp, $cursor, $limit]]);

    expect(adm_ajax(['op' => 'rollback_batch', 'ref' => 'bf-1', 'cursor' => '5', 'limit' => '100'])->data)->toBe(['bf-1', 5, 100]);
});

/**
 * Stubs `Engine::compare()` to return the given diffs and records its arguments.
 *
 * @param  list<array<string, mixed>>  $diffs
 * @param  ArrayObject<int, mixed>|null  $args
 */
function adm_compare(array $diffs, ?ArrayObject $args = null): void
{
    adm_engine(['compare' => function ($a, $b, array $ignore = []) use ($diffs, $args): array {
        $args?->append([$a, $b, $ignore]);

        return ['summary' => ['identical' => $diffs === []], 'diffs' => $diffs];
    }]);
}

it('compares two snapshots, ignoring the fields listed, and adds the download link', function (): void {
    $args = new ArrayObject;
    adm_compare([['post_id' => 1, 'field' => 'f', 'a' => 'one', 'b' => ['k' => 'v']], ['post_id' => 2, 'field' => 'g', 'a' => null, 'b' => 'two']], $args);

    $sent = adm_ajax(['op' => 'compare', 'a' => 'before', 'b' => 'after', 'ignore' => 'x, y ,,z']);

    expect($sent->success)->toBeTrue()
        ->and($sent->data['summary'])->toBe(['identical' => false])
        ->and($sent->data['total_diffs'])->toBe(2)
        ->and($sent->data['diffs'])->toBe([
            ['post_id' => 1, 'field' => 'f', 'a' => 'one', 'b' => '{"k":"v"}'],
            ['post_id' => 2, 'field' => 'g', 'a' => '(empty)', 'b' => 'two'],
        ])
        ->and($sent->data['download'])->toBe('https://example.test/wp-admin/admin-post.php?action=uamswp_acfcdt_mig_dl&type=compare&a=before&b=after&ignore=x%2C+y+%2C%2Cz&_wpnonce=nonce-uamswp_acfcdt_mig')
        ->and($args->getArrayCopy())->toBe([['before', 'after', [0 => 'x', 1 => 'y', 3 => 'z']]]);
});

it('compares with nothing ignored when no list is given', function (): void {
    $args = new ArrayObject;
    adm_compare([], $args);

    $sent = adm_ajax(['op' => 'compare', 'a' => 'before', 'b' => 'after']);

    expect($args->getArrayCopy())->toBe([['before', 'after', []]])
        ->and($sent->data['total_diffs'])->toBe(0)
        ->and($sent->data['diffs'])->toBe([])
        ->and($sent->data['download'])->toContain('&ignore=&_wpnonce');
});

it('returns at most 200 differences and the full total', function (): void {
    adm_compare(array_map(fn (int $i): array => ['post_id' => $i, 'field' => 'f', 'a' => 'a', 'b' => 'b'], range(1, 250)));

    $sent = adm_ajax(['op' => 'compare', 'a' => 'x', 'b' => 'y']);

    expect($sent->data['total_diffs'])->toBe(250)
        ->and($sent->data['diffs'])->toHaveCount(200)
        ->and($sent->data['diffs'][199]['post_id'])->toBe(200);
});

it('shortens a long value past 300 characters and leaves 300 whole', function (): void {
    adm_compare([['post_id' => 1, 'field' => 'f', 'a' => 'X'.str_repeat('a', 299), 'b' => 'Y'.str_repeat('b', 300)]]);

    $row = adm_ajax(['op' => 'compare', 'a' => 'x', 'b' => 'y'])->data['diffs'][0];

    expect($row['a'])->toBe('X'.str_repeat('a', 299))
        ->and($row['b'])->toBe('Y'.str_repeat('b', 296)."\u{2026}");
});

it('counts characters, not bytes, when shortening a value', function (): void {
    adm_compare([['post_id' => 1, 'field' => 'f', 'a' => str_repeat("\u{00e9}", 300), 'b' => str_repeat("\u{00e9}", 301)]]);

    $row = adm_ajax(['op' => 'compare', 'a' => 'x', 'b' => 'y'])->data['diffs'][0];

    expect($row['a'])->toBe(str_repeat("\u{00e9}", 300))
        ->and($row['b'])->toBe(str_repeat("\u{00e9}", 297)."\u{2026}");
});

it('deletes a snapshot and its rows', function (): void {
    $args = new ArrayObject;
    adm_store(['delete' => function (array $kinds, $ref) use ($args): void {
        $args[] = [$kinds, $ref];
    }]);

    $sent = adm_ajax(['op' => 'delete', 'kind' => 'snapshot', 'ref' => 'before']);

    expect($sent->data)->toBe(['deleted' => 'before'])
        ->and($args->getArrayCopy())->toBe([[['snapinfo', 'snapshot'], 'before']]);
});

it('deletes a run with its changes, backups and rollback', function (): void {
    $args = new ArrayObject;
    adm_store([
        'get' => fn ($kind, $ref, $post_id = 0, $field = ''): ?array => null,
        'delete' => function (array $kinds, $ref) use ($args): void {
            $args[] = [$kinds, $ref];
        },
    ]);

    $sent = adm_ajax(['op' => 'delete', 'kind' => 'run', 'ref' => 'bf-1']);

    expect($sent->data)->toBe(['deleted' => 'bf-1'])
        ->and($args->getArrayCopy())->toBe([[['run', 'change', 'backup', 'rollback'], 'bf-1']]);
});

it('looks the run and its rollback up before deleting a run', function (): void {
    $asked = new ArrayObject;
    adm_store([
        'get' => function ($kind, $ref, $post_id = 0, $field = '') use ($asked): ?array {
            $asked[] = [$kind, $ref];

            return null;
        },
        'delete' => fn (array $kinds, $ref): null => null,
    ]);

    adm_ajax(['op' => 'delete', 'kind' => 'run', 'ref' => 'bf-1']);

    expect($asked->getArrayCopy())->toBe([['run', 'bf-1'], ['rollback', 'bf-1']]);
});

/**
 * Sets what the Store holds for a run and its rollback and records what is deleted.
 *
 * @param  array<string, mixed>|null  $run
 * @param  array<string, mixed>|null  $rollback
 * @return ArrayObject<int, mixed>
 */
function adm_delete_store(?array $run, ?array $rollback): ArrayObject
{
    $deleted = new ArrayObject;
    adm_store([
        'get' => fn ($kind, $ref, $post_id = 0, $field = ''): ?array => $kind === 'run' ? $run : $rollback,
        'delete' => function (array $kinds, $ref) use ($deleted): void {
            $deleted[] = $ref;
        },
    ]);

    return $deleted;
}

it('refuses to delete a real run that still holds the only backups', function (?array $rollback): void {
    $deleted = adm_delete_store(['status' => 'complete'], $rollback);

    $sent = adm_ajax(['op' => 'delete', 'kind' => 'run', 'ref' => 'bf-1']);

    expect($sent->success)->toBeFalse()
        ->and($sent->data)->toBe(['message' => 'This real run still holds the only per-field backups of the old postmeta. Download them first, then confirm deletion.'])
        ->and($deleted->getArrayCopy())->toBe([]);
})->with([
    'no rollback' => [null],
    'rollback not complete' => [['status' => 'running']],
]);

it('deletes a real run once the backups are confirmed', function (): void {
    $deleted = adm_delete_store(['status' => 'complete'], null);

    $sent = adm_ajax(['op' => 'delete', 'kind' => 'run', 'ref' => 'bf-1', 'confirm_backups' => '1']);

    expect($sent->data)->toBe(['deleted' => 'bf-1'])
        ->and($deleted->getArrayCopy())->toBe(['bf-1']);
});

it('deletes a real run whose rollback is complete without confirmation', function (): void {
    $deleted = adm_delete_store(['status' => 'complete'], ['status' => 'complete']);

    expect(adm_ajax(['op' => 'delete', 'kind' => 'run', 'ref' => 'bf-1'])->success)->toBeTrue()
        ->and($deleted->getArrayCopy())->toBe(['bf-1']);
});

it('deletes a dry run without confirmation', function (): void {
    $deleted = adm_delete_store(['dry' => true, 'status' => 'complete'], null);

    expect(adm_ajax(['op' => 'delete', 'kind' => 'run', 'ref' => 'bf-1'])->success)->toBeTrue()
        ->and($deleted->getArrayCopy())->toBe(['bf-1']);
});

it('does not look a snapshot up as a run when deleting it', function (): void {
    adm_store([
        'get' => fn (): never => throw new LogicException('must not be reached'),
        'delete' => fn (array $kinds, $ref): null => null,
    ]);

    expect(adm_ajax(['op' => 'delete', 'kind' => 'snapshot', 'ref' => 'before'])->success)->toBeTrue();
});

it('reports a failure of the layer below as an error with its message', function (): void {
    adm_engine(['snapshot_batch' => fn (): never => throw new RuntimeException('Disk full.')]);

    $sent = adm_ajax(['op' => 'snapshot_batch', 'ref' => 'before']);

    expect($sent->success)->toBeFalse()
        ->and($sent->data)->toBe(['message' => 'Disk full.'])
        ->and($sent->status)->toBeNull();
});

/* ====================================================================== downloads */

/**
 * Stubs the site's active tables and the names derived from them.
 *
 * @param  array<string, array<string, mixed>>  $defs
 */
function adm_defs(array $defs): void
{
    redefine(Site::class.'::active_defs', fn (): array => $defs);
    redefine(Site::class.'::prefixed', fn ($table): string => 'wp_'.$table);
}

it('refuses a download to a user who may not use it, before checking the nonce', function (): void {
    $GLOBALS['adm_can'] = false;

    expect(fn (): string => adm_download(['type' => 'archive']))->toThrow(AdminTestDied::class, 'Not allowed.')
        ->and($GLOBALS['adm_admin_nonce_calls'])->toBe([]);
});

it('checks the nonce of a download', function (): void {
    adm_download(['type' => 'nope']);
})->throws(AdminTestDied::class);

it('checks the admin nonce with the plugin nonce name', function (): void {
    try {
        adm_download(['type' => 'nope']);
    } catch (AdminTestDied) {
        // Expected: an unknown type.
    }

    expect($GLOBALS['adm_admin_nonce_calls'])->toBe([['uamswp_acfcdt_mig']]);
});

it('lifts the time limit of a download to five minutes', function (): void {
    try {
        adm_download(['type' => 'nope']);
    } catch (AdminTestDied) {
        // Expected: an unknown type.
    }

    expect(AdminTestSpy::$calls)->toBe([['set_time_limit', 300]]);
});

it('refuses an unknown download type', function (string $type): void {
    expect(fn (): string => adm_download(['type' => $type]))->toThrow(AdminTestDied::class, 'Unknown download.');
})->with(['', 'nope', 'listing']);

it('escapes the message of a failed download', function (): void {
    adm_engine(['compare' => fn (): never => throw new RuntimeException('bad <b>')]);

    expect(fn (): string => adm_download(['type' => 'compare', 'a' => 'x', 'b' => 'y']))->toThrow(AdminTestDied::class, 'bad &lt;b&gt;');
});

it('streams every row of a table as JSON lines, with the download headers', function (): void {
    adm_defs(['provider' => ['name' => 'provider', 'columns' => []]]);
    adm_engine(['each_table_row' => function ($table, callable $emit): int {
        $emit(['post_id' => 1, 'url' => 'a/b', 'name' => "\u{00e9}"]);
        $emit(['post_id' => 2]);

        return 2;
    }]);

    $out = adm_download(['type' => 'archive', 'table' => 'provider']);

    expect($out)->toBe("{\"post_id\":1,\"url\":\"a/b\",\"name\":\"\u{00e9}\"}\n{\"post_id\":2}\n")
        ->and(adm_headers())->toBe([
            'Content-Type: application/x-ndjson; charset=utf-8',
            'Content-Disposition: attachment; filename="site-7-wp_provider.jsonl"',
            'X-Content-Type-Options: nosniff',
        ])
        ->and(AdminTestSpy::$calls[1])->toBe(['nocache_headers']);
});

it('passes the table name it was asked for to the Engine', function (): void {
    $asked = new ArrayObject;
    adm_defs(['provider' => ['name' => 'provider']]);
    adm_engine(['each_table_row' => function ($table, callable $emit) use ($asked): int {
        $asked[] = $table;

        return 0;
    }]);

    adm_download(['type' => 'archive', 'table' => 'provider']);

    expect($asked->getArrayCopy())->toBe(['provider']);
});

it('refuses an archive of a table that is not active', function (array $get): void {
    adm_defs(['provider' => ['name' => 'provider']]);

    expect(fn (): string => adm_download($get))->toThrow(AdminTestDied::class, 'Unknown table.');
})->with([
    'archive of an unknown table' => [['type' => 'archive', 'table' => 'other']],
    'archive with no table' => [['type' => 'archive']],
    'orphans of an unknown table' => [['type' => 'orphans', 'table' => 'other']],
]);

it('streams only the rows with something in an orphaned column, as CSV', function (): void {
    $def = ['name' => 'provider', 'columns' => []];
    $asked = new ArrayObject;
    adm_defs(['provider' => $def]);
    adm_engine([
        'orphan_columns' => function (array $d) use ($asked): array {
            $asked[] = $d;

            return ['old_a', 'old_b'];
        },
        'each_table_row' => function ($table, callable $emit): int {
            $emit(['post_id' => 1, 'old_a' => null, 'old_b' => '']);
            $emit(['post_id' => 2, 'old_a' => '[]', 'old_b' => '[]']);
            $emit(['post_id' => 3, 'old_a' => 'keep, this', 'old_b' => '']);
            $emit(['post_id' => 4, 'old_a' => '', 'old_b' => '0']);
            $emit(['post_id' => 5, 'old_a' => '', 'old_b' => 'x']);

            return 5;
        },
    ]);

    $out = adm_download(['type' => 'orphans', 'table' => 'provider']);

    expect($out)->toBe("post_id,old_a,old_b\n3,\"keep, this\",\n4,,0\n5,,x\n")
        ->and($asked->getArrayCopy())->toBe([$def])
        ->and(adm_headers())->toBe([
            'Content-Type: text/csv; charset=utf-8',
            'Content-Disposition: attachment; filename="site-7-wp_provider-orphan-columns.csv"',
            'X-Content-Type-Options: nosniff',
        ])
        ->and(CommandTestProcess::$calls)->toBe([['fclose']]);
});

it('treats a row that lacks an orphaned column as empty', function (): void {
    adm_defs(['provider' => ['name' => 'provider']]);
    adm_engine([
        'orphan_columns' => fn (array $d): array => ['old_a'],
        'each_table_row' => function ($table, callable $emit): int {
            $emit(['post_id' => 1]);

            return 1;
        },
    ]);

    expect(adm_download(['type' => 'orphans', 'table' => 'provider']))->toBe("post_id,old_a\n");
});

it('streams a stored snapshot, changes or backups page by page', function (string $type, string $kind): void {
    $calls = new ArrayObject;
    adm_engine([]);
    adm_store(['rows' => function ($k, $ref, $after = 0, $limit = 500, $desc = false) use ($calls, $kind): array {
        $calls[] = [$k, $ref, $after, $limit];
        expect($k)->toBe($kind);

        // A loop that never advances its cursor asks for the same page forever; stop it as a failure.
        if (count($calls) > 3) {
            throw new LogicException('The cursor did not advance.');
        }

        return match ($after) {
            0 => array_map(fn (int $i): object => (object) ['id' => (string) $i, 'data' => '{"n":'.$i.'}'], range(1, 500)),
            500 => [(object) ['id' => '501', 'data' => '{"n":501}'], (object) ['id' => '507', 'data' => '{"n":507}']],
            default => throw new LogicException("unexpected cursor $after"),
        };
    }]);

    $out = adm_download(['type' => $type, 'ref' => 'bf-1']);
    $lines = explode("\n", rtrim($out, "\n"));

    expect($lines)->toHaveCount(502)
        ->and($lines[0])->toBe('{"n":1}')
        ->and($lines[501])->toBe('{"n":507}')
        ->and($calls->getArrayCopy())->toBe([[$kind, 'bf-1', 0, 500], [$kind, 'bf-1', 500, 500]])
        ->and(adm_headers())->toBe([
            'Content-Type: application/x-ndjson; charset=utf-8',
            "Content-Disposition: attachment; filename=\"site-7-$type-bf-1.jsonl\"",
            'X-Content-Type-Options: nosniff',
        ]);
})->with([
    'snapshot' => ['snapshot', 'snapshot'],
    'changes' => ['changes', 'change'],
    'backups' => ['backups', 'backup'],
]);

it('stops streaming after one short page', function (): void {
    $calls = new ArrayObject;
    adm_store(['rows' => function ($k, $ref, $after = 0, $limit = 500, $desc = false) use ($calls): array {
        $calls[] = $after;

        return [(object) ['id' => '9', 'data' => '{"n":9}']];
    }]);

    expect(adm_download(['type' => 'snapshot', 'ref' => 'before']))->toBe("{\"n\":9}\n")
        ->and($calls->getArrayCopy())->toBe([0]);
});

it('streams nothing for a stored set with no rows', function (): void {
    adm_store(['rows' => fn (): array => []]);

    expect(adm_download(['type' => 'backups', 'ref' => 'before']))->toBe('');
});

it('rejects a stored-set download whose reference is not allowed, and sends no headers', function (): void {
    try {
        adm_download(['type' => 'snapshot', 'ref' => 'bad name']);
        $died = null;
    } catch (AdminTestDied $adminTestDied) {
        $died = $adminTestDied;
    }

    expect($died)->toBeInstanceOf(AdminTestDied::class)
        ->and($died->getMessage())->toContain('bad name')
        ->and(adm_headers())->toBe([]);
});

it('streams a comparison as a summary line and one line per difference', function (): void {
    $args = new ArrayObject;
    adm_engine(['compare' => function ($a, $b, array $ignore = []) use ($args): array {
        $args[] = [$a, $b, $ignore];

        return ['summary' => ['identical' => false, 'posts_a' => 2], 'diffs' => [['post_id' => 1, 'field' => 'f'], ['post_id' => 2, 'field' => 'g']]];
    }]);

    $out = adm_download(['type' => 'compare', 'a' => 'before', 'b' => 'after', 'ignore' => 'x, y']);

    expect($out)->toBe("{\"summary\":{\"identical\":false,\"posts_a\":2}}\n{\"post_id\":1,\"field\":\"f\"}\n{\"post_id\":2,\"field\":\"g\"}\n")
        ->and($args->getArrayCopy())->toBe([['before', 'after', ['x', 'y']]])
        ->and(adm_headers())->toBe([
            'Content-Type: application/x-ndjson; charset=utf-8',
            'Content-Disposition: attachment; filename="site-7-compare-before__vs__after.jsonl"',
            'X-Content-Type-Options: nosniff',
        ]);
});

it('streams a comparison of nothing different as the summary alone', function (): void {
    $args = new ArrayObject;
    adm_engine(['compare' => function ($a, $b, array $ignore = []) use ($args): array {
        $args[] = $ignore;

        return ['summary' => ['identical' => true], 'diffs' => []];
    }]);

    expect(adm_download(['type' => 'compare', 'a' => 'a', 'b' => 'b']))->toBe("{\"summary\":{\"identical\":true}}\n")
        ->and($args->getArrayCopy())->toBe([[]]);
});

/* ====================================================================== site page */

it('refuses the site page to a user who may not use it', function (): void {
    $GLOBALS['adm_can'] = false;

    expect(fn () => Admin::page())->toThrow(AdminTestDied::class, 'Not allowed.');
});

it('says so when ACF is not active', function (): void {
    redefine(Engine::class.'::acf_ready', fn (): bool => false);
    adm_engine(['inventory' => fn (): never => throw new LogicException('must not be reached')]);

    $html = adm_capture(fn () => Admin::page());

    expect($html)->toBe('<div class="wrap"><h1>ACFCDT Migration</h1><div class="notice notice-error"><p>ACF is not active on this site.</p></div></div>');
});

it('shows the site, the plugin status and the archive folder', function (): void {
    $html = adm_page(adm_inventory(['blog_id' => '3.9', 'url' => 'https://x.test/?a=1&b=2', 'json_dir' => '/d/<j>']));

    expect($html)->toContain("<h1>ACFCDT Migration \u{2014} site 3 <small>https://x.test/?a=1&amp;b=2</small></h1>")
        ->and($html)->toContain('<span>Plugin: <strong>active</strong> (4 intercepts)</span>')
        ->and($html)->toContain('<span>Values also in postmeta: <strong>yes</strong></span>')
        ->and($html)->toContain('<span>Table JSON: <code>/d/&lt;j&gt;</code></span>')
        ->and($html)->toContain('<ol class="acfm-steps">')
        ->and($html)->toContain('<style>')
        ->and($html)->toContain('<script>');
});

it('shows an inactive plugin and a custom table that is the only copy', function (): void {
    $html = adm_page(adm_inventory(['plugin_loaded' => false, 'intercepts' => 0, 'core_meta' => false]));

    expect($html)->toContain('<span>Plugin: <strong>not active</strong> (0 intercepts)</span>')
        ->and($html)->toContain("<strong>NO \u{2014} custom table is the only copy</strong>");
});

it('tells the user when the site has no tables, and when it does', function (): void {
    $none = adm_page(adm_inventory(['tables' => [adm_table(['exists' => false])]]));
    $some = adm_page(adm_inventory(['tables' => [adm_table()]]));
    $empty = adm_page(adm_inventory());

    expect($none)->toContain('<div class="notice notice-info"><p>This site has no ACF Custom Database Tables tables. Nothing to migrate here.</p></div>')
        ->and($empty)->toContain('This site has no ACF Custom Database Tables tables.')
        ->and($some)->not->toContain('Nothing to migrate here.');
});

it('lists each reason the plugin is not reading the custom table', function (): void {
    $html = adm_page(adm_inventory(['cache_problems' => ['Cache A <x>', 'Cache B']]));

    expect($html)->toContain('<div class="notice notice-error"><p><strong>Plugin is not reading the custom table:</strong> Cache A &lt;x&gt;</p></div>')
        ->and($html)->toContain('<strong>Plugin is not reading the custom table:</strong> Cache B</p>');
});

it('describes a table that does not exist and skips its details', function (): void {
    $html = adm_page(adm_inventory(['tables' => [
        adm_table(['table' => 'wp_gone', 'post_type' => 'gone', 'exists' => false]),
        adm_table(['table' => 'wp_here', 'post_type' => 'here']),
    ]]));

    expect($html)->toContain('<h3><code>wp_gone</code> <small>post type gone</small></h3> <p>Table does not exist on this site.</p>')
        ->and($html)->toContain('<h3><code>wp_here</code> <small>post type here</small></h3> <p> Rows: <strong>5</strong>')
        ->and(substr_count($html, 'Download all rows (JSONL)'))->toBe(1);
});

it('counts rows and the posts that do not match them', function (): void {
    $html = adm_page(adm_inventory(['tables' => [adm_table(['rows' => '5.9', 'missing_post' => '1.9', 'wrong_type' => '2.9', 'no_row' => '3.9'])]]));

    expect($html)->toContain("Rows: <strong>5</strong> \u{00b7} rows whose post no longer exists: 1 \u{00b7} rows for other post types: 2 \u{00b7} provider posts with no row: 3 </p>");
});

it('links the archive download for each table', function (): void {
    $html = adm_page(adm_inventory(['tables' => [adm_table()]]));

    expect($html)->toContain('<a class="button" href="https://example.test/wp-admin/admin-post.php?action=uamswp_acfcdt_mig_dl&#038;type=archive&#038;table=provider&#038;_wpnonce=nonce-uamswp_acfcdt_mig">Download all rows (JSONL)</a>')
        ->and($html)->not->toContain('Download orphaned columns');
});

it('links the orphaned-column download only when a table has orphans', function (): void {
    $html = adm_page(adm_inventory(['tables' => [adm_table(['orphans' => ['old_a', 'old_<b>']])]]));

    expect($html)->toContain('<a class="button" href="https://example.test/wp-admin/admin-post.php?action=uamswp_acfcdt_mig_dl&#038;type=orphans&#038;table=provider&#038;_wpnonce=nonce-uamswp_acfcdt_mig">Download orphaned columns (CSV)</a>')
        ->and($html)->toContain('<span class="description">Orphaned (archived, not migrated): old_a, old_&lt;b&gt;</span>');
});

it('reports whether the plugin is reading each table', function (): void {
    $reading = adm_page(adm_inventory([
        'tables' => [adm_table()],
        'cache' => ['provider' => ['cache_post_type' => 'provider', 'intercepting' => true, 'not_cached' => []]],
    ]));
    $not = adm_page(adm_inventory([
        'tables' => [adm_table()],
        'cache' => ['provider' => ['cache_post_type' => '', 'intercepting' => false, 'not_cached' => ['x']]],
    ]));
    $none = adm_page(adm_inventory(['tables' => [adm_table()], 'cache' => []]));

    expect($reading)->toContain("Table map cache: post type <code>provider</code> \u{2014} <span class=\"acfm-ok\">plugin is reading this table</span>")
        ->and($reading)->not->toContain('mapped column(s)')
        ->and($not)->toContain("post type <code>(none)</code> \u{2014} <span class=\"acfm-bad\">plugin is NOT reading this table (site shows postmeta)</span>")
        ->and($not)->not->toContain('mapped column(s)')
        ->and($none)->not->toContain('Table map cache');
});

it('lists the mapped columns missing from the cache when the plugin is reading the table', function (): void {
    $html = adm_page(adm_inventory([
        'tables' => [adm_table()],
        'cache' => ['provider' => ['cache_post_type' => 'provider', 'intercepting' => true, 'not_cached' => ['a', 'b<']]],
    ]));

    expect($html)->toContain('<span class="description">2 mapped column(s) missing from the cache are served from postmeta: a, b&lt;</span>');
});

it('tables each column with a dimmed row for an empty one', function (): void {
    $html = adm_page(adm_inventory(['tables' => [adm_table(['columns' => [
        ['column' => 'c1', 'acf_name' => 'n1 *', 'acf_type' => 'text', 'non_empty' => '4.9', 'note' => 'a <note>'],
        ['column' => 'c2', 'acf_name' => 'n2', 'acf_type' => '-', 'non_empty' => 0, 'note' => ''],
    ]])]]));

    expect($html)->toContain('<tr> <td>c1</td><td>n1 *</td> <td>text</td><td>4</td><td>a &lt;note&gt;</td> </tr>')
        ->and($html)->toContain('<tr class="acfm-dim"> <td>c2</td><td>n2</td> <td>-</td><td>0</td><td></td> </tr>');
});

it('suggests the next snapshot name from what already exists', function (array $snaps, string $name): void {
    $html = adm_page(adm_inventory(), ['snapinfo' => $snaps]);

    expect($html)->toContain('id="acfm-snap-label" value="'.$name.'"');
})->with([
    'none yet' => [[], 'before'],
    'only after-meta' => [['after-meta' => ['status' => 'complete']], 'before'],
    'before taken' => [['before' => ['status' => 'complete']], 'after-meta'],
    'before and after-meta taken' => [['before' => ['status' => 'complete'], 'after-meta' => ['status' => 'complete']], 'after-off'],
]);

it('preselects the meta mode only once the before snapshot exists, the plugin is loaded and after-meta is not taken', function (array $snaps, bool $loaded, bool $meta): void {
    $html = adm_page(adm_inventory(['plugin_loaded' => $loaded]), ['snapinfo' => $snaps]);

    expect($html)->toContain($meta ? '<option value="meta" selected=\'selected\'>meta</option>' : '<option value="meta">meta</option>')
        ->and($html)->toContain('<option value="effective">effective</option>');
})->with([
    'no before' => [[], true, false],
    'before, plugin loaded' => [['before' => []], true, true],
    'before, plugin not loaded' => [['before' => []], false, false],
    'before and after-meta' => [['before' => [], 'after-meta' => []], true, false],
]);

it('disables the snapshot button when the site has no tables', function (): void {
    $without = adm_page(adm_inventory());
    $with = adm_page(adm_inventory(['tables' => [adm_table()]]));

    expect($without)->toContain('id="acfm-snap-go" disabled=\'disabled\'>Take snapshot</button>')
        ->and($with)->toContain('id="acfm-snap-go" >Take snapshot</button>');
});

it('offers the backfill options and disables it without tables or without the plugin', function (array $inv, bool $disabled): void {
    $html = adm_page(adm_inventory($inv));

    expect($html)->toContain($disabled ? 'id="acfm-bf-go" disabled=\'disabled\'>Run backfill</button>' : 'id="acfm-bf-go" >Run backfill</button>')
        ->and($html)->toContain('<input type="checkbox" id="acfm-bf-dry" checked>')
        ->and($html)->toContain('id="acfm-bf-limit" value="10" min="1" max="100"');
})->with([
    'tables and plugin' => [['tables' => [adm_table()]], false],
    'no tables' => [['tables' => []], true],
    'plugin not loaded' => [['tables' => [adm_table()], 'plugin_loaded' => false], true],
]);

it('asks the user to activate the plugin when the backfill cannot run', function (): void {
    $off = adm_page(adm_inventory(['plugin_loaded' => false]));
    $on = adm_page(adm_inventory());

    expect($off)->toContain('<span class="description">Needs ACF Custom Database Tables active.</span>')
        ->and($on)->not->toContain('Needs ACF Custom Database Tables active.');
});

it('offers the stale-cache override only when the plugin is not reading the table', function (): void {
    $with = adm_page(adm_inventory(['cache_problems' => ['x']]));
    $without = adm_page(adm_inventory());

    expect($with)->toContain('<label class="acfm-bad"><input type="checkbox" id="acfm-bf-stale">')
        ->and($without)->not->toContain('<input type="checkbox" id="acfm-bf-stale">');
});

it('lists the complete snapshots in both comparison menus', function (): void {
    $html = adm_page(adm_inventory(), ['snapinfo' => [
        'after-meta' => ['status' => 'complete'],
        'before' => ['status' => 'complete'],
        'partial' => ['status' => 'running'],
        'blank' => [],
    ]]);

    expect($html)->toContain('<select id="acfm-cmp-a"><option>after-meta</option><option selected=\'selected\'>before</option></select>')
        ->and($html)->toContain('<select id="acfm-cmp-b"><option selected=\'selected\'>after-meta</option><option>before</option></select>')
        ->and($html)->not->toContain('<option>partial</option>')
        ->and($html)->toContain('<input type="text" id="acfm-cmp-ignore" placeholder="comma,separated">');
});

it('enables the comparison from two complete snapshots', function (int $complete, bool $disabled): void {
    $snaps = [];
    for ($i = 0; $i < $complete; $i++) {
        $snaps["s$i"] = ['status' => 'complete'];
    }

    $html = adm_page(adm_inventory(), ['snapinfo' => $snaps]);

    expect($html)->toContain($disabled ? 'id="acfm-cmp-go" disabled=\'disabled\'>Compare</button>' : 'id="acfm-cmp-go" >Compare</button>');
})->with([
    'none' => [0, true],
    'one' => [1, true],
    'two' => [2, false],
    'three' => [3, false],
]);

it('hands the script its endpoint, nonce and action', function (): void {
    $html = adm_page(adm_inventory());

    expect($html)->toContain('const C = '.json_encode(['ajax' => 'https://example.test/wp-admin/admin-ajax.php', 'nonce' => 'nonce-uamswp_acfcdt_mig', 'act' => 'uamswp_acfcdt_mig']).';');
});

it('keeps the progress panel hidden until a job starts', function (): void {
    $html = adm_page(adm_inventory());

    expect($html)->toContain('<div class="acfm-box" id="acfm-progress" hidden>')
        ->and($html)->toContain('<h2 id="acfm-progress-title">Working');
});

/* ====================================================================== snapshot and run tables */

it('says none yet when no snapshot or run is stored', function (): void {
    $html = adm_page(adm_inventory());

    expect(substr_count($html, '<p><em>None yet.</em></p>'))->toBe(2)
        ->and($html)->not->toContain('<th>Name</th><th>Mode</th>')
        ->and($html)->not->toContain('<th>Run</th><th>Type</th>');
});

it('tables a complete snapshot with its post count and download', function (): void {
    $html = adm_page(adm_inventory(), ['snapinfo' => ['before' => ['mode' => 'effective', 'status' => 'complete', 'started' => '2026-01-02 03:04:05']]]);

    expect($html)->toContain('<th>Name</th><th>Mode</th><th>Status</th><th>Posts</th><th>Started (UTC)</th>')
        ->and($html)->toContain('<tr><td><code>before</code></td><td>effective</td><td>complete</td><td>42</td><td>2026-01-02 03:04:05</td><td> <a class="button button-small" href="https://example.test/wp-admin/admin-post.php?action=uamswp_acfcdt_mig_dl&#038;type=snapshot&#038;ref=before&#038;_wpnonce=nonce-uamswp_acfcdt_mig">Download</a> <button class="button button-small button-link-delete" data-acfm-delete="snapshot" data-ref="before">Delete</button></td></tr>')
        ->and($html)->not->toContain('data-acfm-resume="snapshot"');
});

it('looks the post count of a snapshot up by kind and name', function (): void {
    $asked = new ArrayObject;
    adm_engine(['inventory' => fn (): array => adm_inventory(), 'posts_upto' => fn ($cursor): int => 0]);
    adm_store([
        'headers' => fn ($kind): array => $kind === 'snapinfo' ? ['before' => ['status' => 'complete']] : [],
        'count' => function ($kind, $ref, $where_sql = '') use ($asked): int {
            $asked[] = [$kind, $ref];

            return 1;
        },
    ]);

    adm_capture(fn () => Admin::page());

    expect($asked->getArrayCopy())->toBe([['snapshot', 'before']]);
});

it('tables an incomplete snapshot with its progress and a resume button', function (): void {
    $asked = new ArrayObject;
    $html = adm_page(
        adm_inventory(),
        ['snapinfo' => ['mid' => ['mode' => 'meta', 'status' => 'running', 'cursor' => '4', 'total' => '90.9', 'started' => 'S']]],
        function ($cursor) use ($asked): int {
            $asked[] = $cursor;

            return 33;
        },
    );

    expect($html)->toContain('<td>running</td><td>33 / 90</td><td>S</td>')
        ->and($html)->toContain('<button class="button button-small" data-acfm-resume="snapshot" data-ref="mid" data-cursor="4" data-total="90" data-done="33">Resume</button>')
        ->and($asked->getArrayCopy())->toBe(['4', '4']);
});

it('shows a question mark for what an incomplete snapshot header lacks', function (): void {
    $asked = new ArrayObject;
    $html = adm_page(
        adm_inventory(),
        ['snapinfo' => ['mid' => []]],
        function ($cursor) use ($asked): int {
            $asked[] = $cursor;

            return 0;
        },
    );

    expect($html)->toContain('<td><code>mid</code></td><td></td><td>?</td><td>0 / ?</td><td></td>')
        ->and($html)->toContain('data-cursor="0" data-total="0" data-done="0">Resume</button>')
        ->and($asked->getArrayCopy())->toBe([0, 0]);
});

it('escapes the names in the snapshot table', function (): void {
    $html = adm_page(adm_inventory(), ['snapinfo' => ['a<b' => ['status' => 'x<y', 'mode' => 'm<n', 'started' => 's<t']]]);

    expect($html)->toContain('<code>a&lt;b</code>')
        ->and($html)->toContain('data-ref="a&lt;b"')
        ->and($html)->toContain('m&lt;n')
        ->and($html)->toContain('s&lt;t');
});

/**
 * A run header.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function adm_run(array $over = []): array
{
    return $over + ['status' => 'complete', 'started' => 'T0', 'total' => 20, 'cursor' => 0, 'stats' => adm_stats()];
}

/**
 * A run header with no stored counts, so the page asks the change rows for them.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function adm_bare_run(array $over = []): array
{
    $run = adm_run($over);
    unset($run['stats']);

    return $run;
}

/**
 * Run statistics.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function adm_stats(array $over = []): array
{
    return $over + ['posts' => 6, 'fields' => 9, 'fill' => 5, 'overwrite' => 3, 'clear' => 1, 'skipped' => 0, 'invalid' => 0, 'write_mismatch' => 0];
}

it('tables a complete real run with its counts, links and actions', function (): void {
    $html = adm_page(adm_inventory(), ['run' => ['bf-1' => adm_run()]]);

    expect($html)->toContain('<th>Run</th><th>Type</th><th>Status</th><th>Posts / fields changed</th><th>fill / overwrite / clear</th><th>Mismatches</th><th>Rollback</th>')
        ->and($html)->toContain('<tr><td><code>bf-1</code><br><small>T0</small></td><td><strong>REAL</strong></td><td>complete</td><td>6 / 9</td><td>5 / 3 / 1</td><td>0</td>')
        ->and($html)->toContain("<td>\u{2014}</td><td>")
        ->and($html)->toContain('type=changes&#038;ref=bf-1&#038;_wpnonce=nonce-uamswp_acfcdt_mig">Changes</a> ')
        ->and($html)->toContain('type=backups&#038;ref=bf-1&#038;_wpnonce=nonce-uamswp_acfcdt_mig">Backups</a> ')
        ->and($html)->toContain('<button class="button button-small" data-acfm-rollback="bf-1">Roll back</button> ')
        ->and($html)->toContain('<button class="button button-small button-link-delete" data-acfm-delete="run" data-ref="bf-1">Delete</button></td></tr>')
        ->and($html)->not->toContain('data-acfm-resume="backfill"');
});

it('tables a dry run without backups or a rollback', function (): void {
    $html = adm_page(adm_inventory(), ['run' => ['dry-1' => adm_run(['dry' => true])]]);

    expect($html)->toContain('<td>dry run</td>')
        ->and($html)->not->toContain('Backups</a>')
        ->and($html)->not->toContain('data-acfm-rollback="')
        ->and($html)->toContain('data-acfm-delete="run" data-ref="dry-1"');
});

it('offers to resume an incomplete run', function (): void {
    $asked = new ArrayObject;
    $html = adm_page(
        adm_inventory(),
        ['run' => ['bf-2' => adm_run(['status' => 'running', 'cursor' => '15', 'total' => '60'])]],
        function ($cursor) use ($asked): int {
            $asked[] = $cursor;

            return 8;
        },
    );

    expect($html)->toContain('<td>running</td>')
        ->and($html)->toContain('type=changes&#038;ref=bf-2&#038;_wpnonce=nonce-uamswp_acfcdt_mig">Changes</a> <button class="button button-small" data-acfm-resume="backfill" data-ref="bf-2" data-cursor="15" data-total="60" data-done="8">Resume</button> ')
        ->and($asked->getArrayCopy())->toBe(['15']);
});

it('resumes a run that has no cursor yet from the start', function (): void {
    $header = adm_run(['status' => 'running', 'total' => 3]);
    unset($header['cursor']);
    $asked = new ArrayObject;
    $html = adm_page(adm_inventory(), ['run' => ['bf-2' => $header]], function ($cursor) use ($asked): int {
        $asked[] = $cursor;

        return 0;
    });

    expect($html)->toContain('data-cursor="0" data-total="3" data-done="0">Resume</button>')
        ->and($asked->getArrayCopy())->toBe([0]);
});

it('shows what a run header lacks as a question mark and counts from the change rows', function (): void {
    $header = ['total' => 4];
    $asked = new ArrayObject;
    adm_engine(['inventory' => fn (): array => adm_inventory(), 'posts_upto' => fn ($c): int => 0]);
    adm_store([
        'headers' => fn ($kind): array => $kind === 'run' ? ['bf-3' => $header] : [],
        'change_summary' => function ($stamp) use ($asked): array {
            $asked[] = $stamp;

            return ['stats' => adm_stats(['posts' => 2, 'fields' => 7, 'fill' => 4, 'overwrite' => 2, 'clear' => 1])];
        },
    ]);

    $html = adm_capture(fn () => Admin::page());

    expect($html)->toContain('<td><code>bf-3</code><br><small></small></td><td><strong>REAL</strong></td><td>?</td><td>2 / 7</td><td>4 / 2 / 1</td>')
        ->and($asked->getArrayCopy())->toBe(['bf-3']);
});

it('uses the counts in the run header without asking the change rows', function (): void {
    adm_engine(['inventory' => fn (): array => adm_inventory(), 'posts_upto' => fn ($c): int => 0]);
    adm_store([
        'headers' => fn ($kind): array => $kind === 'run' ? ['bf-3' => adm_run()] : [],
        'change_summary' => fn (): never => throw new LogicException('must not be reached'),
    ]);

    expect(adm_capture(fn () => Admin::page()))->toContain('<td>6 / 9</td>');
});

it('shows the skipped, invalid and mismatched counts of a run', function (): void {
    $html = adm_page(adm_inventory(), ['run' => ['bf-1' => adm_run(['stats' => adm_stats(['skipped' => '2.9', 'invalid' => '3.9', 'write_mismatch' => '4.9'])])]]);

    expect($html)->toContain('<td>5 / 3 / 1 (2 skipped) <strong style="color:#b32d2e">3 invalid '."\u{2014}".' see Changes</strong></td>')
        ->and($html)->toContain('<td><strong style="color:#b32d2e">4</strong></td>');
});

it('omits the invalid note when the stats have no invalid key', function (): void {
    $stats = adm_stats();
    unset($stats['invalid']);
    $html = adm_page(adm_inventory(), ['run' => ['bf-1' => adm_run(['stats' => $stats])]]);

    expect($html)->toContain('<td>5 / 3 / 1</td>');
});

it('shows the rollback of a run and what can be done with it', function (?array $rb, string $cell, string $button): void {
    $html = adm_page(adm_inventory(), ['run' => ['bf-1' => adm_run()], 'rollback' => $rb === null ? [] : ['bf-1' => $rb]]);

    expect($html)->toContain($cell)
        ->and($html)->toContain($button);
})->with([
    'none' => [null, "<td>\u{2014}</td><td>", '>Roll back</button>'],
    'running, no cursor' => [['status' => 'running'], '<td>running</td><td>', 'data-acfm-rollback="bf-1" data-cursor="0">Resume rollback</button>'],
    'running, a cursor' => [['status' => 'running', 'cursor' => '12'], '<td>running</td><td>', 'data-acfm-rollback="bf-1" data-cursor="12">Resume rollback</button>'],
]);

it('offers no rollback button once the rollback is complete', function (): void {
    $html = adm_page(adm_inventory(), ['run' => ['bf-1' => adm_run()], 'rollback' => ['bf-1' => ['status' => 'complete']]]);

    expect($html)->toContain('<td>complete</td><td>')
        ->and($html)->not->toContain('data-acfm-rollback="')
        ->and($html)->toContain('Backups</a>');
});

it('escapes the rollback status of a run', function (): void {
    $html = adm_page(adm_inventory(), ['run' => ['bf-1' => adm_run()], 'rollback' => ['bf-1' => ['status' => 'x<y']]]);

    expect($html)->toContain('<td>x&lt;y</td>');
});

it('adds a by-field breakdown to a complete run that has one', function (): void {
    adm_engine(['inventory' => fn (): array => adm_inventory(), 'posts_upto' => fn ($c): int => 0]);
    adm_store([
        'headers' => fn ($kind): array => $kind === 'run' ? ['bf-1' => adm_bare_run(), 'bf-2' => adm_bare_run(['status' => 'running'])] : [],
        'change_summary' => fn (): array => [
            'stats' => adm_stats(),
            'by_field' => [['field' => 'a<b', 'fill' => 1, 'overwrite' => 2, 'clear' => 3], ['field' => 'c', 'fill' => 0, 'overwrite' => 0, 'clear' => 4]],
        ],
    ]);
    $html = adm_capture(fn () => Admin::page());

    expect($html)->toContain('<tr class="acfm-sub"><td colspan="8"><details><summary>By field</summary><table class="acfm-compact"><thead><tr><th>Field</th><th>fill</th><th>overwrite</th><th>clear</th></tr></thead><tbody><tr><td>a&lt;b</td><td>1</td><td>2</td><td>3</td></tr><tr><td>c</td><td>0</td><td>0</td><td>4</td></tr></tbody></table></details></td></tr>')
        ->and(substr_count($html, 'By field'))->toBe(1)
        ->and(strpos($html, 'By field'))->toBeLessThan(strpos($html, '<code>bf-2</code>'));
});

it('adds no breakdown when a complete run has no by-field rows', function (): void {
    $html = adm_page(adm_inventory(), ['run' => ['bf-1' => adm_run()]]);

    expect($html)->not->toContain('By field');
});

it('adds no breakdown when the by-field key is absent', function (): void {
    adm_engine(['inventory' => fn (): array => adm_inventory(), 'posts_upto' => fn ($c): int => 0]);
    adm_store([
        'headers' => fn ($kind): array => $kind === 'run' ? ['bf-1' => adm_run()] : [],
        'change_summary' => fn (): array => ['stats' => adm_stats()],
    ]);

    expect(adm_capture(fn () => Admin::page()))->not->toContain('By field');
});
