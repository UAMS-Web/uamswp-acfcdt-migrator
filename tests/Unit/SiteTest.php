<?php

/**
 * Site: the table definitions, the plugin's cache, the posts and ACF fields, the postmeta queries and the output
 * directory, with WordPress, ACF and `$wpdb` mocked.
 *
 * Table definitions and the plugin's cache are real files in a temporary directory. `$wpdb` is a Mockery stand-in
 * whose `prepare()` records its arguments and returns the query with them appended, so a test reads the query the
 * class built; whether MySQL accepts it and returns what the class expects is for the Integration suite.
 * `is_writable()` and `is_dir()` are namespace shims (tests/Unit/Fixtures/site-shims.php) that pass through unless a
 * test sets an answer.
 *
 * Mutation run: `vendor/bin/pest tests/Unit/SiteTest.php --mutate --path=includes
 * --class='UAMSWP_ACFCDT_Migrator\Site' --covered-only`, 195 mutants, 0 survivors, no timeouts (macOS, PHP 8.4.27,
 * PCOV; a working run, not the Linux run that closes the coverage ticket). One annotation in the source: the first
 * index `array_fill()` numbers from, which `implode()` discards.
 *
 * Order matters in this file, because a constant cannot be undefined and a mocked function cannot be removed: the
 * `json_dir()` tests that need `acf_get_setting()` absent come first, then the ones that define it, and the tests
 * that define `ACFCDT_JSON_DIR` and `ACFCDT_MIGRATION_DIR` come last. `WP_CONTENT_DIR` is defined here to a path
 * that exists nowhere.
 *
 * @see includes/class-site.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Mockery\MockInterface;
use UAMSWP_ACFCDT_Migrator\Site;

use function Brain\Monkey\Functions\when;

require_once __DIR__.'/Fixtures/wp-cli-stand-in.php';
require_once __DIR__.'/Fixtures/site-shims.php';

defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', '/wp-content-test');
defined('ARRAY_N') || define('ARRAY_N', 'ARRAY_N');

beforeEach(function (): void {
    SiteTestFs::reset();
    CommandTestCli::$calls = [];

    $GLOBALS['site_dirs'] = [];
    $GLOBALS['site_prepared'] = [];
    $GLOBALS['site_tables'] = [];
    $GLOBALS['site_save_json'] = '';
    $GLOBALS['site_acf_calls'] = [];

    when('get_current_blog_id')->justReturn(5);
    when('home_url')->alias(fn (string $path = ''): string => 'https://example.test'.$path);
    when('untrailingslashit')->alias(fn (string $s): string => rtrim($s, '/\\'));
    when('absint')->alias(fn (mixed $n): int => abs((int) $n));
});

afterEach(function (): void {
    foreach ($GLOBALS['site_dirs'] as $dir) {
        site_remove($dir);
    }

    unset($GLOBALS['site_dirs'], $GLOBALS['site_prepared'], $GLOBALS['site_tables'], $GLOBALS['site_save_json'], $GLOBALS['site_acf_calls'], $GLOBALS['wpdb']);
    SiteTestFs::reset();
});

/** Removes a directory tree. */
function site_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }

    foreach (glob($path.'/{,.}*', GLOB_BRACE) ?: [] as $entry) {
        if (basename($entry) !== '.' && basename($entry) !== '..') {
            site_remove($entry);
        }
    }

    @rmdir($path);
}

/**
 * Makes a temporary directory holding the given files (`path => contents`, nested paths allowed).
 *
 * @param  array<string, string>  $files
 */
function site_dir(array $files = []): string
{
    $dir = sys_get_temp_dir().'/site-test-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $GLOBALS['site_dirs'][] = $dir;

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($dir.'/'.$path))) {
            mkdir(dirname($dir.'/'.$path), 0777, true);
        }

        file_put_contents($dir.'/'.$path, $contents);
    }

    return $dir;
}

/**
 * A table definition file's contents.
 *
 * @param  list<array<string, mixed>>  $columns
 */
function site_def(string $name, string $post_type, array $columns = [], array $over = []): string
{
    return json_encode($over + [
        'name' => $name,
        'relationship' => ['type' => 'post', 'post_type' => $post_type],
        'columns' => $columns,
    ], JSON_THROW_ON_ERROR);
}

