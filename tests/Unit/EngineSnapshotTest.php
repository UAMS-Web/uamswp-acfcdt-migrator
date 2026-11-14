<?php

/**
 * Engine snapshots: `snapshot_start()`, `snapshot_batch()` and `compare()`, with WordPress, ACF and `$wpdb` mocked.
 *
 * `Site`, `Store` and `HookGuard` methods are redefined with Patchwork, so each test states what the collaborator
 * answers and every call to it lands in one ordered log. `$wpdb` is a Mockery stand-in that answers the post query
 * `snapshot_batch()` issues and records what it was handed; whether MySQL accepts the SQL is for the Integration
 * suite. `Normalizer` is real: the values and the equality check it gives are part of what these methods do.
 *
 * The mutants this file owns are those in `includes/class-engine.php` lines 230 to 377. Mutation run: `vendor/bin/pest
 * tests/Unit/EngineSnapshotTest.php --mutate --path=includes --class='UAMSWP_ACFCDT_Migrator\Engine'
 * --covered-only`, 165 mutants of which 159 are killed and 6 survive, all six on lines 21, 85 and 88 (the inventory
 * slice's lines, which this file only reaches in passing), none in range, no timeouts (macOS, PHP 8.4.27, PCOV; a
 * working run, not the Linux run that closes the coverage ticket). One annotation in the source, an equivalent mutant:
 * the status default in `compare()` never equals `'complete'`.
 *
 * Helper names carry the `eng_snap` prefix so the sibling Engine test files can define their own without a clash.
 *
 * @see includes/class-engine.php
 */

declare(strict_types=1);

use Mockery\MockInterface;
use UAMSWP_ACFCDT_Migrator\Engine;
use UAMSWP_ACFCDT_Migrator\HookGuard;
use UAMSWP_ACFCDT_Migrator\Site;
use UAMSWP_ACFCDT_Migrator\Store;

use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;

require_once __DIR__.'/Fixtures/engine-shims.php';

beforeEach(function (): void {
    EngineTestFn::reset();

    when('get_current_blog_id')->justReturn(5);
    when('home_url')->alias(fn (string $path = ''): string => 'https://example.test'.$path);
    when('absint')->alias(fn (mixed $n): int => abs((int) $n));
    when('wp_json_encode')->alias(fn (mixed $v, int $flags = 0): string|false => json_encode($v, $flags));
    redefine(Store::class.'::install', function (): void {});
});

afterEach(function (): void {
    EngineTestFn::reset();
    unset($GLOBALS['wpdb']);
});

/**
 * Redefines `Store` and `HookGuard`, and returns the shared record of what was called.
 *
 * Keys of `$answers`: `get` (map of `kind|ref` to what `Store::get()` returns), `count` (what `Store::count()`
 * returns), `by_post` (map of ref to what `Store::by_post()` returns), `intercepts` (what `has_intercepts()` returns,
 * true by default). The log holds `[name, ...arguments]` rows in call order.
 *
 * @param  array<string, mixed>  $answers
 * @return ArrayObject<string, list<mixed>>
 */
function eng_snap_wire(array $answers = []): ArrayObject
{
    /** @var ArrayObject<string, list<mixed>> $st */
    $st = new ArrayObject(['log' => []]);
    $note = function (string $name, array $args) use ($st): void {
        $log = $st['log'];
        $log[] = [$name, ...$args];
        $st->offsetSet('log', $log);
    };

    redefine(Store::class.'::get', function (...$args) use ($note, $answers): mixed {
        $note('get', $args);

        return $answers['get'][$args[0].'|'.$args[1]] ?? null;
    });
    redefine(Store::class.'::put', function (...$args) use ($note): void {
        $note('put', [...$args, 'argc='.count($args)]);
    });
    redefine(Store::class.'::delete', function (...$args) use ($note): void {
        $note('delete', $args);
    });
    redefine(Store::class.'::count', function (...$args) use ($note, $answers): int {
        $note('count', $args);

        return $answers['count'] ?? 0;
    });
    redefine(Store::class.'::by_post', function (...$args) use ($note, $answers): array {
        $note('by_post', $args);

        return $answers['by_post'][$args[1]] ?? [];
    });
    redefine(HookGuard::class.'::__construct', function () use ($note): void {
        $note('guard.new', []);
    });
    redefine(HookGuard::class.'::has_intercepts', function () use ($note, $answers): bool {
        $note('guard.has_intercepts', []);

        return $answers['intercepts'] ?? true;
    });
    redefine(HookGuard::class.'::detach', function () use ($note): void {
        $note('guard.detach', []);
    });
    redefine(HookGuard::class.'::attach', function () use ($note): void {
        $note('guard.attach', []);
    });
    redefine(HookGuard::class.'::reset_caches', function () use ($note): void {
        $note('guard.reset_caches', []);
    });

    return $st;
}

