<?php

/**
 * Engine, read side: construction, ACF readiness, ref cleaning, post counting and paging, the inventory, orphan
 * columns and the raw table-row stream, with WordPress, ACF and `$wpdb` mocked.
 *
 * `Site`, `Store` and `HookGuard` methods are redefined with Patchwork, so each test states what the collaborator
 * answers. `$wpdb` is a Mockery stand-in whose `prepare()` records its arguments and returns the query behind a `P:`
 * prefix; each executing method records what it was handed and returns what the test queued. Whether MySQL accepts
 * the SQL is for the Integration suite. `function_exists()` is a namespace shim (tests/Unit/Fixtures/engine-shims.php)
 * that passes through unless a test sets an answer.
 *
 * The mutants this file owns are those in `includes/class-engine.php` lines 18 to 225. Mutation run: `vendor/bin/pest
 * tests/Unit/EngineInventoryTest.php --mutate --path=includes --class='UAMSWP_ACFCDT_Migrator\Engine'
 * --covered-only`, 162 mutants, 0 survivors, no timeouts (macOS, PHP 8.4.27, PCOV; a working run, not the Linux run
 * that closes the coverage ticket). Three annotations in the source, each an equivalent mutant: the empty-name check
 * the pattern repeats, and the first index `array_fill()` numbers from, twice. Helper names carry the `eng_inv` prefix
 * so the sibling Engine test files can define their own without a clash.
 *
 * @see includes/class-engine.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Mockery\MockInterface;
use UAMSWP_ACFCDT_Migrator\Engine;
use UAMSWP_ACFCDT_Migrator\HookGuard;
use UAMSWP_ACFCDT_Migrator\Site;
use UAMSWP_ACFCDT_Migrator\Store;

use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;

require_once __DIR__.'/Fixtures/engine-shims.php';

defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

beforeEach(function (): void {
    EngineTestFn::reset();

    $GLOBALS['eng_inv_prepared'] = [];
    $GLOBALS['eng_inv_executed'] = [];
    $GLOBALS['eng_inv_returns'] = [];
    $GLOBALS['eng_inv_fields'] = [];
    $GLOBALS['eng_inv_installs'] = new ArrayObject;

    when('get_current_blog_id')->justReturn(5);
    when('home_url')->alias(fn (string $path = ''): string => 'https://example.test'.$path);
    when('absint')->alias(fn (mixed $n): int => abs((int) $n));
    when('esc_sql')->alias(fn (string $s): string => 'esc('.$s.')');
    when('acf_get_field')->alias(fn (string $key): array|false => $GLOBALS['eng_inv_fields'][$key] ?? false);

    $installs = $GLOBALS['eng_inv_installs'];
    redefine(Store::class.'::install', function () use ($installs): void {
        $installs[] = 'install';
    });
});

afterEach(function (): void {
    EngineTestFn::reset();
    unset($GLOBALS['eng_inv_prepared'], $GLOBALS['eng_inv_executed'], $GLOBALS['eng_inv_returns'], $GLOBALS['eng_inv_fields'], $GLOBALS['eng_inv_installs'], $GLOBALS['wpdb']);
});

/**
 * A `$wpdb` that records each prepared query and each executed statement, and answers from what the test queued.
 *
 * `$returns` maps a method name to a list of answers handed out in order (the last one repeats).
 *
 * @param  array<string, list<mixed>>  $returns
 */
function eng_inv_wpdb(array $returns = []): MockInterface
{
    $GLOBALS['eng_inv_returns'] = $returns;
    /** @var wpdb&MockInterface $wpdb */
    $wpdb = Mockery::mock('wpdb');
    $wpdb->posts = 'wpx_posts';
    $wpdb->shouldReceive('prepare')->andReturnUsing(function (string $query, ...$args): string {
        $GLOBALS['eng_inv_prepared'][] = [$query, $args];

        return 'P:'.$query;
    });

    foreach (['get_var', 'get_results', 'get_col'] as $method) {
        $wpdb->shouldReceive($method)->andReturnUsing(function (string $sql, ...$extra) use ($method): mixed {
            $GLOBALS['eng_inv_executed'][] = [$method, $sql, $extra];
            if (count($GLOBALS['eng_inv_executed']) > 20) {
                throw new LogicException('Runaway loop: more than 20 statements executed.');
            }

            $queue = &$GLOBALS['eng_inv_returns'][$method];
            if (! isset($queue) || $queue === []) {
                return null;
            }

            return count($queue) > 1 ? array_shift($queue) : $queue[0];
        });
    }

    $GLOBALS['wpdb'] = $wpdb;

    return $wpdb;
}