/** A `$wpdb` that knows which tables exist and records each prepared query. */
function site_wpdb(array $existing = []): MockInterface
{
    $GLOBALS['site_tables'] = $existing;
    /** @var wpdb&MockInterface $wpdb */
    $wpdb = Mockery::mock('wpdb');
    $wpdb->prefix = 'wp_';
    $wpdb->posts = 'wp_posts';
    $wpdb->postmeta = 'wp_postmeta';
    $wpdb->shouldReceive('esc_like')->andReturnUsing(fn (string $t): string => addcslashes($t, '_%\\'));
    $wpdb->shouldReceive('prepare')->andReturnUsing(function (string $query, ...$args): string {
        $GLOBALS['site_prepared'][] = [$query, $args];

        return $query.'|'.json_encode($args);
    });
    $wpdb->shouldReceive('get_var')->andReturnUsing(function (string $sql): ?string {
        [, $json] = explode('|', $sql, 2);
        $like = stripslashes((string) (json_decode($json, true)[0] ?? ''));

        return in_array($like, $GLOBALS['site_tables'], true) ? $like : null;
    });
    $GLOBALS['wpdb'] = $wpdb;

    return $wpdb;
}

/** Defines `acf_get_setting()` to answer `save_json` from a global, and logs each question. */
function site_acf_setting(string $save_json): void
{
    $GLOBALS['site_save_json'] = $save_json;
    when('acf_get_setting')->alias(function (string $name): string {
        $GLOBALS['site_acf_calls'][] = $name;

        return $GLOBALS['site_save_json'];
    });
}

/* ====================================================================== construction and the JSON folder */

it('records the blog id and the home URL of the site it was made on', function (): void {
    $site = new Site;

    expect($site->blog_id)->toBe(5)
        ->and($site->url)->toBe('https://example.test/');
});

it('uses the folder it was given, without a trailing slash', function (): void {
    expect((new Site('/given/dir/'))->json_dir())->toBe('/given/dir');
});

it('falls back to the uploads folder when nothing names the JSON folder', function (): void {
    expect(function_exists('acf_get_setting'))->toBeFalse()
        ->and(defined('ACFCDT_JSON_DIR'))->toBeFalse()
        ->and((new Site)->json_dir())->toBe('/wp-content-test/uploads/acf-custom-database-tables');
});

it('ignores an empty folder override', function (mixed $override): void {
    expect((new Site($override))->json_dir())->toBe('/wp-content-test/uploads/acf-custom-database-tables');
})->with([[null], [''], ['0']]);

it('reads the save-JSON setting of ACF and looks in its database-tables folder', function (): void {
    site_acf_setting('/themes/acf-json/');

    expect((new Site)->json_dir())->toBe('/themes/acf-json/database-tables')
        ->and($GLOBALS['site_acf_calls'])->toBe(['save_json', 'save_json']);
});

it('falls back to the uploads folder when ACF has no save-JSON setting', function (): void {
    site_acf_setting('');

    expect((new Site)->json_dir())->toBe('/wp-content-test/uploads/acf-custom-database-tables')
        ->and($GLOBALS['site_acf_calls'])->toBe(['save_json']);
});

it('prefers the folder it was given over ACF', function (): void {
    site_acf_setting('/themes/acf-json');

    expect((new Site('/given'))->json_dir())->toBe('/given')
        ->and($GLOBALS['site_acf_calls'])->toBe([]);
});

/* ====================================================================== table definitions */

it('reads a post-type table definition with its columns and identifiers', function (): void {
    $dir = site_dir([
        'table_provider.json' => site_def('provider', 'provider', [
            ['name' => 'a', 'map' => ['identifier' => 'field_a']],
            ['name' => 'b'],
            ['map' => ['identifier' => 'field_c']],
        ]),
    ]);

    expect((new Site($dir))->table_defs())->toBe([
        'provider' => [
            'name' => 'provider',
            'post_type' => 'provider',
            'file' => $dir.'/table_provider.json',
            'columns' => [
                ['name' => 'a', 'identifier' => 'field_a'],
                ['name' => 'b', 'identifier' => ''],
                ['name' => '', 'identifier' => 'field_c'],
            ],
        ],
    ]);
});

it('keeps definitions in file order, keyed by table name', function (): void {
    $dir = site_dir([
        'b.json' => site_def('second', 'location'),
        'a.json' => site_def('first', 'provider'),
        'c.txt' => site_def('ignored', 'provider'),
    ]);

    $defs = (new Site($dir))->table_defs();

    expect(array_keys($defs))->toBe(['first', 'second'])
        ->and($defs['first']['columns'])->toBe([]);
});

