<?php

/**
 * Engine backfill: `backfill_start()` and `backfill_batch()`, with WordPress, ACF and `$wpdb` mocked.
 *
 * The collaborators are redefined with Patchwork into one ordered call log, so a test states what each answers and
 * reads the order the engine used them in. `acf_get_value()` answers from the effective map while the stand-in guard
 * is attached and from the postmeta map while it is detached, as the engine's two reads rely on, and
 * `acf_update_value()` writes into the postmeta map so a write reads back unless a test says it does not. `$wpdb`
 * answers the post query and records what it was handed; whether MySQL accepts the SQL is for the Integration suite.
 * `Normalizer` is real. The write step's own regression tests (the rows ACF rewrites on other posts) are in
 * EngineBackfillWriteTest.php; this file covers the rest of `backfill_start()` and `backfill_batch()`.
 *
 * Helper names carry the `eng_bf` prefix so the sibling Engine test files can define their own without a clash.
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

beforeEach(function (): void {
    when('get_current_blog_id')->justReturn(5);
    when('home_url')->alias(fn (string $path = ''): string => 'https://example.test'.$path);
    when('absint')->alias(fn (mixed $n): int => abs((int) $n));
    when('wp_json_encode')->alias(fn (mixed $v, int $flags = 0): string|false => json_encode($v, $flags));
    when('wp_slash')->alias(fn (mixed $v): mixed => $v);
    when('add_filter')->justReturn(true);
    when('remove_filter')->justReturn(true);
    redefine(Store::class.'::install', function (): void {});
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

/** The stand-in field map the batch tests use: one text field. */
function eng_bf_field(string $name = 'name', string $type = 'text'): array
{
    return ['name' => $name, 'key' => 'k_'.$name, 'type' => $type];
}

/**
 * Redefines everything the engine reaches and returns the shared record.
 *
 * Options: `get` (map `kind|ref` to what `Store::get()` returns), `summary` (what `Store::change_summary()` returns),
 * `intercepts` (true by default), `problems` (what `cache_problems()` returns), `types` and `total` (post types and
 * how many posts `total_posts()` sees), `fields` (post id to field map, or one map for every post), `eff` and `meta`
 * (post id to field key to the value read while attached or detached), `write_ignored` (a write that does not take).
 *
 * @param  array<string, mixed>  $o
 */
function eng_bf_wire(array $o = []): ArrayObject
{
    $st = new ArrayObject(['log' => [], 'meta' => $o['meta'] ?? [], 'mode' => 'on']);
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
    redefine(Store::class.'::change_summary', function (...$a) use ($note, $o): array {
        $note('change_summary', $a);

        return $o['summary'] ?? ['stats' => [], 'by_field' => []];
    });
    redefine(HookGuard::class.'::__construct', function () use ($note): void {
        $note('guard.new');
    });
    redefine(HookGuard::class.'::has_intercepts', function () use ($note, $o): bool {
        $note('guard.has_intercepts');

        return $o['intercepts'] ?? true;
    });
    redefine(HookGuard::class.'::attach', function () use ($note, $st): void {
        $st['mode'] = 'on';
        $note('guard.attach');
    });
    redefine(HookGuard::class.'::detach', function () use ($note, $st): void {
        $st['mode'] = 'off';
        $note('guard.detach');
    });
    redefine(HookGuard::class.'::reset_caches', function () use ($note): void {
        $note('guard.reset_caches');
    });
    redefine(HookGuard::class.'::flush_acf_values', function () use ($note): void {
        $note('guard.flush');
    });
    redefine(Site::class.'::post_types', fn (): array => $o['types'] ?? ['doctor']);
    redefine(Site::class.'::posts', fn (): array => array_fill(0, $o['total'] ?? 0, 1));
    redefine(Site::class.'::cache_problems', fn (): array => $o['problems'] ?? []);
    redefine(Site::class.'::fields_for_post', function (mixed $id, mixed $type) use ($note, $o): array {
        $note('fields_for_post', [$id, $type]);

        return $o['fields'][$id] ?? ($o['fields'] ?? ['name' => eng_bf_field()]);
    });
    redefine(Site::class.'::meta_rows_for_field', function (...$a) use ($note): array {
        $note('meta_rows_for_field', $a);

        return [['row_for', $a[1]]];
    });

    when('wp_cache_delete')->alias(function (...$a) use ($note): bool {
        $note('wp_cache_delete', $a);

        return true;
    });
    when('clean_post_cache')->alias(function (...$a) use ($note): void {
        $note('clean_post_cache', $a);
    });
    when('acf_get_value')->alias(function (mixed $id, array $field) use ($note, $st, $o): mixed {
        $mode = $st['mode'];
        $note('acf_get_value.'.$mode, [$id, $field['key']]);

        return $mode === 'on' ? ($o['eff'][$id][$field['key']] ?? null) : ($st['meta'][$id][$field['key']] ?? null);
    });
    when('acf_update_value')->alias(function (mixed $value, mixed $id, array $field) use ($note, $st, $o): bool {
        $note('acf_update_value', [$value, $id, $field['key']]);
        if (empty($o['write_ignored'])) {
            $meta = $st['meta'];
            $meta[$id][$field['key']] = $value;
            $st['meta'] = $meta;
        }

        return true;
    });

    return $st;
}