/** Collapses every run of whitespace, so a multi-line query is compared as one line. */
function eng_inv_flat(string $sql): string
{
    return trim((string) preg_replace('/\s+/', ' ', $sql));
}

/**
 * The statements executed, as `[method, flattened sql]` pairs.
 *
 * @return list<array{mixed, string}>
 */
function eng_inv_log(): array
{
    return array_values(array_map(fn (array $e): array => [$e[0], eng_inv_flat($e[1])], $GLOBALS['eng_inv_executed']));
}

/**
 * An engine whose `Site` answers as given.
 *
 * @param  array<string, Closure>  $site  method name => replacement
 */
function eng_inv_engine(array $site = []): Engine
{
    foreach ($site as $method => $fn) {
        redefine(Site::class.'::'.$method, $fn);
    }

    return new Engine;
}

/** The em dash the inventory notes use, written as an escape so no literal U+2014 sits in this file. */
function eng_inv_dash(): string
{
    return "\u{2014}";
}

// acf_ready()

it('is ready only when both ACF functions exist', function (bool $value, bool $groups, bool $expected): void {
    EngineTestFn::$answers = ['acf_get_value' => $value, 'acf_get_field_groups' => $groups];

    expect(Engine::acf_ready())->toBe($expected)
        ->and(EngineTestFn::$calls)->toContain('acf_get_value');
})->with([
    'both' => [true, true, true],
    'no value function' => [false, true, false],
    'no groups function' => [true, false, false],
    'neither' => [false, false, false],
]);

it('checks the groups function only when the value function exists', function (): void {
    EngineTestFn::$answers = ['acf_get_value' => false];

    Engine::acf_ready();

    expect(EngineTestFn::$calls)->toBe(['acf_get_value']);
});

// clean_ref()

it('accepts names of letters, numbers, dot, dash and underscore', function (mixed $ref, string $expected): void {
    expect(Engine::clean_ref($ref))->toBe($expected);
})->with([
    'letters' => ['before', 'before'],
    'all allowed characters' => ['A-z_0.9', 'A-z_0.9'],
    'one character' => ['a', 'a'],
    'sixty characters' => [str_repeat('a', 60), str_repeat('a', 60)],
    'an integer' => [12, '12'],
]);

it('rejects a name that is empty, too long or has other characters', function (mixed $ref, string $shown): void {
    expect(fn (): string => Engine::clean_ref($ref))->toThrow(
        InvalidArgumentException::class,
        "Names may only contain letters, numbers, dot, dash and underscore (max 60): '$shown'"
    );
})->with([
    'empty' => ['', ''],
    'null' => [null, ''],
    'sixty-one characters' => [str_repeat('a', 61), str_repeat('a', 61)],
    'a space' => ['a b', 'a b'],
    'a slash' => ['a/b', 'a/b'],
    'a leading space' => [' a', ' a'],
]);

// __construct()

it('builds a Site when none is given and installs the table', function (): void {
    $engine = new Engine;

    expect($engine->site)->toBeInstanceOf(Site::class)
        ->and($engine->site->blog_id)->toBe(5)
        ->and($engine->site->url)->toBe('https://example.test/')
        ->and(count($GLOBALS['eng_inv_installs']))->toBe(1);
});

it('keeps the Site it is given', function (): void {
    $site = new Site;

    $engine = new Engine($site);

    expect($engine->site)->toBe($site)
        ->and(count($GLOBALS['eng_inv_installs']))->toBe(1);
});

// total_posts()