it('skips a file that is not a table definition for a post type', function (string $contents): void {
    $dir = site_dir(['t.json' => $contents]);

    expect((new Site($dir))->table_defs())->toBe([]);
})->with([
    'not JSON' => ['{nope'],
    'a scalar' => ['"text"'],
    'a number' => ['5'],
    'empty list' => ['[]'],
    'no name' => [json_encode(['relationship' => ['type' => 'post', 'post_type' => 'p']])],
    'empty name' => [json_encode(['name' => '', 'relationship' => ['type' => 'post', 'post_type' => 'p']])],
    'zero name' => [json_encode(['name' => '0', 'relationship' => ['type' => 'post', 'post_type' => 'p']])],
    'no relationship' => [json_encode(['name' => 'n'])],
    'a user table' => [json_encode(['name' => 'n', 'relationship' => ['type' => 'user', 'post_type' => 'p']])],
    'no relationship type' => [json_encode(['name' => 'n', 'relationship' => ['post_type' => 'p']])],
    'no post type' => [json_encode(['name' => 'n', 'relationship' => ['type' => 'post']])],
    'empty post type' => [json_encode(['name' => 'n', 'relationship' => ['type' => 'post', 'post_type' => '']])],
]);

it('keeps reading after a file it skips', function (): void {
    $dir = site_dir(['a.json' => '{nope', 'b.json' => json_encode(['name' => 'n', 'relationship' => ['type' => 'user']]), 'c.json' => site_def('ok', 'p')]);

    expect(array_keys((new Site($dir))->table_defs()))->toBe(['ok']);
});

it('treats a definition with no columns, or columns that are not a list, as having none', function (): void {
    $dir = site_dir(['a.json' => json_encode(['name' => 'n', 'relationship' => ['type' => 'post', 'post_type' => 'p']]), 'b.json' => site_def('m', 'p', [], ['columns' => 'x'])]);

    $defs = (new Site($dir))->table_defs();

    expect($defs['n']['columns'])->toBe([])
        ->and($defs['m']['columns'])->toBe([['name' => '', 'identifier' => '']]);
});

it('finds no definitions in an empty or missing folder', function (): void {
    expect((new Site(site_dir()))->table_defs())->toBe([])
        ->and((new Site('/nowhere/at/all'))->table_defs())->toBe([]);
});

it('treats a failed folder listing as no definitions', function (): void {
    SiteTestFs::$glob = false;

    expect((new Site('/any'))->table_defs())->toBe([]);
});

it('skips a definition file it cannot read', function (): void {
    $dir = site_dir(['a.json' => site_def('n', 'p')]);
    SiteTestFs::$unreadable = true;

    expect((new Site($dir))->table_defs())->toBe([]);
});

it('reads the folder once', function (): void {
    $dir = site_dir(['a.json' => site_def('n', 'p')]);
    $site = new Site($dir);
    $first = $site->table_defs();

    unlink($dir.'/a.json');

    expect($site->table_defs())->toBe($first);
});

it('keeps definitions per Site, not across Sites', function (): void {
    $dir = site_dir(['a.json' => site_def('n', 'p')]);
    $first = (new Site($dir))->table_defs();

    unlink($dir.'/a.json');

    expect($first)->toHaveCount(1)
        ->and((new Site($dir))->table_defs())->toBe([]);
});

/* ====================================================================== tables on this site */

it('prefixes a table name with the site prefix', function (): void {
    site_wpdb();

    expect((new Site)->prefixed('provider'))->toBe('wp_provider');
});

it('asks the database for the exact table name, with the underscore escaped', function (): void {
    site_wpdb(['wp_provider']);

    $site = new Site;

    expect($site->table_exists('provider'))->toBeTrue()
        ->and($GLOBALS['site_prepared'])->toBe([['SHOW TABLES LIKE %s', ['wp\_provider']]]);
});

it('says a table does not exist when the database does not name it', function (): void {
    site_wpdb(['wp_other']);

    expect((new Site)->table_exists('provider'))->toBeFalse();
});

it('says a table does not exist when the database names a different one', function (): void {
    $wpdb = site_wpdb();
    $wpdb->shouldReceive('get_var')->andReturn('wp_providers');

    expect((new Site)->table_exists('provider'))->toBeFalse();
});

it('keeps the definitions whose table exists', function (): void {
    $dir = site_dir([
        'a.json' => site_def('here', 'p'),
        'b.json' => site_def('gone', 'q'),
        'c.json' => site_def('also', 'r'),
    ]);
    site_wpdb(['wp_here', 'wp_also']);

    expect(array_keys((new Site($dir))->active_defs()))->toBe(['here', 'also']);
});