/**
 * The logged calls, one row per call, filtered to the given names.
 *
 * @param  ArrayObject<string, list<mixed>>  $st
 * @param  list<string>  $names
 * @return list<array<int, mixed>>
 */
function eng_snap_calls(ArrayObject $st, array $names): array
{
    return array_values(array_filter($st['log'], fn (array $row): bool => in_array($row[0], $names, true)));
}

/**
 * The names of every logged call, in order.
 *
 * @param  ArrayObject<string, list<mixed>>  $st
 * @return list<string>
 */
function eng_snap_order(ArrayObject $st): array
{
    return array_map(fn (array $row): string => $row[0], $st['log']);
}

/**
 * A `$wpdb` that answers the post query with the given rows and records each prepared and executed statement.
 *
 * @param  list<object>  $rows
 */
function eng_snap_wpdb(array $rows): MockInterface
{
    $GLOBALS['eng_snap_prepared'] = [];
    $GLOBALS['eng_snap_executed'] = [];
    /** @var wpdb&MockInterface $wpdb */
    $wpdb = Mockery::mock('wpdb');
    $wpdb->posts = 'wpx_posts';
    $wpdb->shouldReceive('prepare')->andReturnUsing(function (string $query, ...$args): string {
        $GLOBALS['eng_snap_prepared'][] = [$query, $args];

        return 'P:'.$query;
    });
    $wpdb->shouldReceive('get_results')->andReturnUsing(function (string $sql) use ($rows): array {
        $GLOBALS['eng_snap_executed'][] = $sql;
        if (count($GLOBALS['eng_snap_executed']) > 5) {
            throw new LogicException('Runaway loop: more than 5 statements executed.');
        }

        return $rows;
    });
    $GLOBALS['wpdb'] = $wpdb;

    return $wpdb;
}

/** A post row as `$wpdb->get_results()` returns it: every column a string. */
function eng_snap_post(int $id, string $type = 'doctor', string $title = 'Title'): object
{
    return (object) ['ID' => (string) $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title, 'post_modified' => '2026-01-01 00:00:00'];
}

/**
 * An engine whose `Site` answers as given; `post_types` defaults to one type, `posts` and `cache_problems` to none.
 *
 * @param  array<string, Closure>  $site  method name => replacement
 */
function eng_snap_engine(array $site = []): Engine
{
    $site += ['post_types' => fn (): array => ['doctor'], 'posts' => fn (): array => [], 'cache_problems' => fn (): array => []];
    foreach ($site as $method => $fn) {
        redefine(Site::class.'::'.$method, $fn);
    }

    return new Engine;
}

/** The em dash the incomplete-snapshot message holds, written as an escape so no literal U+2014 sits in this file. */
function eng_snap_dash(): string
{
    return "\u{2014}";
}

// snapshot_start()

it('rejects a label with other characters before touching the store', function (): void {
    $st = eng_snap_wire();

    expect(fn (): string => eng_snap_engine()->snapshot_start('a b', 'effective'))
        ->toThrow(InvalidArgumentException::class, "Names may only contain letters, numbers, dot, dash and underscore (max 60): 'a b'")
        ->and($st['log'])->toBe([]);
});

it('accepts each snapshot mode', function (string $mode): void {
    eng_snap_wire();

    expect(eng_snap_engine(['posts' => fn (): array => []])->snapshot_start('before', $mode))->toBe('before');
})->with(['effective', 'meta']);