it("counts the posts of the site's post types", function (): void {
    $seen = new ArrayObject;
    $engine = eng_inv_engine([
        'post_types' => fn (): array => ['doctor', 'clinic'],
        'posts' => function (array $types, $ids = null, $limit = 0) use ($seen): array {
            $seen['args'] = [$types, $ids];

            return [1, 2, 3];
        },
    ]);

    expect($engine->total_posts())->toBe(3)
        ->and($seen['args'])->toBe([['doctor', 'clinic'], null]);
});

it('passes a restriction to the post query', function (): void {
    $seen = new ArrayObject;
    $engine = eng_inv_engine([
        'post_types' => fn (): array => ['doctor'],
        'posts' => function (array $types, $ids = null, $limit = 0) use ($seen): array {
            $seen['ids'] = $ids;

            return [];
        },
    ]);

    expect($engine->total_posts([4, 5]))->toBe(0)
        ->and($seen['ids'])->toBe([4, 5]);
});

// posts_upto()

it('counts the posts at or below a cursor', function (): void {
    eng_inv_wpdb(['get_var' => ['7']]);
    $engine = eng_inv_engine(['post_types' => fn (): array => ['doctor', 'clinic']]);

    $count = $engine->posts_upto('12');

    [$query, $args] = $GLOBALS['eng_inv_prepared'][0];
    expect($count)->toBe(7)
        ->and(eng_inv_flat($query))->toBe("SELECT COUNT(*) FROM wpx_posts WHERE post_type IN (%s,%s) AND post_status NOT IN ('auto-draft','inherit') AND ID <= %d")
        ->and($args)->toBe([['doctor', 'clinic', 12]])
        ->and($GLOBALS['eng_inv_executed'][0][0])->toBe('get_var')
        ->and($GLOBALS['eng_inv_executed'][0][1])->toBe('P:'.$query);
});

it('counts nothing without post types or without a cursor, and runs no query', function (array $types, mixed $cursor): void {
    eng_inv_wpdb();
    $engine = eng_inv_engine(['post_types' => fn (): array => $types]);

    expect($engine->posts_upto($cursor))->toBe(0)
        ->and($GLOBALS['eng_inv_executed'])->toBe([]);
})->with([
    'no types' => [[], 10],
    'cursor zero' => [['doctor'], 0],
    'cursor zero string' => [['doctor'], '0'],
    'cursor null' => [['doctor'], null],
    'cursor empty' => [['doctor'], ''],
    'no types and no cursor' => [[], 0],
]);

it('counts zero when the query returns null', function (): void {
    eng_inv_wpdb(['get_var' => [null]]);
    $engine = eng_inv_engine(['post_types' => fn (): array => ['doctor']]);

    expect($engine->posts_upto(3))->toBe(0);
});

// next_posts()

/** Calls the private `next_posts()`. */
function eng_inv_next(Engine $engine, mixed ...$args): mixed
{
    return (new ReflectionMethod(Engine::class, 'next_posts'))->invoke($engine, ...$args);
}

it('pages posts above a cursor in ascending order', function (): void {
    eng_inv_wpdb(['get_results' => [['row']]]);
    $engine = eng_inv_engine(['post_types' => fn (): array => ['doctor', 'clinic']]);

    $rows = eng_inv_next($engine, '9', 25);

    [$query, $args] = $GLOBALS['eng_inv_prepared'][0];
    expect($rows)->toBe(['row'])
        ->and(eng_inv_flat($query))->toBe("SELECT ID, post_type, post_status, post_title, post_modified FROM wpx_posts WHERE post_type IN (%s,%s) AND post_status NOT IN ('auto-draft','inherit') AND ID > %d")
        ->and($args)->toBe([['doctor', 'clinic', 9]])
        ->and(eng_inv_log())->toBe([['get_results', 'P:'.eng_inv_flat($query).' ORDER BY ID ASC LIMIT 25']]);
});

it('returns no posts, and runs no query, when the site has no post types', function (): void {
    eng_inv_wpdb();
    $engine = eng_inv_engine(['post_types' => fn (): array => []]);

    expect(eng_inv_next($engine, 0, 10))->toBe([])
        ->and($GLOBALS['eng_inv_executed'])->toBe([]);
});