/**
 * A `$wpdb` that answers the post query with the given rows and records what it was handed.
 *
 * @param  list<object>  $rows
 */
function eng_bf_wpdb(array $rows): MockInterface
{
    $GLOBALS['eng_bf_executed'] = [];
    $wpdb = Mockery::mock('wpdb');
    $wpdb->posts = 'wpx_posts';
    $wpdb->shouldReceive('prepare')->andReturnUsing(fn (string $query, ...$args): string => 'P:'.$query);
    $wpdb->shouldReceive('get_results')->andReturnUsing(function (string $sql) use ($rows): array {
        $GLOBALS['eng_bf_executed'][] = $sql;
        if (count($GLOBALS['eng_bf_executed']) > 5) {
            throw new LogicException('Runaway loop: more than 5 statements executed.');
        }

        return $rows;
    });
    $GLOBALS['wpdb'] = $wpdb;

    return $wpdb;
}

/** A post row as `$wpdb->get_results()` returns it: every column a string. */
function eng_bf_post(int $id, string $type = 'doctor', string $title = 'Title', string $modified = '2026-01-01 00:00:00'): object
{
    return (object) ['ID' => (string) $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title, 'post_modified' => $modified];
}

/** The logged calls with the given names, one row per call. */
function eng_bf_calls(ArrayObject $st, string ...$names): array
{
    return array_values(array_filter($st['log'], fn (array $row): bool => in_array($row[0], $names, true)));
}

/** The names of every logged call, in order. */
function eng_bf_order(ArrayObject $st): array
{
    return array_map(fn (array $row): string => $row[0], $st['log']);
}

/** The data of every `Store::put()` of one kind, as `[ref, post_id, field, data, mode?]` rows. */
function eng_bf_puts(ArrayObject $st, string $kind): array
{
    $out = [];
    foreach (eng_bf_calls($st, 'put') as $row) {
        if ($row[1] === $kind) {
            $out[] = array_slice($row, 2, -1);
        }
    }

    return $out;
}

/** The em dash the guard messages hold, written as an escape so no literal U+2014 sits in this file. */
function eng_bf_dash(): string
{
    return "\u{2014}";
}

/** A run record for `Store::get('run', ...)`. */
function eng_bf_run(array $run = []): array
{
    return ['get' => ['run|r1' => $run + ['dry' => false, 'no_clear' => false]]];
}

/**
 * Runs one `backfill_batch()` over the given posts.
 *
 * @param  list<object>  $posts
 * @param  array<string, mixed>  $o  `eng_bf_wire()` options
 * @return array{0: array<string, mixed>, 1: ArrayObject}
 */
function eng_bf_batch(array $posts, array $o = [], mixed $cursor = 0, int $limit = 10, mixed $ids = null, array $run = []): array
{
    $st = eng_bf_wire($o + eng_bf_run($run));
    eng_bf_wpdb($posts);

    return [(new Engine)->backfill_batch('r1', $cursor, $limit, $ids), $st];
}

