<?php

/**
 * Store: the migration table's name, installation, existence check and row reads and writes, with WordPress and
 * `$wpdb` mocked.
 *
 * `$wpdb` is a Mockery stand-in whose `prepare()` records its arguments and returns the query behind a `P:` prefix;
 * each executing method records what it was handed and returns what the test queued, so a test reads the SQL the class
 * built and the values it bound. Whether MySQL accepts that SQL and returns what the class expects is for the
 * Integration suite. `dbDelta()` is a Brain Monkey expectation, and the core file that defines it is stood in for by an
 * empty file created at the path `ABSPATH` points to, since `ABSPATH` is defined once for the whole suite.
 *
 * Mutation run: `vendor/bin/pest tests/Unit/StoreTest.php --mutate --path=includes
 * --class='UAMSWP_ACFCDT_Migrator\Store' --covered-only`, 156 mutants, 0 survivors, no timeouts (macOS, PHP 8.4.27,
 * PCOV; a working run, not the Linux run that closes the coverage ticket). Three annotations in the source, each an
 * equivalent mutant: the integer cast on an array key, the first index `array_fill()` numbers from, and the value stored
 * under a key that is only counted. The `$wpdb` stand-in throws after 20 statements so a loop mutated to never end fails
 * rather than times out.
 *
 * @see includes/class-store.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Mockery\MockInterface;
use UAMSWP_ACFCDT_Migrator\Store;

use function Brain\Monkey\Functions\when;

beforeEach(function (): void {
    $GLOBALS['store_prepared'] = [];
    $GLOBALS['store_executed'] = [];
    $GLOBALS['store_returns'] = [];
    $GLOBALS['store_time_args'] = [];

    when('get_current_blog_id')->justReturn(5);
    when('current_time')->alias(function (string $type, bool $gmt = false): string {
        $GLOBALS['store_time_args'][] = [$type, $gmt];

        return '2026-01-02 03:04:05';
    });
    when('wp_json_encode')->alias(fn (mixed $data, int $flags = 0): string|false => json_encode($data, $flags));
});

afterEach(function (): void {
    unset($GLOBALS['store_time_args'], $GLOBALS['store_prepared'], $GLOBALS['store_executed'], $GLOBALS['store_returns'], $GLOBALS['wpdb']);
});

/**
 * A `$wpdb` that records each prepared query and each executed statement, and answers from what the test queued.
 *
 * `$returns` maps a method name to a list of answers handed out in order (the last one repeats).
 *
 * @param  array<string, list<mixed>>  $returns
 */
function store_wpdb(array $returns = []): MockInterface
{
    $GLOBALS['store_returns'] = $returns;
    $wpdb = Mockery::mock('wpdb');
    $wpdb->base_prefix = 'wpx_';
    $wpdb->shouldReceive('esc_like')->andReturnUsing(fn (string $t): string => addcslashes($t, '_%\\'));
    $wpdb->shouldReceive('get_charset_collate')->andReturn('DEFAULT CHARACTER SET utf8mb4');
    $wpdb->shouldReceive('prepare')->andReturnUsing(function (string $query, ...$args): string {
        $GLOBALS['store_prepared'][] = [$query, $args];

        return 'P:'.$query;
    });

    foreach (['get_var', 'get_results', 'get_col', 'query'] as $method) {
        $wpdb->shouldReceive($method)->andReturnUsing(function (string $sql) use ($method): mixed {
            $GLOBALS['store_executed'][] = [$method, $sql];
            if (count($GLOBALS['store_executed']) > 20) {
                throw new LogicException('Runaway loop: more than 20 statements executed.');
            }

            $queue = &$GLOBALS['store_returns'][$method];
            if (! isset($queue) || $queue === []) {
                return null;
            }

            return count($queue) > 1 ? array_shift($queue) : $queue[0];
        });
    }

    $GLOBALS['wpdb'] = $wpdb;

    return $wpdb;
}

/** The first prepared query and its arguments. */
function store_prepared(int $index = 0): array
{
    return $GLOBALS['store_prepared'][$index];
}

/** A row object as `$wpdb->get_results()` returns it. */
function store_row(array $fields): object
{
    return (object) $fields;
}