it('restricts the page to the given ids as integers', function (): void {
    eng_inv_wpdb(['get_results' => [[]]]);
    $engine = eng_inv_engine(['post_types' => fn (): array => ['doctor']]);

    eng_inv_next($engine, 0, 10, ['5', '7abc', -3]);

    expect($GLOBALS['eng_inv_executed'][0][1])->toStartWith('P:SELECT ID, post_type')
        ->and($GLOBALS['eng_inv_executed'][0][1])->toEndWith(' AND ID > %d AND ID IN (5,7,3) ORDER BY ID ASC LIMIT 10');
});

it('adds no id restriction for an empty list', function (): void {
    eng_inv_wpdb(['get_results' => [[]]]);
    $engine = eng_inv_engine(['post_types' => fn (): array => ['doctor']]);

    eng_inv_next($engine, 0, 10, []);

    expect($GLOBALS['eng_inv_executed'][0][1])->not->toContain(' AND ID IN (')
        ->and($GLOBALS['eng_inv_executed'][0][1])->toEndWith(' ORDER BY ID ASC LIMIT 10');
});

it('limits to at least one row', function (mixed $limit, string $expected): void {
    eng_inv_wpdb(['get_results' => [[]]]);
    $engine = eng_inv_engine(['post_types' => fn (): array => ['doctor']]);

    eng_inv_next($engine, 0, $limit);

    expect($GLOBALS['eng_inv_executed'][0][1])->toEndWith('LIMIT '.$expected);
})->with([
    'zero' => [0, '1'],
    'negative' => [-5, '1'],
    'one' => [1, '1'],
    'two' => [2, '2'],
    'a numeric string' => ['25', '25'],
    'text' => ['abc', '1'],
]);

// inventory()

/**
 * A table definition as `Site::table_defs()` returns it.
 *
 * @param  list<array{name: string, identifier: string}>  $columns
 * @return array<string, mixed>
 */
function eng_inv_def(string $name, string $post_type, array $columns = [], string $file = '/json/x.json'): array
{
    return ['name' => $name, 'post_type' => $post_type, 'file' => $file, 'columns' => $columns];
}

/**
 * Wires the collaborators `inventory()` reads.
 *
 * @param  list<array<string, mixed>>  $defs
 * @param  list<string>  $existing
 */
function eng_inv_wire(array $defs, array $existing, mixed $core_meta = true): Engine
{
    redefine(HookGuard::class.'::__construct', fn (): null => null);
    redefine(HookGuard::class.'::plugin_loaded', fn (): bool => true);
    redefine(HookGuard::class.'::callback_count', fn (): int => 3);
    redefine(HookGuard::class.'::hook_names', fn (): array => ['acf/load_value', 'acf/update_value']);
    Functions\expect('apply_filters')->once()->with('acfcdt/settings/store_acf_values_in_core_meta', true)->andReturn($core_meta);

    return eng_inv_engine([
        'json_dir' => fn (): string => '/json',
        'cache_status' => fn (): array => ['ok' => true],
        'cache_problems' => fn (): array => ['problem'],
        'table_defs' => fn (): array => $defs,
        'prefixed' => fn ($name): string => 'wpx_'.$name,
        'table_exists' => fn ($name): bool => in_array($name, $existing, true),
    ]);
}

it('reports the site, the hooks and the cache', function (): void {
    eng_inv_wpdb();
    $engine = eng_inv_wire([], [], 1);

    expect($engine->inventory())->toBe([
        'blog_id' => 5,
        'url' => 'https://example.test/',
        'json_dir' => '/json',
        'plugin_loaded' => true,
        'intercepts' => 3,
        'hooks' => ['acf/load_value', 'acf/update_value'],
        'core_meta' => true,
        'cache' => ['ok' => true],
        'cache_problems' => ['problem'],
        'tables' => [],
    ]);
});

it('casts the core meta setting to a boolean', function (mixed $setting, bool $expected): void {
    eng_inv_wpdb();
    $engine = eng_inv_wire([], [], $setting);

    expect($engine->inventory()['core_meta'])->toBe($expected);
})->with([
    'one' => [1, true],
    'zero' => [0, false],
    'a string' => ['no', true],
    'empty string' => ['', false],
]);