it('lists the post types of the active tables once each', function (): void {
    $dir = site_dir([
        'a.json' => site_def('t1', 'provider'),
        'b.json' => site_def('t2', 'location'),
        'c.json' => site_def('t3', 'provider'),
        'd.json' => site_def('t4', 'unused'),
    ]);
    site_wpdb(['wp_t1', 'wp_t2', 'wp_t3']);

    expect((new Site($dir))->post_types())->toBe(['provider', 'location']);
});

it('lists no post types when no table exists', function (): void {
    $dir = site_dir(['a.json' => site_def('t1', 'provider')]);
    site_wpdb();

    expect((new Site($dir))->post_types())->toBe([]);
});

/* ====================================================================== the plugin's table map cache */

/** A table map cache file for a JSON folder. */
function site_cache(string $dir, string $php): void
{
    mkdir($dir.'/.cache');
    file_put_contents($dir.'/.cache/_table_map.php', $php);
}

it('reports a missing cache file by path', function (): void {
    $dir = site_dir();

    expect((new Site($dir))->cache_status())->toBe(['_missing' => $dir.'/.cache/_table_map.php']);
});

it('reports, per table, what the cache says and what is missing from it', function (): void {
    $dir = site_dir(['t.json' => site_def('provider', 'provider', [
        ['name' => 'in_cache', 'map' => ['identifier' => 'in_cache']],
        ['name' => 'col_in_cache', 'map' => ['identifier' => 'differs']],
        ['name' => 'absent', 'map' => ['identifier' => 'field_absent']],
        ['name' => 'no_identifier'],
        ['name' => 'other_absent', 'map' => ['identifier' => 'field_other']],
    ])]);
    site_cache($dir, '<?php return '.var_export([
        'tables' => ['provider' => ['relationship' => ['post_type' => 'physicians']]],
        'post_types' => ['physicians' => 'provider'],
        'acf_field_names' => ['post:provider' => ['in_cache' => 1, 'col_in_cache' => 1], 'post:location' => ['x' => 1]],
    ], true).';');

    expect((new Site($dir))->cache_status())->toBe([
        'provider' => ['cache_post_type' => 'physicians', 'intercepting' => false, 'not_cached' => ['field_absent', 'field_other']],
    ]);
});

it('reports a table the plugin is reading', function (): void {
    $dir = site_dir(['t.json' => site_def('provider', 'provider', [['name' => 'a', 'map' => ['identifier' => 'a']]])]);
    site_cache($dir, '<?php return '.var_export([
        'tables' => ['provider' => ['relationship' => ['post_type' => 'provider']]],
        'post_types' => ['provider' => 'provider'],
        'acf_field_names' => ['post:provider' => ['a' => 1]],
    ], true).';');

    expect((new Site($dir))->cache_status())->toBe([
        'provider' => ['cache_post_type' => 'provider', 'intercepting' => true, 'not_cached' => []],
    ]);
});

it('reports every column missing when the cache has no field names for the post type', function (): void {
    $dir = site_dir(['t.json' => site_def('provider', 'provider', [['name' => 'a', 'map' => ['identifier' => 'field_a']]])]);
    site_cache($dir, '<?php return [];');

    expect((new Site($dir))->cache_status())->toBe([
        'provider' => ['cache_post_type' => null, 'intercepting' => false, 'not_cached' => ['field_a']],
    ]);
});

it('treats a cache file that does not return an array as empty', function (string $php): void {
    $dir = site_dir(['t.json' => site_def('provider', 'provider', [['name' => 'a', 'map' => ['identifier' => 'field_a']]])]);
    site_cache($dir, $php);

    expect((new Site($dir))->cache_status())->toBe([
        'provider' => ['cache_post_type' => null, 'intercepting' => false, 'not_cached' => ['field_a']],
    ]);
})->with([
    'a string' => ['<?php return "text";'],
    'nothing' => ['<?php return null;'],
    'a number' => ['<?php return 1;'],
]);

it('reads field names that the cache stores as an object', function (): void {
    $dir = site_dir(['t.json' => site_def('provider', 'provider', [['name' => 'a', 'map' => ['identifier' => 'a']]])]);
    site_cache($dir, '<?php return ["acf_field_names" => ["post:provider" => (object) ["a" => 1]]];');

    expect((new Site($dir))->cache_status()['provider']['not_cached'])->toBe([]);
});

it('matches a column by strict comparison, not by loose equality', function (): void {
    $dir = site_dir(['t.json' => site_def('provider', 'provider', [['name' => '0', 'map' => ['identifier' => 'zero']]])]);
    site_cache($dir, '<?php return ["acf_field_names" => ["post:provider" => ["other" => 1]]];');

    expect((new Site($dir))->cache_status()['provider']['not_cached'])->toBe(['zero']);
});