/** Creates the core file `install()` requires, at the path `ABSPATH` names, and returns the path. */
function store_upgrade_file(): string
{
    $path = ABSPATH.'wp-admin/includes/upgrade.php';
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, "<?php\n");

    return $path;
}

// table()

it('names the table after the network base prefix', function (): void {
    store_wpdb();

    expect(Store::table())->toBe('wpx_acfcdt_migration');
});

// exists()

it('looks the table up with an escaped LIKE pattern', function (): void {
    store_wpdb();

    Store::exists();

    [$query, $args] = store_prepared();
    expect($query)->toBe('SHOW TABLES LIKE %s')
        ->and($args)->toBe(['wpx\\_acfcdt\\_migration'])
        ->and($GLOBALS['store_executed'])->toBe([['get_var', 'P:SHOW TABLES LIKE %s']]);
});

it('reports the table present only when the lookup returns its exact name', function (mixed $found, bool $expected): void {
    store_wpdb(['get_var' => [$found]]);

    expect(Store::exists())->toBe($expected);
})->with([
    'its name' => ['wpx_acfcdt_migration', true],
    'no row' => [null, false],
    'another table' => ['wpx_other', false],
    'empty string' => ['', false],
    'a non-string match' => [1, false],
]);

// install()

it('does nothing when the installed version is current and the table exists', function (): void {
    store_wpdb(['get_var' => ['wpx_acfcdt_migration']]);
    Functions\expect('get_site_option')->once()->with('uamswp_acfcdt_migrator_db', 0)->andReturn(1);
    Functions\expect('update_site_option')->never();
    Functions\expect('dbDelta')->never();

    Store::install();
});

it('does nothing when the stored version is newer than this one', function (): void {
    store_wpdb(['get_var' => ['wpx_acfcdt_migration']]);
    when('get_site_option')->justReturn(2);
    Functions\expect('update_site_option')->never();
    Functions\expect('dbDelta')->never();

    Store::install();
});

it('creates the table when the stored version is older, even though the table exists', function (): void {
    store_wpdb(['get_var' => ['wpx_acfcdt_migration']]);
    when('get_site_option')->justReturn(0);
    store_upgrade_file();
    Functions\expect('dbDelta')->once();
    Functions\expect('update_site_option')->once()->with('uamswp_acfcdt_migrator_db', 1);

    Store::install();
});

it('creates the table when the version is current but the table is gone', function (): void {
    store_wpdb(['get_var' => [null]]);
    when('get_site_option')->justReturn(1);
    store_upgrade_file();
    Functions\expect('dbDelta')->once();
    Functions\expect('update_site_option')->once()->with('uamswp_acfcdt_migrator_db', 1);

    Store::install();
});

it('treats a non-numeric stored version as not installed', function (): void {
    store_wpdb(['get_var' => ['wpx_acfcdt_migration']]);
    when('get_site_option')->justReturn('abc');
    store_upgrade_file();
    Functions\expect('dbDelta')->once();
    Functions\expect('update_site_option')->once();

    Store::install();
});

it('casts the stored version before comparing it', function (): void {
    store_wpdb(['get_var' => [null]]);
    when('get_site_option')->justReturn('1');
    store_upgrade_file();
    Functions\expect('dbDelta')->once();
    Functions\expect('update_site_option')->once();

    Store::install();
});

it('hands dbDelta the table definition, built with the table name and the charset', function (): void {
    store_wpdb(['get_var' => [null]]);
    when('get_site_option')->justReturn(0);
    store_upgrade_file();
    $sql = new ArrayObject;
    Functions\expect('dbDelta')->once()->andReturnUsing(function (string $statement) use ($sql): void {
        $sql['sql'] = $statement;
    });
    when('update_site_option')->justReturn(true);

    Store::install();

    $flat = preg_replace('/\s+/', ' ', $sql['sql']);
    expect($flat)->toStartWith('CREATE TABLE wpx_acfcdt_migration ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, ')
        ->and($flat)->toContain('blog_id bigint(20) unsigned NOT NULL DEFAULT 0,')
        ->and($flat)->toContain("kind varchar(20) NOT NULL DEFAULT '',")
        ->and($flat)->toContain("ref varchar(64) NOT NULL DEFAULT '',")
        ->and($flat)->toContain('post_id bigint(20) unsigned NOT NULL DEFAULT 0,')
        ->and($flat)->toContain("field varchar(191) NOT NULL DEFAULT '',")
        ->and($flat)->toContain('data longtext NULL,')
        ->and($flat)->toContain("created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',")
        ->and($flat)->toContain('PRIMARY KEY (id),')
        ->and($flat)->toContain('UNIQUE KEY entry (blog_id,kind,ref,post_id,field),')
        ->and($flat)->toContain('KEY lookup (blog_id,kind,ref,id)')
        ->and($flat)->toEndWith(') DEFAULT CHARACTER SET utf8mb4;');
});