it('rejects any other mode, compared strictly', function (mixed $mode): void {
    $st = eng_snap_wire();

    expect(fn (): string => eng_snap_engine()->snapshot_start('before', $mode))
        ->toThrow(InvalidArgumentException::class, 'Mode must be effective or meta.')
        ->and($st['log'])->toBe([]);
})->with([
    'unknown' => ['both'],
    'wrong case' => ['Effective'],
    'a trailing space' => ['meta '],
    'zero' => [0],
    'true' => [true],
    'null' => [null],
]);

it('returns the cleaned label and looks the snapshot up under it', function (): void {
    $st = eng_snap_wire();

    $label = eng_snap_engine(['posts' => fn (): array => []])->snapshot_start(12, 'meta');

    expect($label)->toBe('12')
        ->and(eng_snap_calls($st, ['get']))->toBe([['get', 'snapinfo', '12']]);
});

it('refuses a label already in use unless told to overwrite, whatever the stored record holds', function (mixed $stored): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => $stored]]);

    expect(fn (): string => eng_snap_engine()->snapshot_start('before', 'effective'))
        ->toThrow(RuntimeException::class, "A snapshot named 'before' already exists on this site.")
        ->and(eng_snap_order($st))->toBe(['get']);
})->with([
    'a record' => [['mode' => 'effective']],
    'an empty record' => [[]],
    'zero' => [0],
]);

it('deletes the old snapshot and its rows before starting over when overwriting', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective']]]);

    $label = eng_snap_engine(['posts' => fn (): array => []])->snapshot_start('before', 'effective', true);

    expect($label)->toBe('before')
        ->and(eng_snap_order($st))->toBe(['get', 'delete', 'guard.new', 'guard.has_intercepts', 'put'])
        ->and(eng_snap_calls($st, ['delete']))->toBe([['delete', ['snapinfo', 'snapshot'], 'before']]);
});

it('does not delete anything for a new label', function (): void {
    $st = eng_snap_wire();

    eng_snap_engine(['posts' => fn (): array => []])->snapshot_start('before', 'effective', true);

    expect(eng_snap_calls($st, ['delete']))->toBe([])
        ->and(eng_snap_order($st))->toBe(['get', 'guard.new', 'guard.has_intercepts', 'put']);
});