it('lists a missing table without querying it', function (): void {
    eng_inv_wpdb();
    $engine = eng_inv_wire([eng_inv_def('doctors', 'doctor', [], '/json/doctors.json')], []);

    expect($engine->inventory()['tables'])->toBe([
        ['table' => 'wpx_doctors', 'name' => 'doctors', 'post_type' => 'doctor', 'def_file' => 'doctors.json', 'exists' => false],
    ])
        ->and($GLOBALS['eng_inv_executed'])->toBe([]);
});

it('measures an existing table with one query per figure', function (): void {
    eng_inv_wpdb([
        'get_var' => ['10', '2', '3', '4'],
        'get_col' => [['id', 'post_id', 'extra'], ['id', 'post_id', 'extra']],
    ]);
    $engine = eng_inv_wire([eng_inv_def('doctors', 'doctor', [['name' => 'extra', 'identifier' => '']], '/a/b/doctors.json')], ['doctors']);

    $table = $engine->inventory()['tables'][0];

    expect($table)->toBe([
        'table' => 'wpx_doctors',
        'name' => 'doctors',
        'post_type' => 'doctor',
        'def_file' => 'doctors.json',
        'exists' => true,
        'rows' => 10,
        'missing_post' => 2,
        'wrong_type' => 3,
        'no_row' => 4,
        'extra_cols' => ['id', 'post_id'],
        'missing_cols' => [],
        'columns' => [],
        'orphans' => ['extra'],
    ]);

    $log = eng_inv_log();
    expect($log[0])->toBe(['get_var', 'SELECT COUNT(*) FROM `wpx_doctors`'])
        ->and($log[1])->toBe(['get_var', 'SELECT COUNT(*) FROM `wpx_doctors` t LEFT JOIN wpx_posts p ON p.ID = t.post_id WHERE p.ID IS NULL'])
        ->and($log[2])->toBe(['get_var', 'P:SELECT COUNT(*) FROM `wpx_doctors` t JOIN wpx_posts p ON p.ID = t.post_id WHERE p.post_type <> %s'])
        ->and($log[3])->toBe(['get_var', "P:SELECT COUNT(*) FROM wpx_posts p LEFT JOIN `wpx_doctors` t ON t.post_id = p.ID WHERE p.post_type = %s AND p.post_status NOT IN ('auto-draft','inherit') AND t.post_id IS NULL"])
        ->and($log[4])->toBe(['get_col', 'SHOW COLUMNS FROM `wpx_doctors`'])
        ->and($GLOBALS['eng_inv_prepared'][0][1])->toBe(['doctor'])
        ->and($GLOBALS['eng_inv_prepared'][1][1])->toBe(['doctor']);
});

it('lists columns present in the database but not the definition, and the reverse', function (): void {
    eng_inv_wpdb([
        'get_var' => ['1', '0', '0', '0'],
        'get_col' => [['id', 'a', 'z'], ['id', 'a', 'z']],
    ]);
    $defs = [eng_inv_def('t', 'doctor', [['name' => 'a', 'identifier' => ''], ['name' => 'b', 'identifier' => ''], ['name' => 'c', 'identifier' => '']])];
    $engine = eng_inv_wire($defs, ['t']);

    $table = $engine->inventory()['tables'][0];

    expect($table['extra_cols'])->toBe(['id', 'z'])
        ->and($table['missing_cols'])->toBe(['b', 'c']);
});