it('requires the core upgrade file through ABSPATH', function (): void {
    store_wpdb(['get_var' => [null]]);
    when('get_site_option')->justReturn(0);
    $path = store_upgrade_file();
    when('dbDelta')->justReturn([]);
    when('update_site_option')->justReturn(true);

    Store::install();

    expect(in_array(realpath($path), array_map(realpath(...), get_included_files()), true))->toBeTrue();
});

// put()

it('replaces by default and binds the key, the data and the time', function (): void {
    store_wpdb(['query' => [1]]);

    $result = Store::put('change', '20260102', '42', 'field_a', ['a' => 1]);

    [$query, $args] = store_prepared();
    expect($query)->toBe('REPLACE INTO wpx_acfcdt_migration (blog_id, kind, ref, post_id, field, data, created) VALUES (%d, %s, %s, %d, %s, %s, %s)')
        ->and($args)->toBe([5, 'change', '20260102', 42, 'field_a', '{"a":1}', '2026-01-02 03:04:05'])
        ->and($GLOBALS['store_executed'])->toBe([['query', 'P:'.$query]])
        ->and($result)->toBe(1);
});

it('inserts ignoring duplicates only for the ignore mode', function (string $mode, string $verb): void {
    store_wpdb();

    Store::put('backup', 's', 1, 'f', [], $mode);

    expect(store_prepared()[0])->toStartWith($verb.' INTO ');
})->with([
    'ignore' => ['ignore', 'INSERT IGNORE'],
    'replace' => ['replace', 'REPLACE'],
    'anything else' => ['IGNORE', 'REPLACE'],
    'empty' => ['', 'REPLACE'],
]);

it('casts the post id to an integer and the field to a string', function (): void {
    store_wpdb();

    Store::put('change', 's', '7abc', 12, null);

    expect(store_prepared()[1])->toBe([5, 'change', 's', 7, '12', 'null', '2026-01-02 03:04:05']);
});

it('asks for the time in GMT in MySQL format', function (): void {
    store_wpdb();

    Store::put('run', 's', 0, '', []);

    expect($GLOBALS['store_time_args'])->toBe([['mysql', true]]);
});

it('encodes the data without escaping slashes or unicode', function (): void {
    store_wpdb();

    Store::put('change', 's', 1, 'f', ['url' => 'https://x.test/a', 'name' => "caf\u{e9}"]);

    expect(store_prepared()[1][5])->toBe("{\"url\":\"https://x.test/a\",\"name\":\"caf\u{e9}\"}");
});

// get()

it('reads one row by its full key', function (): void {
    store_wpdb(['get_var' => ['{"a":[1,2]}']]);

    $result = Store::get('snapshot', 'label', '9', 'f');

    [$query, $args] = store_prepared();
    expect($query)->toBe('SELECT data FROM wpx_acfcdt_migration WHERE blog_id = %d AND kind = %s AND ref = %s AND post_id = %d AND field = %s')
        ->and($args)->toBe([5, 'snapshot', 'label', 9, 'f'])
        ->and($GLOBALS['store_executed'])->toBe([['get_var', 'P:'.$query]])
        ->and($result)->toBe(['a' => [1, 2]]);
});

it('binds the field as a string', function (): void {
    store_wpdb(['get_var' => ['1']]);

    Store::get('change', 's', 1, 12);

    expect(store_prepared()[1])->toBe([5, 'change', 's', 1, '12']);
});

it('defaults to a header row and returns null when there is none', function (): void {
    store_wpdb(['get_var' => [null]]);

    expect(Store::get('run', 'stamp'))->toBeNull()
        ->and(store_prepared()[1])->toBe([5, 'run', 'stamp', 0, '']);
});