/* ====================================================================== problems the plugin has reading a table */

it('explains a missing cache as one problem for the whole site', function (): void {
    $dir = site_dir();

    expect((new Site($dir))->cache_problems())->toBe([
        "No table map cache at {$dir}/.cache/_table_map.php \u{2014} the plugin is not reading any custom table. Run Create/Update Tables first.",
    ]);
});

it('has no problem when the plugin reads every active table', function (): void {
    $dir = site_dir(['t.json' => site_def('provider', 'provider')]);
    site_cache($dir, '<?php return ["post_types" => ["provider" => 1]];');
    site_wpdb(['wp_provider']);

    expect((new Site($dir))->cache_problems())->toBe([]);
});

it('explains each active table the plugin is not reading, naming the post type the cache has', function (): void {
    $dir = site_dir([
        'a.json' => site_def('provider', 'provider'),
        'b.json' => site_def('location', 'location'),
        'c.json' => site_def('unused', 'unused'),
        'd.json' => site_def('fine', 'fine'),
    ]);
    site_cache($dir, '<?php return ["tables" => ["provider" => ["relationship" => ["post_type" => "physicians"]]], "post_types" => ["fine" => 1]];');
    site_wpdb(['wp_provider', 'wp_location', 'wp_fine']);

    $problems = (new Site($dir))->cache_problems();

    expect($problems)->toHaveCount(2)
        ->and($problems[0])->toBe('The plugin is NOT reading wp_provider for "provider" posts — its cache says post type "physicians". The site is currently showing postmeta for these posts, not the table. Run Create/Update Tables (Find-a-Doc #813) before migrating, or decide deliberately which copy is current.')
        ->and($problems[1])->toBe('The plugin is NOT reading wp_location for "location" posts — its cache says post type "(none)". The site is currently showing postmeta for these posts, not the table. Run Create/Update Tables (Find-a-Doc #813) before migrating, or decide deliberately which copy is current.');
});

/* ====================================================================== posts */

it('finds no posts for no post types, without asking the database', function (): void {
    $wpdb = site_wpdb();
    $wpdb->shouldReceive('get_results')->never();

    expect((new Site)->posts([]))->toBe([])
        ->and($GLOBALS['site_prepared'])->toBe([]);
});

/** The query `Site::posts()` runs, and what it prepared it from. */
function site_posts(array $types, mixed $ids = null, mixed $limit = 0): array
{
    $wpdb = site_wpdb();
    $ran = new ArrayObject;
    $wpdb->shouldReceive('get_results')->andReturnUsing(function (string $sql) use ($ran): array {
        $ran[] = $sql;

        return [(object) ['ID' => 1]];
    });

    $found = (new Site)->posts($types, $ids, $limit);

    return [$found, $ran->getArrayCopy(), $GLOBALS['site_prepared']];
}

it('selects every post of the given types except auto-drafts and revisions', function (): void {
    [$found, $ran, $prepared] = site_posts(['provider', 'location']);

    expect($found)->toEqual([(object) ['ID' => 1]])
        ->and($prepared)->toBe([[
            "SELECT ID, post_type, post_status, post_title, post_modified FROM wp_posts\n\t\t\t WHERE post_type IN (%s,%s) AND post_status NOT IN ('auto-draft','inherit')",
            [['provider', 'location']],
        ]])
        ->and($ran)->toBe([$prepared[0][0].'|'.json_encode($prepared[0][1]).' ORDER BY ID ASC']);
});

it('prepares one placeholder per post type', function (): void {
    [, , $prepared] = site_posts(['only']);

    expect($prepared[0][0])->toContain('IN (%s) AND');
});

it('limits the posts to given IDs, as integers, dropping what is not a positive number', function (): void {
    [, $ran] = site_posts(['p'], ['3', 4, 0, '-5', 'x']);

    expect($ran[0])->toStartWith('SELECT ID, post_type')
        ->and($ran[0])->toEndWith(' AND ID IN (3,4,5) ORDER BY ID ASC');
});

it('accepts a single ID', function (): void {
    [, $ran] = site_posts(['p'], 7);

    expect($ran[0])->toEndWith(' AND ID IN (7) ORDER BY ID ASC');
});

it('matches nothing when every given ID is dropped', function (): void {
    [, $ran] = site_posts(['p'], ['x', 0]);

    expect($ran[0])->toEndWith(' AND ID IN (0) ORDER BY ID ASC');
});