// backfill_start()

it('needs the caching plugin hooked in, and stores nothing without it', function (): void {
    $st = eng_bf_wire(['intercepts' => false]);

    expect(fn (): string => (new Engine)->backfill_start(false, false))
        ->toThrow(RuntimeException::class, 'ACF Custom Database Tables intercepts not found '.eng_bf_dash().' the plugin must be active to backfill from it.')
        ->and(eng_bf_order($st))->toBe(['guard.new', 'guard.has_intercepts']);
});

it('refuses a live run while the site has cache problems, unless overridden or dry', function (array $problems, mixed $allow, mixed $dry, bool $throws): void {
    $st = eng_bf_wire(['problems' => $problems]);
    $engine = new Engine;

    if ($throws) {
        expect(fn (): string => $engine->backfill_start($dry, false, 'r1', $allow))
            ->toThrow(RuntimeException::class, implode(' ', $problems).' (Override only if you have decided postmeta is the current copy.)')
            ->and(eng_bf_calls($st, 'get'))->toBe([]);

        return;
    }

    expect($engine->backfill_start($dry, false, 'r1', $allow))->toBe('r1');
})->with([
    'problems, no override, live' => [['Object cache is external.', 'Run it again.'], false, false, true],
    'problems, no override, falsy dry' => [['Object cache is external.'], false, 0, true],
    'problems, override' => [['Object cache is external.'], true, false, false],
    'problems, dry' => [['Object cache is external.'], false, true, false],
    'problems, truthy override and dry' => [['Object cache is external.'], 1, 'yes', false],
    'no problems, live' => [[], false, false, false],
    'no problems, dry' => [[], false, true, false],
]);

it('uses a name it is given, cleaned, and rejects one with other characters', function (): void {
    eng_bf_wire();

    expect((new Engine)->backfill_start(false, false, 12))->toBe('12')
        ->and(fn (): string => (new Engine)->backfill_start(false, false, 'a b'))
        ->toThrow(InvalidArgumentException::class, "Names may only contain letters, numbers, dot, dash and underscore (max 60): 'a b'");
});

it('makes a UTC name from the clock when none is given, marking a dry run', function (mixed $stamp, bool $dry, string $pattern): void {
    eng_bf_wire();

    expect((new Engine)->backfill_start($dry, false, $stamp))->toMatch($pattern);
})->with([
    'null, live' => [null, false, '/^\d{8}-\d{6}$/'],
    'null, dry' => [null, true, '/^\d{8}-\d{6}-dry$/'],
    'empty, live' => ['', false, '/^\d{8}-\d{6}$/'],
    'the string zero is empty too' => ['0', false, '/^\d{8}-\d{6}$/'],
]);

it('stamps a generated name with the current UTC time', function (): void {
    eng_bf_wire();
    $before = (int) gmdate('U');

    $stamp = (new Engine)->backfill_start(false, false);

    $parsed = DateTimeImmutable::createFromFormat('Ymd-His', $stamp, new DateTimeZone('UTC'));
    expect($parsed)->not->toBeFalse()
        ->and($parsed->getTimestamp())->toBeGreaterThanOrEqual($before - 1)
        ->and($parsed->getTimestamp())->toBeLessThanOrEqual((int) gmdate('U') + 1);
});

it('refuses a name already in use, whatever the stored record holds', function (mixed $stored): void {
    $st = eng_bf_wire(['get' => ['run|r1' => $stored]]);

    expect(fn (): string => (new Engine)->backfill_start(false, false, 'r1'))
        ->toThrow(RuntimeException::class, "A backfill run 'r1' already exists on this site.")
        ->and(eng_bf_calls($st, 'get'))->toBe([['get', 'run', 'r1']])
        ->and(eng_bf_calls($st, 'put'))->toBe([]);
})->with([
    'a record' => [['dry' => false]],
    'an empty record' => [[]],
    'zero' => [0],
]);