it('returns a stored empty string as null from the decoder, not as a missing row', function (): void {
    store_wpdb(['get_var' => ['']]);

    expect(Store::get('run', 'stamp'))->toBeNull();
});

it('decodes JSON scalars', function (): void {
    store_wpdb(['get_var' => ['0']]);

    expect(Store::get('run', 'stamp'))->toBe(0);
});

// rows()

it('pages ascending rows after a cursor', function (): void {
    store_wpdb(['get_results' => [[store_row(['id' => 3])]]]);

    $rows = Store::rows('snapshot', 'label', 2, 50);

    [$query, $args] = store_prepared();
    expect($query)->toBe('SELECT id, post_id, field, data FROM wpx_acfcdt_migration WHERE blog_id = %d AND kind = %s AND ref = %s AND id > %d ORDER BY id ASC LIMIT %d')
        ->and($args)->toBe([5, 'snapshot', 'label', 2, 50])
        ->and($GLOBALS['store_executed'])->toBe([['get_results', 'P:'.$query]])
        ->and($rows)->toHaveCount(1);
});

it('defaults to the start, 500 rows and ascending order', function (): void {
    store_wpdb();

    Store::rows('snapshot', 'label');

    [$query, $args] = store_prepared();
    expect($query)->toContain('id > %d ORDER BY id ASC')
        ->and($args)->toBe([5, 'snapshot', 'label', 0, 500]);
});

it('pages descending rows before a cursor', function (): void {
    store_wpdb();

    Store::rows('change', 's', 40, 10, true);

    [$query, $args] = store_prepared();
    expect($query)->toContain('id < %d ORDER BY id DESC LIMIT %d')
        ->and($args)->toBe([5, 'change', 's', 40, 10]);
});

it('starts a descending page from the largest id when there is no cursor', function (mixed $cursor): void {
    store_wpdb();

    Store::rows('change', 's', $cursor, 10, true);

    expect(store_prepared()[1][3])->toBe(PHP_INT_MAX);
})->with([0, '0', null, false]);

it('leaves an ascending cursor of zero alone', function (): void {
    store_wpdb();

    Store::rows('change', 's', 0, 10, false);

    expect(store_prepared()[1][3])->toBe(0);
});

// by_post()

it('keys decoded rows by post id', function (): void {
    store_wpdb(['get_results' => [[
        store_row(['id' => '1', 'post_id' => '10', 'data' => '{"a":1}']),
        store_row(['id' => '2', 'post_id' => '11', 'data' => '[2]']),
    ]]]);

    expect(Store::by_post('snapshot', 'label'))->toBe([10 => ['a' => 1], 11 => [2]])
        ->and(store_prepared()[1])->toBe([5, 'snapshot', 'label', 0, 1000]);
});

it('returns an empty array when there are no rows', function (): void {
    store_wpdb(['get_results' => [[]]]);

    expect(Store::by_post('snapshot', 'label'))->toBe([]);
});

it('keeps paging while a full page of 1000 comes back, from the last id seen', function (): void {
    $full = [];
    for ($i = 1; $i <= 1000; $i++) {
        $full[] = store_row(['id' => (string) $i, 'post_id' => (string) $i, 'data' => (string) $i]);
    }

    store_wpdb(['get_results' => [$full, [store_row(['id' => '1001', 'post_id' => '5000', 'data' => '"last"'])]]]);

    $out = Store::by_post('snapshot', 'label');

    expect(count($GLOBALS['store_prepared']))->toBe(2)
        ->and(store_prepared(0)[1][3])->toBe(0)
        ->and(store_prepared(1)[1][3])->toBe(1000)
        ->and(store_prepared(1)[1][4])->toBe(1000)
        ->and($out)->toHaveCount(1001)
        ->and($out[1])->toBe(1)
        ->and($out[5000])->toBe('last');
});

it('stops after a page of 999', function (): void {
    $short = [];
    for ($i = 1; $i <= 999; $i++) {
        $short[] = store_row(['id' => (string) $i, 'post_id' => (string) $i, 'data' => '1']);
    }

    store_wpdb(['get_results' => [$short, [store_row(['id' => '1000', 'post_id' => '1000', 'data' => '1'])]]]);

    expect(Store::by_post('snapshot', 'label'))->toHaveCount(999)
        ->and(count($GLOBALS['store_prepared']))->toBe(1);
});