it('does not limit the IDs when none are given', function (mixed $ids): void {
    [, $ran] = site_posts(['p'], $ids);

    expect($ran[0])->not->toContain('ID IN')
        ->and($ran[0])->toEndWith(' ORDER BY ID ASC');
})->with([[null], [[]], [0], ['']]);

it('limits the number of posts, as an integer', function (mixed $limit, string $tail): void {
    [, $ran] = site_posts(['p'], null, $limit);

    expect($ran[0])->toEndWith($tail);
})->with([
    'none' => [0, ' ORDER BY ID ASC'],
    'a number' => [25, ' ORDER BY ID ASC LIMIT 25'],
    'a numeric string' => ['10', ' ORDER BY ID ASC LIMIT 10'],
    'negative' => [-3, ' ORDER BY ID ASC LIMIT 3'],
]);

it('puts the ID filter before the order and the limit', function (): void {
    [, $ran] = site_posts(['p'], [9], 2);

    expect($ran[0])->toStartWith('SELECT ID, post_type')
        ->and($ran[0])->toEndWith(' AND ID IN (9) ORDER BY ID ASC LIMIT 2');
});

/* ====================================================================== ACF fields */

/**
 * Defines the ACF functions: `$groups` answers by the query's key (`post_id` or `post_type`), `$fields` by group key.
 *
 * @param  array<string, list<array<string, mixed>>>  $groups
 * @param  array<string, list<array<string, mixed>>|false>  $fields
 */
function site_acf(array $groups, array $fields): void
{
    $GLOBALS['site_acf_log'] = [];
    when('acf_get_field_groups')->alias(function (array $args) use ($groups): mixed {
        $GLOBALS['site_acf_log'][] = ['groups', $args];

        return $groups[array_key_first($args)] ?? false;
    });
    when('acf_get_fields')->alias(function (array $group) use ($fields): mixed {
        $GLOBALS['site_acf_log'][] = ['fields', $group['key']];

        return $fields[$group['key']] ?? false;
    });
}

it('lists the top-level fields of a post, by name, from every group that applies', function (): void {
    site_acf(
        ['post_id' => [['key' => 'group_b'], ['key' => '']], 'post_type' => [['key' => 'group_a'], ['no_key' => true]]],
        [
            'group_a' => [['name' => '', 'type' => 'text'], ['type' => 'text'], ['name' => 'zeta', 'type' => 'text'], ['name' => 'alpha', 'type' => 'text']],
            'group_b' => [['name' => 'tab_one', 'type' => 'tab'], ['name' => 'note', 'type' => 'message'], ['name' => 'acc', 'type' => 'accordion'], ['name' => 'mid', 'type' => 'number']],
        ],
    );

    $fields = (new Site)->fields_for_post(12, 'provider');

    expect(array_keys($fields))->toBe(['alpha', 'mid', 'zeta'])
        ->and($fields['mid'])->toBe(['name' => 'mid', 'type' => 'number'])
        ->and($GLOBALS['site_acf_log'])->toBe([
            ['groups', ['post_id' => 12]],
            ['groups', ['post_type' => 'provider']],
            ['fields', 'group_a'],
            ['fields', 'group_b'],
        ]);
});

it('keeps the first field of a name when two groups define it', function (): void {
    site_acf(
        ['post_id' => [['key' => 'group_b'], ['key' => 'group_a']]],
        ['group_a' => [['name' => 'same', 'type' => 'text', 'from' => 'a']], 'group_b' => [['name' => 'same', 'type' => 'text', 'from' => 'b']]],
    );

    expect((new Site)->fields_for_post(1, 'p')['same']['from'])->toBe('a');
});

it('counts a group that applies twice once', function (): void {
    site_acf(
        ['post_id' => [['key' => 'group_a']], 'post_type' => [['key' => 'group_a']]],
        ['group_a' => [['name' => 'f', 'type' => 'text']]],
    );

    (new Site)->fields_for_post(1, 'p');

    expect(array_values(array_filter($GLOBALS['site_acf_log'], fn (array $c): bool => $c[0] === 'fields')))->toBe([['fields', 'group_a']]);
});

it('treats an ACF failure to list groups or fields as none', function (): void {
    site_acf(['post_id' => [['key' => 'group_a']]], []);

    expect((new Site)->fields_for_post(1, 'p'))->toBe([]);
});

it('lists no fields when no group applies', function (): void {
    site_acf([], []);

    expect((new Site)->fields_for_post(1, 'p'))->toBe([]);
});