it('stores a running record with the site details', function (): void {
    $st = eng_snap_wire();
    $seen = new ArrayObject;
    $engine = eng_snap_engine([
        'post_types' => fn (): array => ['doctor', 'clinic'],
        'posts' => function (array $types, $ids = null, $limit = 0) use ($seen): array {
            $seen['args'] = [$types, $ids];

            return [1, 2, 3];
        },
        'cache_problems' => fn (): array => ['object cache is external'],
    ]);
    $before = time();

    $engine->snapshot_start('before', 'effective');

    $put = eng_snap_calls($st, ['put']);
    expect($put)->toHaveCount(1)
        ->and(array_slice($put[0], 0, 5))->toBe(['put', 'snapinfo', 'before', 0, ''])
        ->and($put[0][6])->toBe('argc=5')
        ->and($seen['args'])->toBe([['doctor', 'clinic'], null]);

    $data = $put[0][5];
    expect(array_keys($data))->toBe(['mode', 'post_types', 'total', 'status', 'started', 'url', 'cache_problems'])
        ->and($data['mode'])->toBe('effective')
        ->and($data['post_types'])->toBe(['doctor', 'clinic'])
        ->and($data['total'])->toBe(3)
        ->and($data['status'])->toBe('running')
        ->and($data['url'])->toBe('https://example.test/')
        ->and($data['cache_problems'])->toBe(['object cache is external'])
        ->and($data['started'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and(strtotime($data['started']))->toBeGreaterThanOrEqual($before)
        ->and(strtotime($data['started']))->toBeLessThanOrEqual(time());
});

it('records the mode asked for only while the caching plugin is hooked in', function (bool $intercepts, string $mode, string $expected): void {
    $st = eng_snap_wire(['intercepts' => $intercepts]);

    eng_snap_engine(['posts' => fn (): array => []])->snapshot_start('before', $mode);

    expect(eng_snap_calls($st, ['put'])[0][5]['mode'])->toBe($expected);
})->with([
    'hooked, effective' => [true, 'effective', 'effective'],
    'hooked, meta' => [true, 'meta', 'meta'],
    'not hooked, effective' => [false, 'effective', 'meta (plugin absent)'],
    'not hooked, meta' => [false, 'meta', 'meta (plugin absent)'],
]);

// snapshot_batch()

/**
 * The two tracked fields the batch tests give every post, with what ACF returns for each.
 *
 * @return array<string, array<string, string>>
 */
function eng_snap_fields(): array
{
    return [
        'name' => ['type' => 'text', 'key' => 'field_name'],
        'bio' => ['type' => 'textarea', 'key' => 'field_bio'],
    ];
}

/**
 * Runs `snapshot_batch()` with `fields_for_post()` and `acf_get_value()` answered from the given maps.
 *
 * @param  array<int, mixed>  $values  post id => (field name => raw value)
 * @param  ArrayObject<int, mixed>  $calls  receives the call records: fields_for_post and acf_get_value
 * @return array<string, mixed>
 */
function eng_snap_batch(Engine $engine, ArrayObject $calls, array $values, string $label = 'before', mixed $cursor = 0, int $limit = 25, mixed $ids = null): array
{
    redefine(Site::class.'::fields_for_post', function (mixed $id, mixed $type) use ($calls): array {
        $calls[] = ['fields_for_post', $id, $type];

        return eng_snap_fields();
    });
    when('acf_get_value')->alias(function (mixed $id, array $field) use ($calls, $values): mixed {
        $calls[] = ['acf_get_value', $id, $field['key']];

        return $values[$id][$field['key']] ?? null;
    });

    return $engine->snapshot_batch($label, $cursor, $limit, $ids);
}

it('fails when the snapshot was never started, whatever empty record the store holds', function (mixed $stored): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => $stored]]);

    expect(fn (): array => eng_snap_engine()->snapshot_batch('before', 0))
        ->toThrow(RuntimeException::class, "Snapshot 'before' was not started.")
        ->and(eng_snap_order($st))->toBe(['get']);
})->with([
    'missing' => [null],
    'empty record' => [[]],
]);

it('stores each post with its normalized fields and reports the batch', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running']], 'intercepts' => true]);
    eng_snap_wpdb([eng_snap_post(7, 'doctor', 'Dr. Seven'), eng_snap_post(9, 'clinic', 'Nine')]);
    $calls = new ArrayObject;

    $result = eng_snap_batch(eng_snap_engine(), $calls, [
        7 => ['field_name' => 'Seven', 'field_bio' => ''],
        9 => ['field_name' => null, 'field_bio' => 'Nine bio'],
    ]);

    expect($result)->toBe(['processed' => 2, 'cursor' => 9, 'done' => true, 'values' => 2])
        ->and($calls->getArrayCopy())->toBe([
            ['fields_for_post', 7, 'doctor'],
            ['acf_get_value', 7, 'field_name'],
            ['acf_get_value', 7, 'field_bio'],
            ['fields_for_post', 9, 'clinic'],
            ['acf_get_value', 9, 'field_name'],
            ['acf_get_value', 9, 'field_bio'],
        ]);

    $snapshots = eng_snap_calls($st, ['put']);
    expect(array_slice($snapshots[0], 0, 5))->toBe(['put', 'snapshot', 'before', 7, ''])
        ->and($snapshots[0][5])->toBe([
            'post_id' => 7, 'post_type' => 'doctor', 'post_status' => 'publish', 'post_title' => 'Dr. Seven',
            'fields' => ['name' => ['t' => 'text', 'v' => 'Seven']],
        ])
        ->and($snapshots[0][6])->toBe('argc=5')
        ->and(array_slice($snapshots[1], 0, 5))->toBe(['put', 'snapshot', 'before', 9, ''])
        ->and($snapshots[1][5]['fields'])->toBe(['bio' => ['t' => 'textarea', 'v' => 'Nine bio']]);
});