it('stops after a page of 1001, which cannot happen but is not a full page', function (): void {
    $over = [];
    for ($i = 1; $i <= 1001; $i++) {
        $over[] = store_row(['id' => (string) $i, 'post_id' => (string) $i, 'data' => '1']);
    }

    store_wpdb(['get_results' => [$over, [store_row(['id' => '2000', 'post_id' => '2000', 'data' => '1'])]]]);

    expect(Store::by_post('snapshot', 'label'))->toHaveCount(1001)
        ->and(count($GLOBALS['store_prepared']))->toBe(1);
});

// count()

it('counts the rows of a kind and ref', function (): void {
    store_wpdb(['get_var' => ['12']]);

    $total = Store::count('change', 'stamp');

    [$query, $args] = store_prepared();
    expect($query)->toBe('SELECT COUNT(*) FROM wpx_acfcdt_migration WHERE blog_id = %d AND kind = %s AND ref = %s ')
        ->and($args)->toBe([5, 'change', 'stamp'])
        ->and($GLOBALS['store_executed'])->toBe([['get_var', 'P:'.$query]])
        ->and($total)->toBe(12);
});

it('appends a caller-supplied condition', function (): void {
    store_wpdb(['get_var' => ['3']]);

    Store::count('change', 'stamp', "AND field = 'x'");

    expect(store_prepared()[0])->toEndWith("ref = %s AND field = 'x'");
});

it('counts nothing when the query returns null', function (): void {
    store_wpdb(['get_var' => [null]]);

    expect(Store::count('change', 'stamp'))->toBe(0);
});

// delete()

it('deletes the given kinds of one ref, one placeholder per kind', function (): void {
    store_wpdb(['query' => [4]]);

    $result = Store::delete(['change', 'backup', 'run'], 'stamp');

    [$query, $args] = store_prepared();
    expect($query)->toBe('DELETE FROM wpx_acfcdt_migration WHERE blog_id = %d AND ref = %s AND kind IN (%s,%s,%s)')
        ->and($args)->toBe([[5, 'stamp', 'change', 'backup', 'run']])
        ->and($GLOBALS['store_executed'])->toBe([['query', 'P:'.$query]])
        ->and($result)->toBe(4);
});

it('uses one placeholder for one kind', function (): void {
    store_wpdb();

    Store::delete(['run'], 's');

    expect(store_prepared()[0])->toEndWith('kind IN (%s)');
});

// headers()

it('lists header rows newest first, merging the creation time under the header data', function (): void {
    store_wpdb(['get_results' => [[
        store_row(['ref' => 'b', 'data' => '{"mode":"x"}', 'created' => '2026-01-02 00:00:00']),
        store_row(['ref' => 'a', 'data' => '{"mode":"y","created":"own"}', 'created' => '2026-01-01 00:00:00']),
    ]]]);

    $out = Store::headers('snapinfo');

    [$query, $args] = store_prepared();
    expect($query)->toBe('SELECT ref, data, created FROM wpx_acfcdt_migration WHERE blog_id = %d AND kind = %s ORDER BY id DESC')
        ->and($args)->toBe([5, 'snapinfo'])
        ->and($GLOBALS['store_executed'])->toBe([['get_results', 'P:'.$query]])
        ->and(array_keys($out))->toBe(['b', 'a'])
        ->and($out['b'])->toBe(['mode' => 'x', 'created' => '2026-01-02 00:00:00'])
        ->and($out['a'])->toBe(['mode' => 'y', 'created' => 'own']);
});

it('decodes header data to arrays, nested ones included', function (): void {
    store_wpdb(['get_results' => [[store_row(['ref' => 'a', 'data' => '{"x":{"y":1}}', 'created' => 'now'])]]]);

    expect(Store::headers('run'))->toBe(['a' => ['x' => ['y' => 1], 'created' => 'now']]);
});

it('treats undecodable header data as empty', function (): void {
    store_wpdb(['get_results' => [[store_row(['ref' => 'a', 'data' => 'not json', 'created' => 'now'])]]]);

    expect(Store::headers('run'))->toBe(['a' => ['created' => 'now']]);
});