it('asks ACF once for the same groups and post type', function (): void {
    site_acf(['post_id' => [['key' => 'group_a']]], ['group_a' => [['name' => 'f', 'type' => 'text']]]);
    $site = new Site;

    $site->fields_for_post(1, 'p');

    $before = count(array_filter($GLOBALS['site_acf_log'], fn (array $c): bool => $c[0] === 'fields'));
    $again = $site->fields_for_post(2, 'p');
    $after = count(array_filter($GLOBALS['site_acf_log'], fn (array $c): bool => $c[0] === 'fields'));

    expect($before)->toBe(1)
        ->and($after)->toBe(1)
        ->and(array_keys($again))->toBe(['f']);
});

it('keeps the fields of a post type apart from another type with the same groups', function (): void {
    site_acf(['post_id' => [['key' => 'group_a']]], ['group_a' => [['name' => 'f', 'type' => 'text']]]);
    $site = new Site;

    $site->fields_for_post(1, 'one');
    $site->fields_for_post(1, 'two');

    expect(count(array_filter($GLOBALS['site_acf_log'], fn (array $c): bool => $c[0] === 'fields')))->toBe(2);
});

it('keeps the fields of different groups apart for one post type', function (): void {
    site_acf(
        ['post_id' => [['key' => 'group_a']], 'post_type' => []],
        ['group_a' => [['name' => 'fa', 'type' => 'text']], 'group_b' => [['name' => 'fb', 'type' => 'text']]],
    );
    $site = new Site;

    $first = $site->fields_for_post(1, 'p');
    site_acf(['post_id' => [['key' => 'group_b']]], ['group_b' => [['name' => 'fb', 'type' => 'text']]]);
    $second = $site->fields_for_post(2, 'p');

    expect(array_keys($first))->toBe(['fa'])
        ->and(array_keys($second))->toBe(['fb']);
});

it('does not mistake one post type and its groups for another', function (): void {
    $by_type = ['p' => [['key' => 'q']], 'y' => [['key' => 'x']]];
    $fields = ['q' => [['name' => 'from_q', 'type' => 'text']], 'x' => [['name' => 'from_x', 'type' => 'text']]];
    when('acf_get_field_groups')->alias(fn (array $args): array => isset($args['post_type']) ? ($by_type[$args['post_type']] ?? []) : []);
    when('acf_get_fields')->alias(fn (array $group): array => $fields[$group['key']]);

    $site = new Site;

    expect(array_keys($site->fields_for_post(1, 'pq')))->toBe([])
        ->and(array_keys($site->fields_for_post(1, 'p')))->toBe(['from_q'])
        ->and(array_keys($site->fields_for_post(1, 'xy')))->toBe([])
        ->and(array_keys($site->fields_for_post(1, 'y')))->toBe(['from_x']);
});

it('names the field types that never hold a value', function (): void {
    expect(Site::SKIP_TYPES)->toBe(['tab', 'message', 'accordion']);
});

/* ====================================================================== postmeta */

it('selects the meta rows of a simple field and its key reference', function (): void {
    $wpdb = site_wpdb();
    $wpdb->shouldReceive('get_results')->andReturnUsing(fn (string $sql, string $output): array => [[$sql, $output]]);

    $rows = (new Site)->meta_rows_for_field(12, 'my_field', false);

    expect($GLOBALS['site_prepared'])->toBe([[
        'SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = %d AND (meta_key = %s OR meta_key = %s) ORDER BY meta_id ASC',
        [[12, 'my_field', '_my_field']],
    ]])
        ->and($rows[0][1])->toBe('ARRAY_N')
        ->and($rows[0][0])->toStartWith('SELECT meta_key, meta_value FROM wp_postmeta');
});

it('selects the sub-field rows of a complex field too, with the name escaped for LIKE', function (): void {
    $wpdb = site_wpdb();
    $wpdb->shouldReceive('get_results')->andReturn([]);

    (new Site)->meta_rows_for_field(12, 'my_field', true);

    expect($GLOBALS['site_prepared'])->toBe([[
        'SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = %d AND (meta_key = %s OR meta_key = %s OR meta_key LIKE %s OR meta_key LIKE %s) ORDER BY meta_id ASC',
        [[12, 'my_field', '_my_field', 'my\\_field\\_%', '\\_my\\_field\\_%']],
    ]]);
});