it('stores a post with no values as an empty field list', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running']]]);
    eng_snap_wpdb([eng_snap_post(3)]);

    $result = eng_snap_batch(eng_snap_engine(), new ArrayObject, []);

    expect($result)->toBe(['processed' => 1, 'cursor' => 3, 'done' => true, 'values' => 0])
        ->and(eng_snap_calls($st, ['put'])[0][5]['fields'])->toBe([]);
});

it('numbers the post ids it stores as integers whatever the database returned', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running']]]);
    eng_snap_wpdb([eng_snap_post(12)]);

    eng_snap_batch(eng_snap_engine(), new ArrayObject, [12 => ['field_name' => 'x']]);

    $put = eng_snap_calls($st, ['put'])[0];
    expect($put[3])->toBeInt()->toBe(12)
        ->and($put[5]['post_id'])->toBeInt()->toBe(12);
});

it('asks the database for posts after the cursor, a page at a time', function (): void {
    eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running']]]);
    eng_snap_wpdb([]);

    eng_snap_batch(eng_snap_engine(['post_types' => fn (): array => ['doctor', 'clinic']]), new ArrayObject, [], 'before', '41', 10, [3, '4']);

    expect($GLOBALS['eng_snap_prepared'])->toHaveCount(1)
        ->and($GLOBALS['eng_snap_prepared'][0][1])->toBe([['doctor', 'clinic', 41]])
        ->and($GLOBALS['eng_snap_executed'][0])->toEndWith(' AND ID IN (3,4) ORDER BY ID ASC LIMIT 10');
});

it('defaults to a page of 25 posts and does not restrict the ids', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running']]]);
    eng_snap_wpdb([]);
    $engine = eng_snap_engine();

    $engine->snapshot_batch('before', 0);

    expect($GLOBALS['eng_snap_executed'][0])->toEndWith(' ORDER BY ID ASC LIMIT 25')
        ->and($GLOBALS['eng_snap_executed'][0])->not->toContain('ID IN (')
        ->and(eng_snap_calls($st, ['put'])[0][1])->toBe('snapinfo');
});

it('detaches the caching plugin only in a meta-mode snapshot while it is hooked in', function (bool $intercepts, string $mode, bool $detaches): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => $mode, 'status' => 'running']], 'intercepts' => $intercepts]);
    eng_snap_wpdb([]);

    eng_snap_engine()->snapshot_batch('before', 0);

    expect(eng_snap_calls($st, ['guard.detach']))->toHaveCount($detaches ? 1 : 0);
})->with([
    'hooked, meta' => [true, 'meta', true],
    'hooked, meta (plugin absent)' => [true, 'meta (plugin absent)', true],
    'hooked, effective' => [true, 'effective', false],
    'not hooked, meta' => [false, 'meta', false],
    'not hooked, effective' => [false, 'effective', false],
]);

it('puts the hooks back after the loop, before it writes the progress record', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'meta', 'status' => 'running']]]);
    eng_snap_wpdb([eng_snap_post(1), eng_snap_post(2)]);

    eng_snap_batch(eng_snap_engine(), new ArrayObject, []);

    expect(eng_snap_order($st))->toBe([
        'get', 'guard.new', 'guard.has_intercepts', 'guard.detach',
        'guard.reset_caches', 'put',
        'guard.reset_caches', 'put',
        'guard.attach', 'count', 'put',
    ]);
});

it('keeps the cursor and writes a running record when the page is full', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running', 'total' => 9]]]);
    eng_snap_wpdb([eng_snap_post(5), eng_snap_post(6)]);

    $result = eng_snap_batch(eng_snap_engine(), new ArrayObject, [], 'before', 0, 2);

    expect($result['done'])->toBeFalse()
        ->and($result['cursor'])->toBe(6)
        ->and(eng_snap_calls($st, ['count']))->toBe([]);

    $puts = eng_snap_calls($st, ['put']);
    $progress = $puts[count($puts) - 1];
    expect(array_slice($progress, 0, 5))->toBe(['put', 'snapinfo', 'before', 0, ''])
        ->and($progress[5])->toBe(['mode' => 'effective', 'status' => 'running', 'total' => 9, 'cursor' => 6]);
});