it('stores a running record with the flags and the site details', function (): void {
    $st = eng_bf_wire(['total' => 3]);
    $before = time();

    $stamp = (new Engine)->backfill_start('1', 0, 'r1');

    expect($stamp)->toBe('r1');
    $put = eng_bf_calls($st, 'put');
    expect($put)->toHaveCount(1)
        ->and(array_slice($put[0], 0, 5))->toBe(['put', 'run', 'r1', 0, ''])
        ->and($put[0][6])->toBe('argc=5');

    $data = $put[0][5];
    expect(array_keys($data))->toBe(['dry', 'no_clear', 'total', 'status', 'started', 'url'])
        ->and($data['dry'])->toBeTrue()
        ->and($data['no_clear'])->toBeFalse()
        ->and($data['total'])->toBe(3)
        ->and($data['status'])->toBe('running')
        ->and($data['url'])->toBe('https://example.test/')
        ->and($data['started'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and(strtotime($data['started']))->toBeGreaterThanOrEqual($before)
        ->and(strtotime($data['started']))->toBeLessThanOrEqual(time());
});

it('casts the flags it stores to booleans', function (mixed $dry, mixed $no_clear, bool $dry_stored, bool $no_clear_stored): void {
    $st = eng_bf_wire();

    (new Engine)->backfill_start($dry, $no_clear, 'r1', true);

    $data = eng_bf_calls($st, 'put')[0][5];
    expect($data['dry'])->toBe($dry_stored)
        ->and($data['no_clear'])->toBe($no_clear_stored);
})->with([
    'both set' => ['x', 1, true, true],
    'neither' => [0, '', false, false],
    'only no_clear' => [false, true, false, true],
]);

// backfill_batch(): the run and the guard

it('fails when the run was never started, whatever empty record the store holds', function (mixed $stored): void {
    $st = eng_bf_wire(['get' => ['run|r1' => $stored]]);

    expect(fn (): array => (new Engine)->backfill_batch('r1', 0))
        ->toThrow(RuntimeException::class, "Backfill run 'r1' was not started.")
        ->and(eng_bf_order($st))->toBe(['get']);
})->with([
    'missing' => [null],
    'empty record' => [[]],
]);

it('fails when the caching plugin was deactivated mid-run, before reading any post', function (): void {
    $st = eng_bf_wire(['get' => ['run|r1' => ['dry' => false]], 'intercepts' => false]);
    eng_bf_wpdb([eng_bf_post(1)]);

    expect(fn (): array => (new Engine)->backfill_batch('r1', 0))
        ->toThrow(RuntimeException::class, 'ACF Custom Database Tables intercepts not found '.eng_bf_dash().' was the plugin deactivated mid-run?')
        ->and(eng_bf_order($st))->toBe(['get', 'guard.new', 'guard.has_intercepts'])
        ->and($GLOBALS['eng_bf_executed'])->toBe([]);
});

it('asks the database for posts after the cursor, a page at a time', function (): void {
    eng_bf_batch([], [], '41', 7, [3, '4']);

    expect($GLOBALS['eng_bf_executed'])->toHaveCount(1)
        ->and($GLOBALS['eng_bf_executed'][0])->toEndWith(' AND ID IN (3,4) ORDER BY ID ASC LIMIT 7');
});

it('defaults to a page of ten posts and does not restrict the ids', function (): void {
    eng_bf_wire(eng_bf_run());
    eng_bf_wpdb([]);

    (new Engine)->backfill_batch('r1', 0);

    expect($GLOBALS['eng_bf_executed'][0])->toEndWith(' ORDER BY ID ASC LIMIT 10')
        ->and($GLOBALS['eng_bf_executed'][0])->not->toContain('ID IN (');
});

// backfill_batch(): reading both sides

it('reads each post with the plugin on, then with it off, clearing the meta cache before the second read', function (): void {
    [, $st] = eng_bf_batch([eng_bf_post(7, 'doctor')], [
        'eff' => [7 => ['k_name' => 'same']],
        'meta' => [7 => ['k_name' => 'same']],
    ]);

    expect(eng_bf_order($st))->toBe([
        'get', 'guard.new', 'guard.has_intercepts',
        'fields_for_post',
        'guard.attach', 'guard.reset_caches', 'acf_get_value.on',
        'guard.detach', 'wp_cache_delete', 'acf_get_value.off',
        'guard.attach', 'change_summary', 'put',
    ])
        ->and(eng_bf_calls($st, 'fields_for_post'))->toBe([['fields_for_post', 7, 'doctor']])
        ->and(eng_bf_calls($st, 'wp_cache_delete'))->toBe([['wp_cache_delete', 7, 'post_meta']])
        ->and(eng_bf_calls($st, 'acf_get_value.on'))->toBe([['acf_get_value.on', 7, 'k_name']])
        ->and(eng_bf_calls($st, 'acf_get_value.off'))->toBe([['acf_get_value.off', 7, 'k_name']]);
});

it('hands the post id on as an integer whatever the database returned', function (): void {
    [, $st] = eng_bf_batch([eng_bf_post(12)], ['eff' => [12 => ['k_name' => 'x']], 'meta' => [12 => ['k_name' => 'x']]]);

    expect(eng_bf_calls($st, 'fields_for_post')[0][1])->toBeInt()->toBe(12)
        ->and(eng_bf_calls($st, 'acf_get_value.on')[0][1])->toBeInt()->toBe(12)
        ->and(eng_bf_calls($st, 'wp_cache_delete')[0][1])->toBeInt()->toBe(12);
});

it('writes nothing for a field whose two sides agree', function (): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => ' same ']],
        'meta' => [7 => ['k_name' => ' same ']],
    ]);

    expect($result)->toBe(['processed' => 1, 'cursor' => 7, 'done' => true, 'written' => 0, 'mismatches' => 0, 'warnings' => []])
        ->and(eng_bf_puts($st, 'change'))->toBe([])
        ->and(eng_bf_puts($st, 'backup'))->toBe([])
        ->and(eng_bf_calls($st, 'acf_update_value', 'clean_post_cache'))->toBe([]);
});

