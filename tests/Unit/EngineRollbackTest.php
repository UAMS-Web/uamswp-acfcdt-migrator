<?php

/**
 * Engine rollback: `rollback_start()` and `rollback_batch()`, with WordPress, `Store` and `Site` mocked.
 *
 * `Store` and `Site` are redefined with Patchwork into one ordered call log, and `$wpdb` is a recording stand-in
 * that keeps the rows `insert()` is handed. Whether MySQL accepts the writes is for the Integration suite.
 *
 * Helper names carry the `eng_rb` prefix so the sibling Engine test files can define their own without a clash.
 *
 * @see includes/class-engine.php
 */

declare(strict_types=1);

use Mockery\MockInterface;
use UAMSWP_ACFCDT_Migrator\Engine;
use UAMSWP_ACFCDT_Migrator\Site;
use UAMSWP_ACFCDT_Migrator\Store;

use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;

beforeEach(function (): void {
    when('get_current_blog_id')->justReturn(5);
    when('home_url')->alias(fn (string $path = ''): string => 'https://example.test'.$path);
    redefine(Store::class.'::install', function (): void {});
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

/**
 * Redefines `Store` and `Site` and returns the shared record.
 *
 * Options: `get` (map `kind|ref` to what `Store::get()` returns), `count` (what `Store::count()` returns), `rows`
 * (what `Store::rows()` returns).
 *
 * @param  array<string, mixed>  $o
 */
function eng_rb_wire(array $o = []): ArrayObject
{
    $st = new ArrayObject(['log' => []]);
    $note = function (string $name, array $args = []) use ($st): void {
        $log = $st['log'];
        $log[] = [$name, ...$args];
        $st['log'] = $log;
    };

    redefine(Store::class.'::get', function (...$a) use ($note, $o): mixed {
        $note('get', $a);

        return $o['get'][$a[0].'|'.$a[1]] ?? null;
    });
    redefine(Store::class.'::put', function (...$a) use ($note): void {
        $note('put', [...$a, 'argc='.count($a)]);
    });
    redefine(Store::class.'::count', function (...$a) use ($note, $o): int {
        $note('count', $a);

        return $o['count'] ?? 0;
    });
    redefine(Store::class.'::rows', function (...$a) use ($note, $o): array {
        $note('rows', [...$a, 'argc='.count($a)]);

        return $o['rows'] ?? [];
    });
    redefine(Site::class.'::delete_meta_rows_for_field', function (...$a) use ($note): void {
        $note('delete_meta_rows_for_field', $a);
    });

    when('clean_post_cache')->alias(function (...$a) use ($note): void {
        $note('clean_post_cache', $a);
    });
    when('wp_cache_delete')->alias(function (...$a) use ($note): bool {
        $note('wp_cache_delete', $a);

        return true;
    });

    return $st;
}

/** A `$wpdb` stand-in that records every `insert()` it is handed, in the shared log. */
function eng_rb_wpdb(ArrayObject $st): void
{
    /** @var wpdb&MockInterface $wpdb */
    $wpdb = Mockery::mock('wpdb');
    $wpdb->postmeta = 'wpx_postmeta';
    $wpdb->shouldReceive('insert')->andReturnUsing(function (...$a) use ($st): int {
        $log = $st['log'];
        $log[] = ['insert', ...$a, 'argc='.count($a)];
        $st['log'] = $log;

        return 1;
    });
    $GLOBALS['wpdb'] = $wpdb;
}

/** A backup row as `Store::rows()` returns it: a numeric id and a JSON `data` column. */
function eng_rb_row(int $id, array $entry): object
{
    return (object) ['id' => (string) $id, 'data' => json_encode($entry)];
}

/** A backup entry for one field of one post. */
function eng_rb_entry(int $post_id, string $field, array $rows, ?bool $complex = false): array
{
    $entry = ['post_id' => (string) $post_id, 'field' => $field, 'rows' => $rows];
    if ($complex !== null) {
        $entry['complex'] = $complex;
    }

    return $entry;
}

/** The logged calls with the given names, one row per call. */
function eng_rb_calls(ArrayObject $st, string ...$names): array
{
    return array_values(array_filter($st['log'], fn (array $row): bool => in_array($row[0], $names, true)));
}

/** The names of every logged call, in order. */
function eng_rb_order(ArrayObject $st): array
{
    return array_map(fn (array $row): string => $row[0], $st['log']);
}

/** The dash the messages hold, written as an escape so no literal U+2014 sits in this file. */
function eng_rb_dash(): string
{
    return "\u{2014}";
}

/**
 * Runs one `rollback_batch()`.
 *
 * @param  list<object>  $rows
 * @param  array<string, mixed>  $o  `eng_rb_wire()` options
 * @return array{0: array<string, mixed>, 1: ArrayObject}
 */
function eng_rb_batch(array $rows, array $o = [], mixed $cursor = 0, int $limit = 100): array
{
    $st = eng_rb_wire(['rows' => $rows] + $o);
    eng_rb_wpdb($st);

    return [(new Engine)->rollback_batch('r1', $cursor, $limit), $st];
}

// rollback_start()

it('fails when there is no such run, whatever empty record the store holds', function (mixed $stored): void {
    $st = eng_rb_wire(['get' => ['run|r1' => $stored]]);

    expect(fn (): string => (new Engine)->rollback_start('r1'))
        ->toThrow(RuntimeException::class, "No backfill run 'r1' on this site.")
        ->and(eng_rb_order($st))->toBe(['get']);
})->with([
    'missing' => [null],
    'empty record' => [[]],
]);

it('cleans the name it is given', function (): void {
    eng_rb_wire(['get' => ['run|r1' => ['dry' => false]]]);

    expect((new Engine)->rollback_start('r1'))->toBe('r1')
        ->and(fn (): string => (new Engine)->rollback_start('a b'))
        ->toThrow(InvalidArgumentException::class, "Names may only contain letters, numbers, dot, dash and underscore (max 60): 'a b'");
});

it('refuses to roll back a dry run, whatever flag value marks it', function (mixed $dry): void {
    $st = eng_rb_wire(['get' => ['run|r1' => ['dry' => $dry]]]);

    expect(fn (): string => (new Engine)->rollback_start('r1'))
        ->toThrow(RuntimeException::class, "'r1' was a dry run ".eng_rb_dash().' nothing to roll back.')
        ->and(eng_rb_calls($st, 'put'))->toBe([]);
})->with([
    'true' => [true],
    'one' => [1],
    'text' => ['yes'],
]);

it('treats a false or missing dry flag as a live run', function (array $run): void {
    eng_rb_wire(['get' => ['run|r1' => $run]]);

    expect((new Engine)->rollback_start('r1'))->toBe('r1');
})->with([
    'false' => [['dry' => false]],
    'zero' => [['dry' => 0]],
    'absent' => [['status' => 'complete']],
]);

it('refuses a run already rolled back unless forced', function (mixed $force, bool $throws): void {
    $st = eng_rb_wire([
        'get' => ['run|r1' => ['dry' => false], 'rollback|r1' => ['status' => 'complete']],
        'count' => 3,
    ]);

    if ($throws) {
        expect(fn (): string => (new Engine)->rollback_start('r1', $force))
            ->toThrow(RuntimeException::class, "Run 'r1' was already rolled back.")
            ->and(eng_rb_calls($st, 'put'))->toBe([]);

        return;
    }

    expect((new Engine)->rollback_start('r1', $force))->toBe('r1')
        ->and(eng_rb_calls($st, 'put'))->toHaveCount(1);
})->with([
    'not forced' => [false, true],
    'forced' => [true, false],
    'forced with a truthy value' => [1, false],
]);

it('allows a rollback that never finished, and one with no status', function (array $rb): void {
    $st = eng_rb_wire(['get' => ['run|r1' => ['dry' => false], 'rollback|r1' => $rb]]);

    expect((new Engine)->rollback_start('r1'))->toBe('r1')
        ->and(eng_rb_calls($st, 'put'))->toHaveCount(1);
})->with([
    'running' => [['status' => 'running']],
    'no status' => [['cursor' => 4]],
    'an empty record' => [[]],
]);

it('stores a running record with the number of backups to restore', function (): void {
    $st = eng_rb_wire(['get' => ['run|r1' => ['dry' => false]], 'count' => 7]);
    $before = time();

    (new Engine)->rollback_start('r1');

    expect(eng_rb_calls($st, 'count'))->toBe([['count', 'backup', 'r1']]);
    $put = eng_rb_calls($st, 'put');
    expect($put)->toHaveCount(1)
        ->and(array_slice($put[0], 0, 5))->toBe(['put', 'rollback', 'r1', 0, ''])
        ->and($put[0][6])->toBe('argc=5');

    $data = $put[0][5];
    expect(array_keys($data))->toBe(['status', 'started', 'total'])
        ->and($data['status'])->toBe('running')
        ->and($data['total'])->toBe(7)
        ->and($data['started'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and(strtotime($data['started']))->toBeGreaterThanOrEqual($before)
        ->and(strtotime($data['started']))->toBeLessThanOrEqual(time());
});

it('reads the run, then the rollback, before counting and storing', function (): void {
    $st = eng_rb_wire(['get' => ['run|r1' => ['dry' => false]]]);

    (new Engine)->rollback_start('r1');

    expect(eng_rb_order($st))->toBe(['get', 'get', 'count', 'put'])
        ->and(eng_rb_calls($st, 'get'))->toBe([['get', 'run', 'r1'], ['get', 'rollback', 'r1']]);
});

// rollback_batch(): restoring

it('asks for backups newest first after the cursor', function (): void {
    [, $st] = eng_rb_batch([], [], '40', 25);

    $rows = eng_rb_calls($st, 'rows');
    expect($rows)->toBe([['rows', 'backup', 'r1', 40, 25, true, 'argc=5']]);
    expect($rows[0][3])->toBeInt();
});

it('defaults to a page of one hundred', function (): void {
    $st = eng_rb_wire();
    eng_rb_wpdb($st);

    (new Engine)->rollback_batch('r1', 0);

    expect(eng_rb_calls($st, 'rows')[0][4])->toBe(100);
});

it('deletes the field rows, then puts each backed up row back', function (): void {
    $entry = eng_rb_entry(7, 'name', [['name', 'Ann'], ['_name', 'field_x']]);

    [, $st] = eng_rb_batch([eng_rb_row(9, $entry)]);

    $names = eng_rb_order($st);
    $from = array_search('delete_meta_rows_for_field', $names, true);
    expect(array_slice($names, $from, 3))->toBe(['delete_meta_rows_for_field', 'insert', 'insert'])
        ->and(eng_rb_calls($st, 'delete_meta_rows_for_field'))->toBe([['delete_meta_rows_for_field', 7, 'name', false]])
        ->and(eng_rb_calls($st, 'insert'))->toBe([
            ['insert', 'wpx_postmeta', ['post_id' => 7, 'meta_key' => 'name', 'meta_value' => 'Ann'], ['%d', '%s', '%s'], 'argc=3'],
            ['insert', 'wpx_postmeta', ['post_id' => 7, 'meta_key' => '_name', 'meta_value' => 'field_x'], ['%d', '%s', '%s'], 'argc=3'],
        ]);
});

it('hands the post id on as an integer', function (): void {
    [, $st] = eng_rb_batch([eng_rb_row(9, eng_rb_entry(7, 'name', [['name', 'Ann']]))]);

    expect(eng_rb_calls($st, 'delete_meta_rows_for_field')[0][1])->toBeInt()->toBe(7)
        ->and(eng_rb_calls($st, 'insert')[0][2]['post_id'])->toBeInt()->toBe(7);
});

it('passes a complex field on as complex, and a missing flag as not complex', function (?bool $complex, bool $expected): void {
    [, $st] = eng_rb_batch([eng_rb_row(9, eng_rb_entry(7, 'rows', [], $complex))]);

    expect(eng_rb_calls($st, 'delete_meta_rows_for_field'))->toBe([['delete_meta_rows_for_field', 7, 'rows', $expected]]);
})->with([
    'complex' => [true, true],
    'simple' => [false, false],
    'flag missing' => [null, false],
]);

it('only deletes when a backup holds no rows', function (): void {
    [$result, $st] = eng_rb_batch([eng_rb_row(9, eng_rb_entry(7, 'name', []))]);

    expect($result['processed'])->toBe(1)
        ->and(eng_rb_calls($st, 'delete_meta_rows_for_field'))->toHaveCount(1)
        ->and(eng_rb_calls($st, 'insert'))->toBe([]);
});

it('works through the backups in the order the store gave them', function (): void {
    [, $st] = eng_rb_batch([
        eng_rb_row(9, eng_rb_entry(7, 'location_phone_numbers', [['location_phone_numbers', '2']])),
        eng_rb_row(8, eng_rb_entry(7, 'location_phone', [['location_phone', '1']])),
    ]);

    expect(array_column(eng_rb_calls($st, 'delete_meta_rows_for_field'), 2))->toBe(['location_phone_numbers', 'location_phone'])
        ->and(array_column(array_column(eng_rb_calls($st, 'insert'), 2), 'meta_value'))->toBe(['2', '1']);
});

// rollback_batch(): the post cache

it('clears each post cache once, after every row is restored', function (): void {
    [, $st] = eng_rb_batch([
        eng_rb_row(9, eng_rb_entry(7, 'a', [['a', '1']])),
        eng_rb_row(8, eng_rb_entry(7, 'b', [['b', '1']])),
        eng_rb_row(7, eng_rb_entry(4, 'a', [['a', '1']])),
    ]);

    expect(eng_rb_calls($st, 'clean_post_cache'))->toBe([['clean_post_cache', 7], ['clean_post_cache', 4]])
        ->and(eng_rb_calls($st, 'wp_cache_delete'))->toBe([['wp_cache_delete', 7, 'post_meta'], ['wp_cache_delete', 4, 'post_meta']]);

    $names = eng_rb_order($st);
    $first = array_search('clean_post_cache', $names, true);
    expect(array_slice($names, $first))->toBe(['clean_post_cache', 'wp_cache_delete', 'clean_post_cache', 'wp_cache_delete', 'get', 'put'])
        ->and(array_slice($names, 0, $first))->not->toContain('clean_post_cache');
});

it('clears no cache when there is nothing to restore', function (): void {
    [, $st] = eng_rb_batch([]);

    expect(eng_rb_calls($st, 'clean_post_cache', 'wp_cache_delete'))->toBe([]);
});

// rollback_batch(): the cursor and the rollback record

it('moves the cursor to the last backup id and reports the page', function (): void {
    [$result, $st] = eng_rb_batch([
        eng_rb_row(9, eng_rb_entry(7, 'a', [])),
        eng_rb_row(5, eng_rb_entry(7, 'b', [])),
    ], ['get' => ['rollback|r1' => ['status' => 'running', 'total' => 2]]], 100, 2);

    expect($result)->toBe(['processed' => 2, 'cursor' => 5, 'done' => false]);

    $put = eng_rb_calls($st, 'put');
    expect($put)->toHaveCount(1)
        ->and($put[0])->toBe(['put', 'rollback', 'r1', 0, '', ['status' => 'running', 'total' => 2, 'cursor' => 5], 'argc=5']);
});

it('completes the rollback when the page is short', function (): void {
    [$result, $st] = eng_rb_batch([eng_rb_row(9, eng_rb_entry(7, 'a', []))], ['get' => ['rollback|r1' => ['status' => 'running', 'total' => 1]]], 100, 2);
    $before = time();

    expect($result)->toBe(['processed' => 1, 'cursor' => 9, 'done' => true]);

    $data = eng_rb_calls($st, 'put')[0][5];
    expect(array_keys($data))->toBe(['status', 'total', 'cursor', 'finished'])
        ->and($data['status'])->toBe('complete')
        ->and($data['cursor'])->toBe(9)
        ->and($data['finished'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and(strtotime($data['finished']))->toBeLessThanOrEqual($before + 1);
});

it('keeps the cursor it was given when there is nothing more', function (): void {
    [$result, $st] = eng_rb_batch([], [], '40', 10);

    expect($result)->toBe(['processed' => 0, 'cursor' => '40', 'done' => true])
        ->and(eng_rb_calls($st, 'put')[0][5]['cursor'])->toBe('40');
});

it('starts a record when the store holds none for the rollback', function (): void {
    [, $st] = eng_rb_batch([eng_rb_row(9, eng_rb_entry(7, 'a', []))], [], 100, 5);

    $data = eng_rb_calls($st, 'put')[0][5];
    expect(array_keys($data))->toBe(['cursor', 'status', 'finished']);
});

it('reads a page exactly the size of the limit as not done', function (int $limit, bool $done): void {
    [$result] = eng_rb_batch([
        eng_rb_row(3, eng_rb_entry(1, 'a', [])),
        eng_rb_row(2, eng_rb_entry(1, 'b', [])),
        eng_rb_row(1, eng_rb_entry(1, 'c', [])),
    ], [], 100, $limit);

    expect($result['done'])->toBe($done);
})->with([
    'limit above the page' => [4, true],
    'limit equal to the page' => [3, false],
    'limit below the page' => [2, false],
]);

it('restores nothing for a backup whose rows are null', function (): void {
    $entry = ['post_id' => '7', 'field' => 'name', 'rows' => null];

    [$result, $st] = eng_rb_batch([eng_rb_row(9, $entry)]);

    expect($result['processed'])->toBe(1)
        ->and(eng_rb_calls($st, 'delete_meta_rows_for_field'))->toHaveCount(1)
        ->and(eng_rb_calls($st, 'insert'))->toBe([]);
});