it('completes the snapshot when the page is short, counting its rows', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running', 'total' => 9]], 'count' => 8]);
    eng_snap_wpdb([eng_snap_post(5), eng_snap_post(6)]);
    $before = time();

    $result = eng_snap_batch(eng_snap_engine(), new ArrayObject, [], 'before', 0, 3);

    expect($result['done'])->toBeTrue()
        ->and(eng_snap_calls($st, ['count']))->toBe([['count', 'snapshot', 'before']]);

    $puts = eng_snap_calls($st, ['put']);
    $final = $puts[count($puts) - 1];
    $info = $final[5];
    expect(array_slice($final, 0, 5))->toBe(['put', 'snapinfo', 'before', 0, ''])
        ->and(array_keys($info))->toBe(['mode', 'status', 'total', 'cursor', 'finished', 'posts'])
        ->and($info['status'])->toBe('complete')
        ->and($info['cursor'])->toBe(6)
        ->and($info['posts'])->toBe(8)
        ->and($info['finished'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and(strtotime($info['finished']))->toBeGreaterThanOrEqual($before)
        ->and(strtotime($info['finished']))->toBeLessThanOrEqual(time());
});

it('completes with the cursor it was given when there are no more posts', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running']], 'count' => 4]);
    eng_snap_wpdb([]);

    $result = eng_snap_batch(eng_snap_engine(), new ArrayObject, [], 'before', 40, 25);

    expect($result)->toBe(['processed' => 0, 'cursor' => 40, 'done' => true, 'values' => 0])
        ->and(eng_snap_calls($st, ['put'])[0][5]['cursor'])->toBe(40);
});

it('reads a page that is exactly the limit as not done', function (int $limit, bool $done): void {
    eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running']]]);
    eng_snap_wpdb([eng_snap_post(1), eng_snap_post(2), eng_snap_post(3)]);

    expect(eng_snap_batch(eng_snap_engine(), new ArrayObject, [], 'before', 0, $limit)['done'])->toBe($done);
})->with([
    'limit above the page' => [4, true],
    'limit equal to the page' => [3, false],
    'limit below the page' => [2, false],
]);

it('resets the caches once for each post it reads', function (): void {
    $st = eng_snap_wire(['get' => ['snapinfo|before' => ['mode' => 'effective', 'status' => 'running']]]);
    eng_snap_wpdb([eng_snap_post(1), eng_snap_post(2), eng_snap_post(3)]);

    eng_snap_batch(eng_snap_engine(), new ArrayObject, []);

    expect(eng_snap_calls($st, ['guard.reset_caches']))->toHaveCount(3);
});

// compare()

/**
 * A stored snapshot record: the post's title and its fields as `name => value`.
 *
 * @param  array<int|string, mixed>  $fields
 * @return array<string, mixed>
 */
function eng_snap_rec(string $title, array $fields): array
{
    return [
        'post_title' => $title,
        'fields' => array_map(fn (mixed $v): array => ['t' => 'text', 'v' => $v], $fields),
    ];
}

/**
 * Wires two complete snapshots, `a` and `b`, with the given per-post records.
 *
 * @param  array<int, array<string, mixed>>  $a
 * @param  array<int, array<string, mixed>>  $b
 * @param  array<string, mixed>  $info_a
 * @param  array<string, mixed>  $info_b
 * @return ArrayObject<string, list<mixed>>
 */
function eng_snap_two(array $a, array $b, array $info_a = ['status' => 'complete'], array $info_b = ['status' => 'complete']): ArrayObject
{
    return eng_snap_wire([
        'get' => ['snapinfo|a' => $info_a, 'snapinfo|b' => $info_b],
        'by_post' => ['a' => $a, 'b' => $b],
    ]);
}