// backfill_batch(): the change record

it('files a difference by what each side holds', function (mixed $eff, mixed $meta, string $category): void {
    [, $st] = eng_bf_batch([eng_bf_post(7, 'doctor', 'Dr. Seven', '2026-03-04 05:06:07')], [
        'eff' => [7 => ['k_name' => $eff]],
        'meta' => [7 => ['k_name' => $meta]],
    ], run: ['dry' => true]);

    $change = eng_bf_puts($st, 'change');
    expect($change)->toHaveCount(1)
        ->and($change[0][0])->toBe('r1')
        ->and($change[0][1])->toBe(7)
        ->and($change[0][2])->toBe('name')
        ->and($change[0][3])->toBe([
            'post_id' => 7, 'post_type' => 'doctor', 'title' => 'Dr. Seven', 'modified' => '2026-03-04 05:06:07',
            'field' => 'name', 'key' => 'k_name', 'type' => 'text', 'category' => $category,
            'effective' => $eff === '' ? null : $eff, 'meta_before' => $meta === '' ? null : $meta,
        ]);
})->with([
    'postmeta empty' => ['served', null, 'fill'],
    'postmeta empty string' => ['served', '', 'fill'],
    'nothing served' => [null, 'stale', 'clear'],
    'served empty string' => ['', 'stale', 'clear'],
    'both differ' => ['served', 'stale', 'overwrite'],
]);

it('writes no change record at all with the fields_for_post of a post that has none', function (): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(7)], ['fields' => []]);

    expect($result['processed'])->toBe(1)
        ->and(eng_bf_puts($st, 'change'))->toBe([])
        ->and(eng_bf_calls($st, 'clean_post_cache'))->toBe([]);
});

// backfill_batch(): a dry run