it('reports one entry per identified column found in the database', function (): void {
    eng_inv_wpdb([
        'get_var' => ['1', '0', '0', '0', '5', '0'],
        'get_col' => [['id', 'a', 'b', 'skipped'], ['id', 'a', 'b', 'skipped']],
    ]);
    $GLOBALS['eng_inv_fields'] = [
        'field_a' => ['type' => 'text', 'name' => 'a'],
        'b' => ['type' => 'text', 'name' => 'b'],
    ];
    $defs = [eng_inv_def('t', 'doctor', [
        ['name' => 'skipped', 'identifier' => ''],
        ['name' => 'not_in_db', 'identifier' => 'field_x'],
        ['name' => 'a', 'identifier' => 'field_a'],
        ['name' => 'b', 'identifier' => 'b'],
    ])];
    $engine = eng_inv_wire($defs, ['t']);

    expect($engine->inventory()['tables'][0]['columns'])->toBe([
        ['column' => 'a', 'acf_name' => 'field_a *', 'acf_type' => 'text', 'non_empty' => 5, 'note' => ''],
        ['column' => 'b', 'acf_name' => 'b', 'acf_type' => 'text', 'non_empty' => 0, 'note' => ''],
    ]);

    $queries = array_column(array_filter(eng_inv_log(), fn (array $e): bool => $e[0] === 'get_var'), 1);
    expect($queries[4])->toBe("SELECT COUNT(*) FROM `wpx_t` WHERE `esc(a)` IS NOT NULL AND `esc(a)` NOT IN ('', '[]', '{}', 'null', '\"\"')")
        ->and($queries[5])->toContain('`esc(b)`');
});

it('matches a column name to the database with strict comparison', function (): void {
    eng_inv_wpdb([
        'get_var' => ['0', '0', '0', '0'],
        'get_col' => [['1e1'], ['1e1']],
    ]);
    $GLOBALS['eng_inv_fields'] = ['field_ten' => ['type' => 'text', 'name' => 'ten']];
    $defs = [eng_inv_def('t', 'doctor', [['name' => '10', 'identifier' => 'field_ten']])];
    $engine = eng_inv_wire($defs, ['t']);

    expect($engine->inventory()['tables'][0]['columns'])->toBe([]);
});

it('notes a column whose ACF field is gone', function (): void {
    eng_inv_wpdb(['get_var' => ['0', '0', '0', '0', '2'], 'get_col' => [['a'], ['a']]]);
    $engine = eng_inv_wire([eng_inv_def('t', 'doctor', [['name' => 'a', 'identifier' => 'field_gone']])], ['t']);

    expect($engine->inventory()['tables'][0]['columns'])->toBe([
        ['column' => 'a', 'acf_name' => 'field_gone *', 'acf_type' => '-', 'non_empty' => 2, 'note' => 'ORPHAN: no ACF field by this name '.eng_inv_dash().' archive only'],
    ]);
});

it('treats every column as an orphan when ACF cannot look fields up', function (): void {
    EngineTestFn::$answers = ['acf_get_field' => false];
    $GLOBALS['eng_inv_fields'] = ['field_a' => ['type' => 'text', 'name' => 'a']];
    eng_inv_wpdb(['get_var' => ['0', '0', '0', '0', '0'], 'get_col' => [['a'], ['a']]]);
    $engine = eng_inv_wire([eng_inv_def('t', 'doctor', [['name' => 'a', 'identifier' => 'field_a']])], ['t']);

    $column = $engine->inventory()['tables'][0]['columns'][0];

    expect($column['acf_type'])->toBe('-')
        ->and($column['note'])->toStartWith('ORPHAN')
        ->and(EngineTestFn::$calls)->toContain('acf_get_field');
});

it('names the parent of a nested field', function (): void {
    eng_inv_wpdb(['get_var' => ['0', '0', '0', '0', '0'], 'get_col' => [['a'], ['a']]]);
    $GLOBALS['eng_inv_fields'] = [
        'field_a' => ['type' => 'text', 'name' => 'a', 'parent' => 'field_group'],
        'field_group' => ['type' => 'group', 'name' => 'details'],
    ];
    $engine = eng_inv_wire([eng_inv_def('t', 'doctor', [['name' => 'a', 'identifier' => 'field_a']])], ['t']);

    expect($engine->inventory()['tables'][0]['columns'][0]['note'])->toBe('nested inside group "details" '.eng_inv_dash().' plugin likely never intercepted it');
});