it('cleans both names and reads each snapshot in order', function (): void {
    $st = eng_snap_two([], []);

    eng_snap_engine()->compare('a', 'b');

    expect(eng_snap_order($st))->toBe(['get', 'get', 'by_post', 'by_post'])
        ->and($st['log'][0])->toBe(['get', 'snapinfo', 'a'])
        ->and($st['log'][1])->toBe(['get', 'snapinfo', 'b'])
        ->and($st['log'][2])->toBe(['by_post', 'snapshot', 'a'])
        ->and($st['log'][3])->toBe(['by_post', 'snapshot', 'b']);
});

it('rejects a snapshot name with other characters before reading anything', function (string $a, string $b, string $shown): void {
    $st = eng_snap_two([], []);

    expect(fn (): array => eng_snap_engine()->compare($a, $b))
        ->toThrow(InvalidArgumentException::class, "(max 60): '$shown'")
        ->and($st['log'])->toBe([]);
})->with([
    'the first' => ['a b', 'b', 'a b'],
    'the second' => ['a', 'b/', 'b/'],
]);

it('needs both snapshots to exist', function (array $get): void {
    $st = eng_snap_wire(['get' => $get]);

    expect(fn (): array => eng_snap_engine()->compare('a', 'b'))
        ->toThrow(RuntimeException::class, 'Both snapshots must exist on this site.')
        ->and(eng_snap_calls($st, ['by_post']))->toBe([]);
})->with([
    'first missing' => [['snapinfo|b' => ['status' => 'complete']]],
    'second missing' => [['snapinfo|a' => ['status' => 'complete']]],
    'neither' => [[]],
    'first empty' => [['snapinfo|a' => [], 'snapinfo|b' => ['status' => 'complete']]],
    'second empty' => [['snapinfo|a' => ['status' => 'complete'], 'snapinfo|b' => []]],
]);

it('needs both snapshots to be complete, naming the first that is not', function (array $a, array $b, string $named): void {
    $st = eng_snap_two([], [], $a, $b);

    expect(fn (): array => eng_snap_engine()->compare('a', 'b'))
        ->toThrow(RuntimeException::class, "Snapshot '$named' is incomplete ".eng_snap_dash().' resume or re-run it first.')
        ->and(eng_snap_calls($st, ['by_post']))->toBe([]);
})->with([
    'the first is running' => [['status' => 'running'], ['status' => 'complete'], 'a'],
    'the second is running' => [['status' => 'complete'], ['status' => 'running'], 'b'],
    'the first has no status' => [['mode' => 'meta'], ['status' => 'complete'], 'a'],
    'the second has no status' => [['status' => 'complete'], ['mode' => 'meta'], 'b'],
    'neither is complete' => [['status' => 'running'], ['status' => 'running'], 'a'],
]);

it('reports identical snapshots', function (): void {
    eng_snap_two(
        [1 => eng_snap_rec('One', ['name' => 'x']), 2 => eng_snap_rec('Two', ['name' => 'y'])],
        [1 => eng_snap_rec('One', ['name' => 'x']), 2 => eng_snap_rec('Two', ['name' => 'y'])],
    );

    expect(eng_snap_engine()->compare('a', 'b'))->toBe([
        'summary' => ['posts_a' => 2, 'posts_b' => 2, 'only_in_a' => 0, 'only_in_b' => 0, 'posts_with_diffs' => 0, 'fields_differ' => 0, 'identical' => true],
        'diffs' => [],
    ]);
});

it('reports a post found in only one snapshot', function (): void {
    eng_snap_two(
        [1 => eng_snap_rec('Only A', ['name' => 'x']), 3 => eng_snap_rec('Both', ['name' => 'z'])],
        [2 => eng_snap_rec('Only B', ['name' => 'y']), 3 => eng_snap_rec('Both', ['name' => 'z'])],
    );

    expect(eng_snap_engine()->compare('a', 'b'))->toBe([
        'summary' => ['posts_a' => 2, 'posts_b' => 2, 'only_in_a' => 1, 'only_in_b' => 1, 'posts_with_diffs' => 0, 'fields_differ' => 0, 'identical' => false],
        'diffs' => [
            ['post_id' => 1, 'title' => 'Only A', 'field' => '(whole post)', 'a' => 'present', 'b' => null],
            ['post_id' => 2, 'title' => 'Only B', 'field' => '(whole post)', 'a' => null, 'b' => 'present'],
        ],
    ]);
});