it('only records a dry run, touching neither the backup nor the value', function (): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => 'served']],
        'meta' => [7 => ['k_name' => 'stale']],
    ], run: ['dry' => true]);

    $change = eng_bf_puts($st, 'change');
    expect($result)->toBe(['processed' => 1, 'cursor' => 7, 'done' => true, 'written' => 0, 'mismatches' => 0, 'warnings' => []])
        ->and($change)->toHaveCount(1)
        ->and(array_keys($change[0][3]))->not->toContain('skipped')
        ->and(eng_bf_puts($st, 'backup'))->toBe([])
        ->and(eng_bf_calls($st, 'acf_update_value', 'clean_post_cache', 'meta_rows_for_field'))->toBe([]);
});

// backfill_batch(): a live write

it('backs up the field, writes the effective value, reads it back and files the change', function (): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => 'served']],
        'meta' => [7 => ['k_name' => 'stale']],
    ]);

    expect($result)->toBe(['processed' => 1, 'cursor' => 7, 'done' => true, 'written' => 1, 'mismatches' => 0, 'warnings' => []]);

    $backup = eng_bf_puts($st, 'backup');
    expect($backup)->toHaveCount(1)
        ->and($backup[0])->toBe(['r1', 7, 'name', ['post_id' => 7, 'field' => 'name', 'complex' => false, 'rows' => [['row_for', 'name']]], 'ignore'])
        ->and(eng_bf_calls($st, 'meta_rows_for_field'))->toBe([['meta_rows_for_field', 7, 'name', false]])
        ->and(eng_bf_calls($st, 'acf_update_value'))->toBe([['acf_update_value', 'served', 7, 'k_name']]);

    $change = eng_bf_puts($st, 'change');
    expect($change)->toHaveCount(1)
        ->and(array_keys($change[0][3]))->toBe(['post_id', 'post_type', 'title', 'modified', 'field', 'key', 'type', 'category', 'effective', 'meta_before'])
        ->and($change[0][3]['category'])->toBe('overwrite');
});

it('orders a live write: backup, write, cache flush, read back, change record, post cache', function (): void {
    [, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => 'served']],
        'meta' => [7 => ['k_name' => 'stale']],
    ]);

    $names = eng_bf_order($st);
    $from = array_search('meta_rows_for_field', $names, true);
    expect(array_slice($names, $from))->toBe([
        'meta_rows_for_field', 'put', 'acf_update_value',
        'guard.flush', 'wp_cache_delete', 'acf_get_value.off',
        'put', 'clean_post_cache', 'guard.attach', 'change_summary', 'put',
    ]);
});

it('marks a complex field in the backup so its sub-field rows are kept', function (): void {
    $fields = ['rows' => ['name' => 'rows', 'key' => 'k_rows', 'type' => 'repeater', 'sub_fields' => []]];

    [, $st] = eng_bf_batch([eng_bf_post(7)], [
        'fields' => $fields,
        'eff' => [7 => ['k_rows' => [['a' => '1']]]],
        'meta' => [7 => ['k_rows' => null]],
    ]);

    $backup = eng_bf_puts($st, 'backup');
    expect($backup[0][3]['complex'])->toBeTrue()
        ->and(eng_bf_calls($st, 'meta_rows_for_field'))->toBe([['meta_rows_for_field', 7, 'rows', true]]);
});

it('files a clear when no_clear is off, and skips it when no_clear is on', function (bool $no_clear, int $written): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => null]],
        'meta' => [7 => ['k_name' => 'stale']],
    ], run: ['no_clear' => $no_clear]);

    $change = eng_bf_puts($st, 'change')[0][3];
    expect($result['written'])->toBe($written)
        ->and($change['category'])->toBe('clear')
        ->and(isset($change['skipped']))->toBe($no_clear)
        ->and(eng_bf_puts($st, 'backup'))->toHaveCount($written);
})->with([
    'clearing allowed' => [false, 1],
    'clearing forbidden' => [true, 0],
]);

it('does not skip a fill or an overwrite because no_clear is on', function (mixed $eff, mixed $meta, string $category): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => $eff]],
        'meta' => [7 => ['k_name' => $meta]],
    ], run: ['no_clear' => true]);

    expect($result['written'])->toBe(1)
        ->and(eng_bf_puts($st, 'change')[0][3]['category'])->toBe($category)
        ->and(isset(eng_bf_puts($st, 'change')[0][3]['skipped']))->toBeFalse();
})->with([
    'fill' => ['served', null, 'fill'],
    'overwrite' => ['served', 'stale', 'overwrite'],
]);