it('deletes the same rows it selects', function (bool $complex, string $where, array $params): void {
    $wpdb = site_wpdb();
    $wpdb->shouldReceive('query')->once()->andReturn(4);

    $deleted = (new Site)->delete_meta_rows_for_field(12, 'my_field', $complex);

    expect($deleted)->toBe(4)
        ->and($GLOBALS['site_prepared'])->toBe([["DELETE FROM wp_postmeta WHERE post_id = %d AND $where", [array_merge([12], $params)]]]);
})->with([
    'simple' => [false, '(meta_key = %s OR meta_key = %s)', ['my_field', '_my_field']],
    'complex' => [true, '(meta_key = %s OR meta_key = %s OR meta_key LIKE %s OR meta_key LIKE %s)', ['my_field', '_my_field', 'my\\_field\\_%', '\\_my\\_field\\_%']],
]);

it('hands the prepared delete to the database', function (): void {
    $wpdb = site_wpdb();
    $ran = new ArrayObject;
    $wpdb->shouldReceive('query')->andReturnUsing(function (string $sql) use ($ran): int {
        $ran[] = $sql;

        return 1;
    });

    (new Site)->delete_meta_rows_for_field(3, 'f', false);

    expect($ran->getArrayCopy())->toBe(['DELETE FROM wp_postmeta WHERE post_id = %d AND (meta_key = %s OR meta_key = %s)|[[3,"f","_f"]]']);
});

/* ====================================================================== output folder */

it('returns the folder it is given as it is when there is no sub-folder', function (mixed $sub): void {
    $dir = site_dir();
    Functions\expect('wp_mkdir_p')->never();

    expect(Site::out_dir($dir.'/', $sub))->toBe($dir);
})->with([[''], ['0'], [null]]);

it('adds the sub-folder after trimming its slashes', function (): void {
    $dir = site_dir(['sub/inner/x.txt' => '']);
    Functions\expect('wp_mkdir_p')->never();

    expect(Site::out_dir($dir, '/sub/inner/'))->toBe($dir.'/sub/inner');
});

it('creates a folder that does not exist', function (): void {
    $asked = new ArrayObject;
    SiteTestFs::$dir = false;
    when('wp_mkdir_p')->alias(function (string $path) use ($asked): bool {
        $asked[] = $path;

        return true;
    });

    expect(Site::out_dir('/base/', 'sub'))->toBe('/base/sub')
        ->and($asked->getArrayCopy())->toBe(['/base/sub'])
        ->and(CommandTestCli::$calls)->toBe([]);
});

it('stops with an error when the folder cannot be created', function (): void {
    SiteTestFs::$dir = false;
    when('wp_mkdir_p')->justReturn(false);

    expect(fn (): string => Site::out_dir('/base', ''))->toThrow(RuntimeException::class, 'WP_CLI::error: Cannot create output directory: /base');
});

it('does not try to create a folder that exists', function (): void {
    SiteTestFs::$dir = true;
    Functions\expect('wp_mkdir_p')->never();

    expect(Site::out_dir('/base', 'sub'))->toBe('/base/sub');
});

it('puts output one level above the web root when that is writable', function (): void {
    SiteTestFs::$writable = true;
    SiteTestFs::$dir = true;

    $parent = dirname(rtrim(ABSPATH, '/'));

    expect(Site::out_dir())->toBe($parent.'/acfcdt-migration')
        ->and(SiteTestFs::$calls[0])->toBe(['is_writable', $parent]);
});

it('adds the sub-folder to the default output folder', function (): void {
    SiteTestFs::$writable = true;
    SiteTestFs::$dir = true;

    expect(Site::out_dir(null, 'snap'))->toBe(dirname(rtrim(ABSPATH, '/')).'/acfcdt-migration/snap');
});

it('falls back to wp-content when the folder above the web root is not writable', function (): void {
    SiteTestFs::$writable = false;
    SiteTestFs::$dir = true;

    expect(Site::out_dir())->toBe('/wp-content-test/acfcdt-migration');
});

it('prefers the folder it is given over the default', function (): void {
    SiteTestFs::$dir = true;

    expect(Site::out_dir('/given'))->toBe('/given')
        ->and(SiteTestFs::$calls)->toBe([['is_dir', '/given']]);
});

/* ====================================================================== constants (these define them, so they run last) */

it('uses the JSON folder constant before ACF and the uploads folder', function (): void {
    site_acf_setting('/themes/acf-json');
    define('ACFCDT_JSON_DIR', '/constant/json/');

    expect((new Site)->json_dir())->toBe('/constant/json')
        ->and($GLOBALS['site_acf_calls'])->toBe([]);
});

it('uses the output folder constant before the default', function (): void {
    SiteTestFs::$dir = true;
    define('ACFCDT_MIGRATION_DIR', '/constant/out/');

    expect(Site::out_dir())->toBe('/constant/out')
        ->and(SiteTestFs::$calls)->toBe([['is_dir', '/constant/out']]);
});