it('falls back to the parent key when the parent cannot be found', function (): void {
    eng_inv_wpdb(['get_var' => ['0', '0', '0', '0', '0'], 'get_col' => [['a'], ['a']]]);
    $GLOBALS['eng_inv_fields'] = ['field_a' => ['type' => 'text', 'name' => 'a', 'parent' => 'field_missing']];
    $engine = eng_inv_wire([eng_inv_def('t', 'doctor', [['name' => 'a', 'identifier' => 'field_a']])], ['t']);

    expect($engine->inventory()['tables'][0]['columns'][0]['note'])->toBe('nested inside field_missing '.eng_inv_dash().' plugin likely never intercepted it');
});

it('does not treat a parent that is not a field key as nesting', function (mixed $parent): void {
    eng_inv_wpdb(['get_var' => ['0', '0', '0', '0', '0'], 'get_col' => [['a'], ['a']]]);
    $GLOBALS['eng_inv_fields'] = ['field_a' => ['type' => 'text', 'name' => 'a', 'parent' => $parent]];
    $engine = eng_inv_wire([eng_inv_def('t', 'doctor', [['name' => 'a', 'identifier' => 'field_a']])], ['t']);

    expect($engine->inventory()['tables'][0]['columns'][0]['note'])->toBe('');
})->with([
    'a post id' => [123],
    'a list' => [['field_x']],
    'a group key' => ['group_5'],
    'a key with the prefix inside' => ['xfield_5'],
    'empty' => [''],
    'zero' => [0],
]);

it('notes the field types the plugin does not support or encodes', function (string $type, string $note): void {
    eng_inv_wpdb(['get_var' => ['0', '0', '0', '0', '0'], 'get_col' => [['a'], ['a']]]);
    $GLOBALS['eng_inv_fields'] = ['field_a' => ['type' => $type, 'name' => 'a']];
    $engine = eng_inv_wire([eng_inv_def('t', 'doctor', [['name' => 'a', 'identifier' => 'field_a']])], ['t']);

    expect($engine->inventory()['tables'][0]['columns'][0]['note'])->toBe(str_replace('~', eng_inv_dash(), $note));
})->with([
    'group' => ['group', 'group ~ unsupported by plugin, values likely in postmeta'],
    'clone' => ['clone', 'clone ~ unsupported by plugin, values likely in postmeta'],
    'flexible content' => ['flexible_content', 'flexible_content ~ unsupported by plugin, values likely in postmeta'],
    'repeater' => ['repeater', 'repeater (encoded column)'],
    'text' => ['text', ''],
    'a loosely equal type' => ['0', ''],
]);

// orphan_columns()

it('lists database columns with no ACF field, skipping the key columns', function (): void {
    eng_inv_wpdb(['get_col' => [['id', 'post_id', 'known', 'gone', 'unnamed', 'absent']]]);
    when('acf_get_field')->alias(fn (string $key): array|false => $key === 'field_gone' ? false : ['type' => 'text']);
    $engine = eng_inv_engine(['prefixed' => fn ($name): string => 'wpx_'.$name]);
    $def = ['name' => 't', 'columns' => [
        ['name' => 'known', 'identifier' => 'field_known'],
        ['name' => 'gone', 'identifier' => 'field_gone'],
        ['name' => 'unnamed', 'identifier' => ''],
    ]];

    expect($engine->orphan_columns($def))->toBe(['gone', 'unnamed', 'absent'])
        ->and(eng_inv_log())->toBe([['get_col', 'SHOW COLUMNS FROM `wpx_t`']]);
});

it('lists nothing when every column has a field', function (): void {
    eng_inv_wpdb(['get_col' => [['id', 'a']]]);
    $GLOBALS['eng_inv_fields'] = ['field_a' => ['type' => 'text']];
    $engine = eng_inv_engine(['prefixed' => fn ($name): string => 'wpx_'.$name]);

    expect($engine->orphan_columns(['name' => 't', 'columns' => [['name' => 'a', 'identifier' => 'field_a']]]))->toBe([]);
});

it('lists every table, a missing one among them', function (): void {
    eng_inv_wpdb(['get_var' => ['1', '0', '0', '0'], 'get_col' => [['id'], ['id']]]);
    $engine = eng_inv_wire([eng_inv_def('gone', 'doctor'), eng_inv_def('here', 'clinic')], ['here']);

    $tables = $engine->inventory()['tables'];

    expect(array_column($tables, 'name'))->toBe(['gone', 'here'])
        ->and(array_column($tables, 'exists'))->toBe([false, true]);
});