it('reports each field that differs, with the values from both sides', function (): void {
    eng_snap_two(
        [5 => eng_snap_rec('Five', ['name' => 'same', 'bio' => 'old', 'gone' => 'g'])],
        [5 => eng_snap_rec('Five (renamed)', ['name' => 'same', 'bio' => 'new', 'added' => 'n'])],
    );

    $result = eng_snap_engine()->compare('a', 'b');

    expect($result['summary'])->toBe(['posts_a' => 1, 'posts_b' => 1, 'only_in_a' => 0, 'only_in_b' => 0, 'posts_with_diffs' => 1, 'fields_differ' => 3, 'identical' => false])
        ->and($result['diffs'])->toBe([
            ['post_id' => 5, 'title' => 'Five', 'field' => 'bio', 'a' => 'old', 'b' => 'new'],
            ['post_id' => 5, 'title' => 'Five', 'field' => 'gone', 'a' => 'g', 'b' => null],
            ['post_id' => 5, 'title' => 'Five', 'field' => 'added', 'a' => null, 'b' => 'n'],
        ]);
});

it('counts a post once however many of its fields differ', function (): void {
    eng_snap_two(
        [1 => eng_snap_rec('One', ['x' => '1', 'y' => '1']), 2 => eng_snap_rec('Two', ['x' => '1']), 3 => eng_snap_rec('Three', ['x' => '1'])],
        [1 => eng_snap_rec('One', ['x' => '2', 'y' => '2']), 2 => eng_snap_rec('Two', ['x' => '1']), 3 => eng_snap_rec('Three', ['x' => '2'])],
    );

    $summary = eng_snap_engine()->compare('a', 'b')['summary'];

    expect($summary['posts_with_diffs'])->toBe(2)
        ->and($summary['fields_differ'])->toBe(3);
});

it('skips the fields it is told to ignore, by exact name', function (): void {
    eng_snap_two(
        [5 => eng_snap_rec('Five', ['skipme' => 'a', 'keep' => 'a'])],
        [5 => eng_snap_rec('Five', ['skipme' => 'b', 'keep' => 'b'])],
    );

    $result = eng_snap_engine()->compare('a', 'b', ['skipme']);

    expect(array_column($result['diffs'], 'field'))->toBe(['keep'])
        ->and($result['summary']['fields_differ'])->toBe(1);
});

it('does not count a post whose only differences are ignored fields', function (): void {
    eng_snap_two(
        [5 => eng_snap_rec('Five', ['skipme' => 'a'])],
        [5 => eng_snap_rec('Five', ['skipme' => 'b'])],
    );

    $result = eng_snap_engine()->compare('a', 'b', ['skipme']);

    expect($result['summary']['posts_with_diffs'])->toBe(0)
        ->and($result['summary']['identical'])->toBeTrue()
        ->and($result['diffs'])->toBe([]);
});

it('matches ignored names strictly, so a field named like a number is not ignored by its string', function (): void {
    eng_snap_two(
        [5 => eng_snap_rec('Five', [7 => 'a'])],
        [5 => eng_snap_rec('Five', [7 => 'b'])],
    );

    expect(eng_snap_engine()->compare('a', 'b', ['7'])['summary']['fields_differ'])->toBe(1)
        ->and(eng_snap_engine()->compare('a', 'b', [7])['summary']['fields_differ'])->toBe(0);
});

it('treats values that normalize to the same text as equal, and a missing field as null', function (): void {
    eng_snap_two(
        [5 => ['post_title' => 'Five', 'fields' => ['list' => ['t' => 'select', 'v' => ['a', 'b']], 'n' => ['t' => 'text', 'v' => '0']]]],
        [5 => ['post_title' => 'Five', 'fields' => ['list' => ['t' => 'select', 'v' => ['a', 'b']], 'n' => ['t' => 'text', 'v' => 0]]]],
    );

    $result = eng_snap_engine()->compare('a', 'b');

    expect(array_column($result['diffs'], 'field'))->toBe(['n']);
});