it('reads flags in the run record as present or absent', function (): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => 'served']],
        'meta' => [7 => ['k_name' => null]],
        'get' => ['run|r1' => ['status' => 'running']],
    ]);

    expect($result['written'])->toBe(1)
        ->and(eng_bf_puts($st, 'change')[0][3])->not->toHaveKey('skipped');
});

// backfill_batch(): invalid ids

it('never writes a corrupted id, and flags it', function (bool $dry): void {
    $fields = ['rel' => ['name' => 'rel', 'key' => 'k_rel', 'type' => 'post_object']];

    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'fields' => $fields,
        'eff' => [7 => ['k_rel' => '10522]']],
        'meta' => [7 => ['k_rel' => null]],
    ], run: ['dry' => $dry]);

    $record = eng_bf_puts($st, 'change')[0][3];
    expect($result['written'])->toBe(0)
        ->and($record['invalid'])->toBeTrue()
        ->and(isset($record['skipped']))->toBe(! $dry)
        ->and(eng_bf_puts($st, 'backup'))->toBe([])
        ->and(eng_bf_calls($st, 'acf_update_value'))->toBe([]);
})->with([
    'dry' => [true],
    'live' => [false],
]);

it('writes a valid id', function (): void {
    $fields = ['rel' => ['name' => 'rel', 'key' => 'k_rel', 'type' => 'post_object']];

    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'fields' => $fields,
        'eff' => [7 => ['k_rel' => '10522']],
        'meta' => [7 => ['k_rel' => null]],
    ]);

    expect($result['written'])->toBe(1)
        ->and(eng_bf_puts($st, 'change')[0][3])->not->toHaveKey('invalid');
});

// backfill_batch(): a write that does not read back

it('counts a write that does not read back and warns about it', function (): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => 'served']],
        'meta' => [7 => ['k_name' => 'stale']],
        'write_ignored' => true,
    ]);

    expect($result['written'])->toBe(1)
        ->and($result['mismatches'])->toBe(1)
        ->and($result['warnings'])->toBe(['Post 7 field name: postmeta does not match after write.']);

    $record = eng_bf_puts($st, 'change')[0][3];
    expect($record['write_mismatch'])->toBeTrue()
        ->and($record['meta_after'])->toBe('stale');
});

it('adds nothing to the record when the write reads back', function (): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => 'served']],
        'meta' => [7 => ['k_name' => 'stale']],
    ]);

    expect(eng_bf_puts($st, 'change')[0][3])->not->toHaveKey('write_mismatch')
        ->and(eng_bf_puts($st, 'change')[0][3])->not->toHaveKey('meta_after')
        ->and($result['warnings'])->toBe([]);
});

it('numbers the warnings and mismatches across posts and fields', function (): void {
    $fields = ['a' => eng_bf_field('a'), 'b' => eng_bf_field('b')];

    [$result] = eng_bf_batch([eng_bf_post(7), eng_bf_post(9)], [
        'fields' => $fields,
        'eff' => [7 => ['k_a' => 'x', 'k_b' => 'y'], 9 => ['k_a' => 'x', 'k_b' => 'y']],
        'meta' => [7 => ['k_a' => null, 'k_b' => null], 9 => ['k_a' => null, 'k_b' => null]],
        'write_ignored' => true,
    ]);

    expect($result['written'])->toBe(4)
        ->and($result['mismatches'])->toBe(4)
        ->and($result['warnings'])->toBe([
            'Post 7 field a: postmeta does not match after write.',
            'Post 7 field b: postmeta does not match after write.',
            'Post 9 field a: postmeta does not match after write.',
            'Post 9 field b: postmeta does not match after write.',
        ]);
});

// backfill_batch(): the post cache