// each_table_row()

/**
 * Rows for a page.
 *
 * @return list<array<string, string>>
 */
function eng_inv_rows(int $from, int $to, bool $with_id = true): array
{
    $rows = [];
    for ($i = $from; $i <= $to; $i++) {
        $rows[] = $with_id ? ['id' => (string) $i, 'v' => 'r'.$i] : ['v' => 'r'.$i];
    }

    return $rows;
}

it('streams every row of a table with an id, paging by id', function (): void {
    eng_inv_wpdb(['get_col' => [['id', 'v']], 'get_results' => [eng_inv_rows(1, 500), eng_inv_rows(501, 503)]]);
    $engine = eng_inv_engine(['prefixed' => fn ($name): string => 'wpx_'.$name]);
    $seen = [];

    $count = $engine->each_table_row('doctors', function (array $row) use (&$seen): void {
        $seen[] = $row['id'];
    });

    expect($count)->toBe(503)
        ->and($seen)->toHaveCount(503)
        ->and($seen[0])->toBe('1')
        ->and($seen[502])->toBe('503')
        ->and($GLOBALS['eng_inv_prepared'])->toBe([
            ['SELECT * FROM `wpx_doctors` WHERE id > %d ORDER BY id ASC LIMIT 500', [0]],
            ['SELECT * FROM `wpx_doctors` WHERE id > %d ORDER BY id ASC LIMIT 500', [500]],
        ])
        ->and($GLOBALS['eng_inv_executed'][1][0])->toBe('get_results')
        ->and($GLOBALS['eng_inv_executed'][1][2])->toBe(['ARRAY_A'])
        ->and(eng_inv_log()[0])->toBe(['get_col', 'SHOW COLUMNS FROM `wpx_doctors`']);
});

it('pages a table with no id column by offset', function (): void {
    eng_inv_wpdb(['get_col' => [['v']], 'get_results' => [eng_inv_rows(1, 500, false), eng_inv_rows(501, 700, false)]]);
    $engine = eng_inv_engine(['prefixed' => fn ($name): string => 'wpx_'.$name]);
    $count = 0;

    $total = $engine->each_table_row('t', function (array $row) use (&$count): void {
        $count++;
    });

    expect($total)->toBe(700)
        ->and($count)->toBe(700)
        ->and($GLOBALS['eng_inv_prepared'])->toBe([])
        ->and(array_column(array_slice(eng_inv_log(), 1), 1))->toBe([
            'SELECT * FROM `wpx_t` LIMIT 500 OFFSET 0',
            'SELECT * FROM `wpx_t` LIMIT 500 OFFSET 500',
        ])
        ->and($GLOBALS['eng_inv_executed'][1][2])->toBe(['ARRAY_A']);
});

it('stops after a short page and after a full page followed by an empty one', function (int $first, int $queries, int $total): void {
    eng_inv_wpdb(['get_col' => [['id']], 'get_results' => [eng_inv_rows(1, $first), []]]);
    $engine = eng_inv_engine(['prefixed' => fn ($name): string => 'wpx_'.$name]);

    expect($engine->each_table_row('t', fn (array $row): null => null))->toBe($total)
        ->and(count($GLOBALS['eng_inv_executed']) - 1)->toBe($queries);
})->with([
    'none' => [0, 1, 0],
    'one short of a page' => [499, 1, 499],
    'a full page' => [500, 2, 500],
    'one over a page' => [501, 1, 501],
]);

it('treats a column list without an exact id column as having none', function (): void {
    eng_inv_wpdb(['get_col' => [['ID', 'post_id']], 'get_results' => [eng_inv_rows(1, 2, false)]]);
    $engine = eng_inv_engine(['prefixed' => fn ($name): string => 'wpx_'.$name]);

    $engine->each_table_row('t', fn (array $row): null => null);

    expect($GLOBALS['eng_inv_executed'][1][1])->toBe('SELECT * FROM `wpx_t` LIMIT 500 OFFSET 0');
});