it('returns an empty array when there are no headers', function (): void {
    store_wpdb(['get_results' => [[]]]);

    expect(Store::headers('run'))->toBe([]);
});

// change_summary()

/** A change row's JSON. */
function store_change(string $category, string $type, string $field, int $post, array $flags = []): string
{
    return json_encode(['category' => $category, 'post_type' => $type, 'field' => $field, 'post_id' => $post] + $flags);
}

it('reads the change rows of one run', function (): void {
    store_wpdb(['get_col' => [[]]]);

    Store::change_summary('stamp');

    [$query, $args] = store_prepared();
    expect($query)->toBe('SELECT data FROM wpx_acfcdt_migration WHERE blog_id = %d AND kind = %s AND ref = %s')
        ->and($args)->toBe([5, 'change', 'stamp'])
        ->and($GLOBALS['store_executed'])->toBe([['get_col', 'P:'.$query]]);
});

it('returns zeroed stats and no fields for a run with no changes', function (): void {
    store_wpdb(['get_col' => [[]]]);

    expect(Store::change_summary('stamp'))->toBe([
        'stats' => ['fields' => 0, 'fill' => 0, 'overwrite' => 0, 'clear' => 0, 'skipped' => 0, 'invalid' => 0, 'write_mismatch' => 0, 'posts' => 0],
        'by_field' => [],
    ]);
});

it('counts fields, categories and distinct posts', function (): void {
    store_wpdb(['get_col' => [[
        store_change('fill', 'doctor', 'a', 1),
        store_change('fill', 'doctor', 'a', 1),
        store_change('overwrite', 'doctor', 'b', 2),
        store_change('clear', 'clinic', 'a', 3),
    ]]]);

    $summary = Store::change_summary('stamp');

    expect($summary['stats'])->toBe(['fields' => 4, 'fill' => 2, 'overwrite' => 1, 'clear' => 1, 'skipped' => 0, 'invalid' => 0, 'write_mismatch' => 0, 'posts' => 3]);
});

it('counts each flag independently', function (): void {
    store_wpdb(['get_col' => [[
        store_change('fill', 'doctor', 'a', 1, ['skipped' => true]),
        store_change('fill', 'doctor', 'a', 2, ['invalid' => true]),
        store_change('fill', 'doctor', 'a', 3, ['write_mismatch' => true]),
        store_change('fill', 'doctor', 'a', 4, ['skipped' => false, 'invalid' => 0, 'write_mismatch' => '']),
        store_change('fill', 'doctor', 'a', 5, ['skipped' => true, 'invalid' => true, 'write_mismatch' => true]),
    ]]]);

    expect(Store::change_summary('stamp')['stats'])->toBe(['fields' => 5, 'fill' => 5, 'overwrite' => 0, 'clear' => 0, 'skipped' => 2, 'invalid' => 2, 'write_mismatch' => 2, 'posts' => 5]);
});

it('groups the counts by post type and field, sorted by key', function (): void {
    store_wpdb(['get_col' => [[
        store_change('fill', 'doctor', 'b', 1),
        store_change('overwrite', 'clinic', 'z', 2),
        store_change('clear', 'doctor', 'a', 3),
        store_change('fill', 'doctor', 'b', 4),
        store_change('overwrite', 'doctor', 'b', 5),
    ]]]);

    expect(Store::change_summary('stamp')['by_field'])->toBe([
        ['field' => 'clinic.z', 'fill' => 0, 'overwrite' => 1, 'clear' => 0],
        ['field' => 'doctor.a', 'fill' => 0, 'overwrite' => 0, 'clear' => 1],
        ['field' => 'doctor.b', 'fill' => 2, 'overwrite' => 1, 'clear' => 0],
    ]);
});

it('keeps the per-field row from the first change and does not reset it for later ones', function (): void {
    store_wpdb(['get_col' => [[
        store_change('fill', 'doctor', 'a', 1),
        store_change('fill', 'doctor', 'a', 2),
        store_change('fill', 'doctor', 'a', 3),
    ]]]);

    expect(Store::change_summary('stamp')['by_field'])->toBe([['field' => 'doctor.a', 'fill' => 3, 'overwrite' => 0, 'clear' => 0]]);
});