it('cleans the post cache once for a post that was written, not for one that was not', function (): void {
    $fields = ['a' => eng_bf_field('a'), 'b' => eng_bf_field('b')];

    [, $st] = eng_bf_batch([eng_bf_post(7), eng_bf_post(8), eng_bf_post(9)], [
        'fields' => $fields,
        'eff' => [7 => ['k_a' => 'x', 'k_b' => 'y'], 8 => ['k_a' => 'x', 'k_b' => 'y'], 9 => ['k_a' => 'x', 'k_b' => 'y']],
        'meta' => [7 => ['k_a' => null, 'k_b' => null], 8 => ['k_a' => 'x', 'k_b' => 'y'], 9 => ['k_a' => 'x', 'k_b' => null]],
    ]);

    expect(eng_bf_calls($st, 'clean_post_cache'))->toBe([['clean_post_cache', 7], ['clean_post_cache', 9]]);
});

it('does not clean the post cache for a post whose only differences were skipped', function (): void {
    [, $st] = eng_bf_batch([eng_bf_post(7)], [
        'eff' => [7 => ['k_name' => null]],
        'meta' => [7 => ['k_name' => 'stale']],
    ], run: ['no_clear' => true]);

    expect(eng_bf_calls($st, 'clean_post_cache'))->toBe([]);
});

// backfill_batch(): the cursor and the run record

it('moves the cursor to the last post and reports the page', function (): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(5), eng_bf_post(6)], [], 0, 2);

    expect($result)->toBe(['processed' => 2, 'cursor' => 6, 'done' => false, 'written' => 0, 'mismatches' => 0, 'warnings' => []])
        ->and(eng_bf_calls($st, 'change_summary'))->toBe([]);

    $puts = eng_bf_puts($st, 'run');
    expect($puts)->toHaveCount(1)
        ->and($puts[0])->toBe(['r1', 0, '', ['dry' => false, 'no_clear' => false, 'cursor' => 6]]);
});

it('completes the run when the page is short, adding the change summary', function (): void {
    [$result, $st] = eng_bf_batch([eng_bf_post(5), eng_bf_post(6)], ['summary' => ['stats' => ['fill' => 2], 'by_field' => ['name' => 2]]], 0, 3);
    $before = time();

    expect($result['done'])->toBeTrue()
        ->and(eng_bf_calls($st, 'change_summary'))->toBe([['change_summary', 'r1']]);

    $run = eng_bf_puts($st, 'run')[0];
    $data = $run[3];
    expect(array_slice($run, 0, 3))->toBe(['r1', 0, ''])
        ->and(array_keys($data))->toBe(['dry', 'no_clear', 'cursor', 'status', 'finished', 'stats', 'by_field'])
        ->and($data['status'])->toBe('complete')
        ->and($data['cursor'])->toBe(6)
        ->and($data['stats'])->toBe(['fill' => 2])
        ->and($data['finished'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and(strtotime($data['finished']))->toBeLessThanOrEqual($before + 1);
});

it('keeps the cursor it was given when there is nothing more', function (): void {
    [$result, $st] = eng_bf_batch([], [], '40', 10);

    expect($result)->toBe(['processed' => 0, 'cursor' => '40', 'done' => true, 'written' => 0, 'mismatches' => 0, 'warnings' => []])
        ->and(eng_bf_puts($st, 'run')[0][3]['cursor'])->toBe('40')
        ->and(eng_bf_order($st))->toBe(['get', 'guard.new', 'guard.has_intercepts', 'guard.attach', 'change_summary', 'put']);
});

it('reads a page exactly the size of the limit as not done', function (int $limit, bool $done): void {
    [$result] = eng_bf_batch([eng_bf_post(1), eng_bf_post(2), eng_bf_post(3)], [], 0, $limit);

    expect($result['done'])->toBe($done);
})->with([
    'limit above the page' => [4, true],
    'limit equal to the page' => [3, false],
    'limit below the page' => [2, false],
]);

it('puts the hooks back after the loop and before it writes the run record', function (): void {
    [, $st] = eng_bf_batch([eng_bf_post(1)], [], 0, 5);

    $names = eng_bf_order($st);
    expect(array_slice($names, -3))->toBe(['guard.attach', 'change_summary', 'put']);
});
