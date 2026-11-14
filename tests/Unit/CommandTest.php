<?php

/**
 * Command: the WP-CLI front end, with WP-CLI, WordPress, the Engine, the Store and the Site mocked.
 *
 * `WP_CLI` is a recording stand-in (tests/Unit/Fixtures/wp-cli-stand-in.php) whose `error()` and `halt()` throw
 * where the real ones exit the process. The `WP_CLI\Utils` functions are Brain Monkey mocks. The Engine, Site and
 * Store methods the command calls are replaced with Patchwork, so each test states what the layer below returns.
 * What those layers do with a real database is for the Integration suite.
 *
 * Mutation run: `vendor/bin/pest tests/Unit/CommandTest.php --mutate --path=includes
 * --class='UAMSWP_ACFCDT_Migrator\Command' --covered-only`, 477 mutants, 0 survivors, no timeouts (macOS, PHP 8.4.27,
 * PCOV; a working run, not the Linux run that closes the coverage ticket).
 *
 * `run_child()` calls `getenv()`, `proc_open()` and `proc_close()`, and `archive()` calls `fclose()`. Patchwork cannot
 * redefine internal functions without a `patchwork.json` that breaks the mutation run, so
 * tests/Unit/Fixtures/process-shims.php defines same-named functions in the plugin's namespace, which PHP resolves
 * first. A plain file reads the same whether or not it was closed, so the close shim is the only way a test sees it.
 *
 * @see includes/class-command.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Mockery\MockInterface;
use UAMSWP_ACFCDT_Migrator\Command;
use UAMSWP_ACFCDT_Migrator\Engine;
use UAMSWP_ACFCDT_Migrator\Site;
use UAMSWP_ACFCDT_Migrator\Store;

use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;

require_once __DIR__.'/Fixtures/wp-cli-stand-in.php';
require_once __DIR__.'/Fixtures/process-shims.php';

defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
defined('UAMSWP_ACFCDT_MIGRATOR_FILE') || define('UAMSWP_ACFCDT_MIGRATOR_FILE', '/plugins/uamswp-acfcdt-migrator/uamswp-acfcdt-migrator.php');

beforeEach(function (): void {
    CommandTestProcess::reset();
    CommandTestCli::$calls = [];
    CommandTestCli::$runner = (object) ['config' => []];

    $GLOBALS['cmd_tables'] = [];
    $GLOBALS['cmd_bars'] = [];
    $GLOBALS['cmd_cache_clears'] = 0;

    when('WP_CLI\Utils\get_flag_value')->alias(fn ($assoc, $flag, $default = null) => $assoc[$flag] ?? $default);
    when('WP_CLI\Utils\format_items')->alias(function ($format, $items, $fields): void {
        $GLOBALS['cmd_tables'][] = [$format, $items, $fields];
    });
    when('WP_CLI\Utils\make_progress_bar')->alias(fn ($message, $count): CommandTestProgressBar => $GLOBALS['cmd_bars'][] = new CommandTestProgressBar($message, $count));
    when('WP_CLI\Utils\wp_clear_object_cache')->alias(function (): void {
        $GLOBALS['cmd_cache_clears']++;
    });
    when('get_current_blog_id')->justReturn(7);
    when('home_url')->justReturn('https://example.test/');
    when('absint')->alias(fn ($n): int => abs((int) $n));

    // The Engine's constructor installs the log table; nothing here has a database.
    redefine(Store::class.'::install', fn (): null => null);
    redefine(Engine::class.'::acf_ready', fn (): bool => true);
});

afterEach(function (): void {
    foreach ($GLOBALS['cmd_dirs'] ?? [] as $dir) {
        array_map(unlink(...), glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    unset($GLOBALS['cmd_dirs'], $GLOBALS['cmd_tables'], $GLOBALS['cmd_bars'], $GLOBALS['cmd_cache_clears'], $GLOBALS['cmd_sites_args'], $GLOBALS['cmd_var_calls'], $GLOBALS['wpdb']);
});

/**
 * Replaces Engine instance methods; each closure receives the call's arguments.
 *
 * @param  array<string, Closure>  $methods
 */
function cmd_engine(array $methods): void
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
function cmd_store(array $methods): void
{
    foreach ($methods as $name => $fn) {
        redefine(Store::class.'::'.$name, $fn);
    }
}

/**
 * The messages WP_CLI was given for one kind of call, in order.
 *
 * @return list<mixed>
 */
function cmd_calls(string $type): array
{
    return array_values(array_map(fn (array $c): mixed => $c[1], array_filter(CommandTestCli::$calls, fn (array $c): bool => $c[0] === $type)));
}

/**
 * The header every data subcommand prints first.
 *
 * @return list<list<string>>
 */
function cmd_header(): array
{
    return [['log', ''], ['log', '%Y### Site 7: https://example.test/%n']];
}

it('stops with an error when ACF is not loaded', function (): void {
    redefine(Engine::class.'::acf_ready', fn (): bool => false);

    expect(fn () => (new Command)->status([], []))->toThrow(RuntimeException::class, 'WP_CLI::error: ACF is not loaded.');
});

it('lists nothing stored yet when there are no snapshots and no runs', function (): void {
    cmd_store(['headers' => fn ($kind): array => []]);

    (new Command)->status([], []);

    expect(CommandTestCli::$calls)->toBe([...cmd_header(), ['log', '   nothing stored yet']])
        ->and($GLOBALS['cmd_tables'])->toBe([]);
});

it('lists snapshots and runs as one table', function (): void {
    cmd_store([
        'headers' => fn ($kind): array => match ($kind) {
            'snapinfo' => [
                'before' => ['mode' => 'effective', 'status' => 'done', 'created' => '2026-01-01'],
                'bare' => ['created' => '2026-01-02'],
            ],
            'rollback' => ['run-b' => ['status' => 'done']],
            'run' => [
                'run-a' => ['dry' => true, 'stats' => ['fields' => 4, 'write_mismatch' => 0], 'status' => 'done', 'created' => '2026-01-03'],
                'run-b' => ['stats' => ['fields' => 9, 'write_mismatch' => 2], 'status' => 'done', 'created' => '2026-01-04'],
                'run-c' => ['created' => '2026-01-05'],
            ],
            default => throw new LogicException("Unexpected header kind: $kind"),
        },
        'change_summary' => fn ($stamp): array => ['stats' => ['fields' => 3, 'write_mismatch' => 1]],
    ]);

    (new Command)->status([], []);

    expect($GLOBALS['cmd_tables'])->toBe([[
        'table',
        [
            ['type' => 'snapshot', 'name' => 'before', 'detail' => 'effective', 'status' => 'done', 'when' => '2026-01-01'],
            ['type' => 'snapshot', 'name' => 'bare', 'detail' => '', 'status' => '', 'when' => '2026-01-02'],
            ['type' => 'dry run', 'name' => 'run-a', 'detail' => '4 fields, 0 mismatches', 'status' => 'done', 'when' => '2026-01-03'],
            ['type' => 'backfill', 'name' => 'run-b', 'detail' => '9 fields, 2 mismatches, rollback done', 'status' => 'done', 'when' => '2026-01-04'],
            ['type' => 'backfill', 'name' => 'run-c', 'detail' => '3 fields, 1 mismatches', 'status' => '', 'when' => '2026-01-05'],
        ],
        ['type', 'name', 'detail', 'status', 'when'],
    ]])->and(cmd_calls('log'))->not->toContain('   nothing stored yet');
});

it('reports a missing run and stops before rolling back', function (): void {
    cmd_store(['get' => fn ($kind, $ref, $post_id = 0, $field = ''): null => null]);
    cmd_engine(['rollback_start' => fn (): never => throw new LogicException('must not be reached')]);

    (new Command)->rollback(['gone'], []);

    expect(CommandTestCli::$calls)->toBe([...cmd_header(), ['log', "   No run gone on this site \u{2014} nothing to roll back."]]);
});

it('looks the run up by kind and name', function (): void {
    $asked = new ArrayObject;
    cmd_store(['get' => function ($kind, $ref, $post_id = 0, $field = '') use ($asked): null {
        $asked[] = [$kind, $ref];

        return null;
    }]);

    (new Command)->rollback(['run-1'], []);

    expect($asked->getArrayCopy())->toBe([['run', 'run-1']]);
});

it('rolls back in batches of 200 and counts what was restored', function (): void {
    $seen = new ArrayObject;
    cmd_store(['get' => fn ($kind, $ref, $post_id = 0, $field = ''): array => ['status' => 'done']]);
    cmd_engine([
        'rollback_start' => function ($ref, $force) use ($seen): string {
            $seen[] = ['start', $ref, $force];

            return 'stamp-9';
        },
        'rollback_batch' => function ($stamp, $cursor, $limit) use ($seen): array {
            $seen[] = ['batch', $stamp, $cursor, $limit];

            return $cursor === 0
                ? ['cursor' => 40, 'processed' => 5, 'done' => false]
                : ['cursor' => 80, 'processed' => 3, 'done' => true];
        },
    ]);

    (new Command)->rollback(['run-1'], ['force' => true]);

    expect($seen->getArrayCopy())->toBe([['start', 'run-1', true], ['batch', 'stamp-9', 0, 200], ['batch', 'stamp-9', 40, 200]])
        ->and(cmd_calls('success'))->toBe(['Site 7: 8 field(s) restored from run stamp-9.']);
});

it('rolls back without forcing by default', function (): void {
    $force = new ArrayObject;
    cmd_store(['get' => fn ($kind, $ref, $post_id = 0, $field = ''): array => ['status' => 'done']]);
    cmd_engine([
        'rollback_start' => function ($ref, $f) use ($force): string {
            $force[] = $f;

            return 's';
        },
        'rollback_batch' => fn ($stamp, $cursor, $limit): array => ['cursor' => 0, 'processed' => 0, 'done' => true],
    ]);

    (new Command)->rollback(['run-1'], []);

    expect($force->getArrayCopy())->toBe([false])
        ->and(cmd_calls('success'))->toBe(['Site 7: 0 field(s) restored from run s.']);
});

it('turns a refused rollback into a CLI error', function (): void {
    cmd_store(['get' => fn ($kind, $ref, $post_id = 0, $field = ''): array => ['status' => 'done']]);
    cmd_engine(['rollback_start' => fn ($ref, $force): never => throw new Exception('already rolled back')]);

    expect(fn () => (new Command)->rollback(['run-1'], []))->toThrow(RuntimeException::class, 'WP_CLI::error: already rolled back');
});

/**
 * @param  ArrayObject<int, mixed>  $seen
 * @param  list<array<string, mixed>>  $batches
 * @return array<string, mixed>
 */
function cmd_snapshot_engine(ArrayObject $seen, array $batches = []): array
{
    $queue = new SplQueue;
    foreach ($batches ?: [['cursor' => 9, 'values' => 4, 'processed' => 2, 'done' => true]] as $batch) {
        $queue->enqueue($batch);
    }

    return [
        'snapshot_start' => function ($label, $mode, $force) use ($seen): string {
            $seen[] = ['start', $label, $mode, $force];

            return 'label-'.$label;
        },
        'total_posts' => function ($ids) use ($seen): int {
            $seen[] = ['total', $ids];

            return 12;
        },
        'snapshot_batch' => function ($label, $cursor, $limit, $ids) use ($seen, $queue): array {
            $seen[] = ['batch', $label, $cursor, $limit, $ids];

            return $queue->dequeue();
        },
    ];
}

it('snapshots in batches of 50, ticking the bar and clearing the object cache after each', function (): void {
    $seen = new ArrayObject;
    cmd_engine(cmd_snapshot_engine($seen, [
        ['cursor' => 5, 'values' => 3, 'processed' => 2, 'done' => false],
        ['cursor' => 9, 'values' => 4, 'processed' => 1, 'done' => true],
    ]));

    (new Command)->snapshot(['before'], ['mode' => 'meta', 'force' => true]);

    $bar = $GLOBALS['cmd_bars'][0];

    expect($seen->getArrayCopy())->toBe([
        ['start', 'before', 'meta', true],
        ['total', null],
        ['batch', 'label-before', 0, 50, null],
        ['batch', 'label-before', 5, 50, null],
    ])
        ->and($bar->message)->toBe("Snapshot 'label-before' \u{2013} 12 posts")
        ->and($bar->count)->toBe(12)
        ->and($bar->ticks)->toBe(3)
        ->and($bar->finished)->toBeTrue()
        ->and($GLOBALS['cmd_cache_clears'])->toBe(2)
        ->and(cmd_calls('success'))->toBe(["Site 7: snapshot \"label-before\" \u{2014} 12 posts, 7 non-empty values."])
        ->and(array_slice(CommandTestCli::$calls, 0, 2))->toBe(cmd_header());
});

it('snapshots the effective mode without forcing by default', function (): void {
    $seen = new ArrayObject;
    cmd_engine(cmd_snapshot_engine($seen));

    (new Command)->snapshot(['before'], []);

    expect($seen[0])->toBe(['start', 'before', 'effective', false]);
});

it('limits a snapshot to the post IDs given, dropping blanks and non-numbers', function (): void {
    $seen = new ArrayObject;
    cmd_engine(cmd_snapshot_engine($seen));

    (new Command)->snapshot(['before'], ['ids' => '3,x,5']);

    expect($seen[1])->toBe(['total', [0 => 3, 2 => 5]])
        ->and($seen[2])->toBe(['batch', 'label-before', 0, 50, [0 => 3, 2 => 5]]);
});

it('treats an empty or zero ID list as every post', function (string $ids): void {
    $seen = new ArrayObject;
    cmd_engine(cmd_snapshot_engine($seen));

    (new Command)->snapshot(['before'], ['ids' => $ids]);

    expect($seen[1])->toBe(['total', null]);
})->with(['empty' => [''], 'zero' => ['0']]);

it('turns a refused snapshot into a CLI error', function (): void {
    cmd_engine(['snapshot_start' => fn ($label, $mode, $force): never => throw new Exception('snapshot exists')]);

    expect(fn () => (new Command)->snapshot(['before'], []))->toThrow(RuntimeException::class, 'WP_CLI::error: snapshot exists');
});

/**
 * @param  list<array<string, mixed>>  $diffs
 * @param  array<string, mixed>  $summary
 * @return array<string, mixed>
 */
function cmd_compare_result(array $diffs = [], array $summary = []): array
{
    return ['diffs' => $diffs, 'summary' => $summary + ['posts' => 5, 'identical' => 5]];
}

it('compares two snapshots and reports they are identical', function (): void {
    $seen = new ArrayObject;
    cmd_engine(['compare' => function ($a, $b, $ignore) use ($seen): array {
        $seen->exchangeArray([$a, $b, $ignore]);

        return cmd_compare_result();
    }]);

    (new Command)->compare(['before', 'after'], []);

    expect($seen->getArrayCopy())->toBe(['before', 'after', []])
        ->and($GLOBALS['cmd_tables'])->toBe([['table', [['posts' => 5]], ['posts']]])
        ->and(cmd_calls('success'))->toBe(["'before' and 'after' are identical."])
        ->and(cmd_calls('warning'))->toBe([])
        ->and(cmd_calls('halt'))->toBe([]);
});

it('passes the ignored fields trimmed, with blanks dropped', function (): void {
    $seen = new ArrayObject;
    cmd_engine(['compare' => function ($a, $b, $ignore) use ($seen): array {
        $seen->exchangeArray($ignore);

        return cmd_compare_result();
    }]);

    (new Command)->compare(['a', 'b'], ['ignore-fields' => ' one , two ,,three']);

    expect($seen->getArrayCopy())->toBe([0 => 'one', 1 => 'two', 3 => 'three']);
});

it('prints each difference up to the limit, then warns and halts with 1', function (): void {
    $diffs = array_map(fn (int $i): array => ['post_id' => $i, 'title' => "Post $i", 'field' => "f$i", 'a' => "va$i", 'b' => null], range(1, 3));
    cmd_engine(['compare' => fn ($a, $b, $ignore): array => cmd_compare_result($diffs, ['changed' => 3])]);

    expect(fn () => (new Command)->compare(['before', 'after'], ['limit' => '2']))->toThrow(RuntimeException::class, 'WP_CLI::halt: 1');

    expect(cmd_calls('log'))->toBe([
        '',
        '%Y### Site 7: https://example.test/%n',
        'post 1 "Post 1" field f1',
        '    before: "va1"',
        '    after: (empty)',
        'post 2 "Post 2" field f2',
        '    before: "va2"',
        '    after: (empty)',
    ])
        ->and(cmd_calls('warning'))->toBe(["3 difference(s) between 'before' and 'after'."])
        ->and(cmd_calls('success'))->toBe([])
        ->and($GLOBALS['cmd_tables'])->toBe([['table', [['changed' => 3, 'posts' => 5]], ['changed', 'posts']]]);
});

it('prints up to 25 differences by default', function (): void {
    $diffs = array_map(fn (int $i): array => ['post_id' => $i, 'title' => 't', 'field' => 'f', 'a' => 1, 'b' => 2], range(1, 30));
    cmd_engine(['compare' => fn ($a, $b, $ignore): array => cmd_compare_result($diffs)]);

    try {
        (new Command)->compare(['a', 'b'], []);
    } catch (RuntimeException) {
    }

    expect(count(array_filter(cmd_calls('log'), fn ($m): bool => str_starts_with((string) $m, 'post '))))->toBe(25);
});

it('truncates a long value in a difference to 160 characters', function (): void {
    cmd_engine(['compare' => fn ($a, $b, $ignore): array => cmd_compare_result([
        ['post_id' => 1, 'title' => 't', 'field' => 'f', 'a' => str_repeat('x', 158), 'b' => str_repeat('y', 157)],
    ])]);
    when('wp_json_encode')->alias('json_encode');

    try {
        (new Command)->compare(['a', 'b'], []);
    } catch (RuntimeException) {
    }

    // 158 characters plus two quotes is 160, left whole; 157 plus two is 159, left whole. Add one more below.
    expect(cmd_calls('log')[3])->toBe('    a: "'.str_repeat('x', 158).'"')
        ->and(cmd_calls('log')[4])->toBe('    b: "'.str_repeat('y', 157).'"');
});

it('cuts a value at 157 characters and an ellipsis once it passes 160', function (): void {
    cmd_engine(['compare' => fn ($a, $b, $ignore): array => cmd_compare_result([
        ['post_id' => 1, 'title' => 't', 'field' => 'f', 'a' => str_repeat('x', 159), 'b' => 1],
    ])]);
    when('wp_json_encode')->alias('json_encode');

    try {
        (new Command)->compare(['a', 'b'], []);
    } catch (RuntimeException) {
    }

    expect(cmd_calls('log')[3])->toBe('    a: "'.str_repeat('x', 156).'...');
});

it('reads a bare --ignore-fields flag as the one name 1', function (): void {
    $seen = new ArrayObject;
    cmd_engine(['compare' => function ($a, $b, $ignore) use ($seen): array {
        $seen->exchangeArray($ignore);

        return cmd_compare_result();
    }]);

    (new Command)->compare(['a', 'b'], ['ignore-fields' => true]);

    expect($seen->getArrayCopy())->toBe(['1']);
});

it('turns a refused compare into a CLI error', function (): void {
    cmd_engine(['compare' => fn ($a, $b, $ignore): never => throw new Exception('no such snapshot')]);

    expect(fn () => (new Command)->compare(['a', 'b'], []))->toThrow(RuntimeException::class, 'WP_CLI::error: no such snapshot');
});

/**
 * @param  array<string, mixed>  $stats
 * @param  list<array<string, mixed>>  $by_field
 * @return array<string, mixed>
 */
function cmd_summary(array $stats = [], array $by_field = []): array
{
    return [
        'by_field' => $by_field,
        'stats' => $stats + ['fields' => 6, 'posts' => 2, 'write_mismatch' => 0],
    ];
}

/**
 * @param  ArrayObject<int, mixed>  $seen
 * @param  list<array<string, mixed>>  $batches
 * @param  array<string, mixed>  $summary
 */
function cmd_backfill_engine(ArrayObject $seen, array $batches, array $summary): void
{
    $queue = new SplQueue;
    foreach ($batches as $batch) {
        $queue->enqueue($batch);
    }

    cmd_engine([
        'backfill_start' => function ($dry, $no_clear, $stamp, $allow) use ($seen): string {
            $seen[] = ['start', $dry, $no_clear, $stamp, $allow];

            return 'run-'.$stamp;
        },
        'total_posts' => function ($ids) use ($seen): int {
            $seen[] = ['total', $ids];

            return 40;
        },
        'backfill_batch' => function ($stamp, $cursor, $limit, $ids) use ($seen, $queue): array {
            $seen[] = ['batch', $stamp, $cursor, $limit, $ids];

            return $queue->dequeue();
        },
    ]);
    cmd_store(['change_summary' => function ($stamp) use ($seen, $summary): array {
        $seen[] = ['summary', $stamp];

        return $summary;
    }]);
}

it('backfills in batches of 20, surfacing batch warnings and the change table', function (): void {
    $seen = new ArrayObject;
    $by_field = [['field' => 'provider.name', 'fill' => 1, 'overwrite' => 0, 'clear' => 0]];
    when('wp_json_encode')->alias('json_encode');
    cmd_backfill_engine($seen, [
        ['cursor' => 20, 'warnings' => ['w1', 'w2'], 'processed' => 20, 'done' => false],
        ['cursor' => 40, 'warnings' => [], 'processed' => 2, 'done' => true],
    ], cmd_summary(['fields' => 6, 'posts' => 2], $by_field));

    (new Command)->backfill([], ['stamp' => 's1', 'no-clear' => true, 'allow-stale-cache' => true, 'ids' => '4,6']);

    $bar = $GLOBALS['cmd_bars'][0];

    expect($seen->getArrayCopy())->toBe([
        ['start', false, true, 's1', true],
        ['total', [4, 6]],
        ['batch', 'run-s1', 0, 20, [4, 6]],
        ['batch', 'run-s1', 20, 20, [4, 6]],
        ['summary', 'run-s1'],
    ])
        ->and($bar->message)->toBe("Backfill \u{2013} 40 posts")
        ->and($bar->count)->toBe(40)
        ->and($bar->ticks)->toBe(22)
        ->and($bar->finished)->toBeTrue()
        ->and($GLOBALS['cmd_cache_clears'])->toBe(2)
        ->and(cmd_calls('warning'))->toBe(['w1', 'w2'])
        ->and($GLOBALS['cmd_tables'])->toBe([['table', $by_field, ['field', 'fill', 'overwrite', 'clear']]])
        ->and(cmd_calls('log'))->toContain('Stats: {"fields":6,"posts":2,"write_mismatch":0}')
        ->and(cmd_calls('success'))->toBe(['Site 7: run run-s1 '."\u{2014}".' 2 posts / 6 fields backfilled.'])
        ->and(array_slice(CommandTestCli::$calls, 0, 2))->toBe(cmd_header());
});

it('prints no change table when no field changed', function (): void {
    $seen = new ArrayObject;
    when('wp_json_encode')->alias('json_encode');
    cmd_backfill_engine($seen, [['cursor' => 1, 'warnings' => [], 'processed' => 1, 'done' => true]], cmd_summary());

    (new Command)->backfill([], ['stamp' => 's1']);

    expect($GLOBALS['cmd_tables'])->toBe([])
        ->and($seen[0])->toBe(['start', false, false, 's1', false])
        ->and($seen[1])->toBe(['total', null]);
});

it('marks a dry run in the bar, the default run name and the closing line', function (): void {
    $seen = new ArrayObject;
    when('wp_json_encode')->alias('json_encode');
    cmd_backfill_engine($seen, [['cursor' => 1, 'warnings' => [], 'processed' => 1, 'done' => true]], cmd_summary());

    (new Command)->backfill([], ['dry-run' => true]);

    expect($seen[0][0])->toBe('start')
        ->and($seen[0][1])->toBeTrue()
        ->and($seen[0][3])->toMatch('/^\d{8}-\d{6}-dry$/')
        ->and($GLOBALS['cmd_bars'][0]->message)->toBe("[DRY RUN] Backfill \u{2013} 40 posts")
        ->and(cmd_calls('success'))->toBe(['[DRY RUN] Site 7: run run-'.$seen[0][3]." \u{2014} 2 posts / 6 fields would change."]);
});

it('names a real run by the time alone', function (): void {
    $seen = new ArrayObject;
    when('wp_json_encode')->alias('json_encode');
    cmd_backfill_engine($seen, [['cursor' => 1, 'warnings' => [], 'processed' => 1, 'done' => true]], cmd_summary());

    (new Command)->backfill([], []);

    expect($seen[0][3])->toMatch('/^\d{8}-\d{6}$/');
});

it('warns and halts with 1 when a write did not stick', function (): void {
    $seen = new ArrayObject;
    when('wp_json_encode')->alias('json_encode');
    cmd_backfill_engine($seen, [['cursor' => 1, 'warnings' => [], 'processed' => 1, 'done' => true]], cmd_summary(['write_mismatch' => 3]));

    expect(fn () => (new Command)->backfill([], ['stamp' => 's1']))->toThrow(RuntimeException::class, 'WP_CLI::halt: 1');

    expect(cmd_calls('warning'))->toBe(["3 write mismatch(es) \u{2014} inspect the changes for run run-s1."])
        ->and(cmd_calls('success'))->toBe([]);
});

it('turns a refused backfill into a CLI error', function (): void {
    cmd_engine(['backfill_start' => fn ($dry, $no_clear, $stamp, $allow): never => throw new Exception('stale cache')]);

    expect(fn () => (new Command)->backfill([], ['stamp' => 's1']))->toThrow(RuntimeException::class, 'WP_CLI::error: stale cache');
});

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function cmd_inventory(array $over = []): array
{
    return $over + [
        'json_dir' => '/json',
        'plugin_loaded' => true,
        'intercepts' => 3,
        'hooks' => ['acf/load_value', 'acf/update_value'],
        'core_meta' => true,
        'cache' => [],
        'cache_problems' => [],
        'tables' => [],
    ];
}

it('reports the plugin state and the table definitions directory', function (): void {
    cmd_engine(['inventory' => fn (): array => cmd_inventory()]);

    (new Command)->inventory([], []);

    expect(CommandTestCli::$calls)->toBe([
        ...cmd_header(),
        ['log', 'Table JSON dir:          /json'],
        ['log', 'Plugin loaded:           yes'],
        ['log', 'Plugin intercepts found: 3 on [acf/load_value, acf/update_value]'],
        ['log', 'Values stored in core meta: yes'],
    ]);
});

it('says so when the plugin is not loaded and the custom table is the only copy', function (): void {
    cmd_engine(['inventory' => fn (): array => cmd_inventory(['plugin_loaded' => false, 'core_meta' => false, 'intercepts' => 0, 'hooks' => []])]);

    (new Command)->inventory([], []);

    expect(cmd_calls('log'))->toContain('Plugin loaded:           no')
        ->and(cmd_calls('log'))->toContain('Plugin intercepts found: 0 on []')
        ->and(cmd_calls('log'))->toContain("Values stored in core meta: NO \u{2014} custom table is the only copy");
});

it('describes the plugin table map cache for each table, skipping the missing marker', function (): void {
    cmd_engine(['inventory' => fn (): array => cmd_inventory(['cache' => [
        '_missing' => ['cache_post_type' => 'x', 'intercepting' => true, 'not_cached' => ['z']],
        'provider' => ['cache_post_type' => 'provider', 'intercepting' => true, 'not_cached' => ['a', 'b']],
        'location' => ['cache_post_type' => '', 'intercepting' => false, 'not_cached' => ['c']],
        'clinic' => ['cache_post_type' => 'clinic', 'intercepting' => true, 'not_cached' => []],
    ], 'cache_problems' => ['problem one', 'problem two']])]);

    (new Command)->inventory([], []);

    expect(cmd_calls('log'))->toContain("Table map cache for provider: post type \"provider\" \u{2014} plugin IS reading this table; 2 mapped column(s) not in cache (served from postmeta)")
        ->and(cmd_calls('log'))->toContain("Table map cache for location: post type \"(none)\" \u{2014} plugin is NOT reading this table")
        ->and(cmd_calls('log'))->toContain("Table map cache for clinic: post type \"clinic\" \u{2014} plugin IS reading this table")
        ->and(implode("\n", cmd_calls('log')))->not->toContain('_missing')
        ->and(cmd_calls('warning'))->toBe(['problem one', 'problem two']);
});

it('prints a table that does not exist on this site and moves on', function (): void {
    cmd_engine(['inventory' => fn (): array => cmd_inventory(['tables' => [
        ['table' => 'wp_provider', 'post_type' => 'provider', 'def_file' => 'table_provider.json', 'exists' => false],
    ]])]);

    (new Command)->inventory([], []);

    expect(array_slice(CommandTestCli::$calls, -3))->toBe([
        ['log', ''],
        ['log', '%G== wp_provider%n  (post type: provider, def: table_provider.json)'],
        ['log', '   table does not exist on this site'],
    ])
        ->and($GLOBALS['cmd_tables'])->toBe([]);
});

it('goes on to the next table after one that does not exist', function (): void {
    cmd_engine(['inventory' => fn (): array => cmd_inventory(['tables' => [
        ['table' => 'wp_gone', 'post_type' => 'a', 'def_file' => 'a.json', 'exists' => false],
        ['table' => 'wp_there', 'post_type' => 'b', 'def_file' => 'b.json', 'exists' => true, 'rows' => 1, 'missing_post' => 0, 'wrong_type' => 0, 'no_row' => 0, 'extra_cols' => [], 'missing_cols' => [], 'columns' => []],
    ]])]);

    (new Command)->inventory([], []);

    expect(cmd_calls('log'))->toContain('%G== wp_there%n  (post type: b, def: b.json)');
});

it('prints the row counts, the column differences and the column table for an existing table', function (): void {
    $columns = [['column' => 'a', 'acf_name' => 'a', 'acf_type' => 'text', 'non_empty' => 3, 'note' => '']];
    cmd_engine(['inventory' => fn (): array => cmd_inventory(['tables' => [[
        'table' => 'wp_provider', 'post_type' => 'provider', 'def_file' => 'table_provider.json', 'exists' => true,
        'rows' => 10, 'missing_post' => 1, 'wrong_type' => 2, 'no_row' => 3,
        'extra_cols' => ['x1', 'x2'], 'missing_cols' => ['m1'], 'columns' => $columns,
    ]]])]);

    (new Command)->inventory([], ['format' => 'json']);

    expect(array_slice(CommandTestCli::$calls, -5))->toBe([
        ['log', ''],
        ['log', '%G== wp_provider%n  (post type: provider, def: table_provider.json)'],
        ['log', '   rows: 10 | rows whose post no longer exists: 1 | rows for other post types/revisions: 2 | provider posts with no row: 3'],
        ['log', '   columns in DB but not in JSON: x1, x2'],
        ['warning', '   columns in JSON but missing from DB: m1'],
    ])
        ->and($GLOBALS['cmd_tables'])->toBe([['json', $columns, ['column', 'acf_name', 'acf_type', 'non_empty', 'note']]]);
});

it('prints a table in the table format by default and skips empty column lists', function (): void {
    cmd_engine(['inventory' => fn (): array => cmd_inventory(['tables' => [[
        'table' => 'wp_provider', 'post_type' => 'provider', 'def_file' => 'f.json', 'exists' => true,
        'rows' => 1, 'missing_post' => 0, 'wrong_type' => 0, 'no_row' => 0,
        'extra_cols' => [], 'missing_cols' => [], 'columns' => [],
    ]]])]);

    (new Command)->inventory([], []);

    expect($GLOBALS['cmd_tables'][0][0])->toBe('table')
        ->and(cmd_calls('warning'))->toBe([])
        ->and(implode("\n", cmd_calls('log')))->not->toContain('columns in DB but not in JSON');
});

/**
 * A network of sites for `--network`: each entry is `[blog_id, domain, path, [tables it has]]`. The `$wpdb` mock
 * answers `SHOW TABLES LIKE` from that list, and the Site reports the given table definitions.
 *
 * @param  list<array{0: int, 1: string, 2: string, 3: list<string>}>  $sites
 * @param  list<string>  $table_names
 */
function cmd_network(array $sites, array $table_names = ['provider']): MockInterface
{
    when('is_multisite')->justReturn(true);
    $GLOBALS['cmd_sites_args'] = [];
    $GLOBALS['cmd_var_calls'] = 0;
    when('get_sites')->alias(function (array $args) use ($sites): array {
        $GLOBALS['cmd_sites_args'][] = $args;

        return array_map(fn (array $s): object => (object) ['blog_id' => $s[0], 'domain' => $s[1], 'path' => $s[2]], $sites);
    });
    redefine(Site::class.'::table_defs', fn (): array => array_fill_keys($table_names, []));

    $has = [];
    foreach ($sites as $s) {
        foreach ($s[3] as $table) {
            $has['wp_'.$s[0].'_'.$table] = true;
        }
    }

    $wpdb = Mockery::mock('wpdb');
    $wpdb->shouldReceive('get_blog_prefix')->andReturnUsing(fn (int $id): string => 'wp_'.$id.'_');
    $wpdb->shouldReceive('esc_like')->andReturnUsing(fn (string $s): string => $s);
    $wpdb->shouldReceive('prepare')->andReturnUsing(fn (string $q, string $v): string => $q.'|'.$v);
    $wpdb->shouldReceive('get_var')->andReturnUsing(function (string $q) use ($has): ?string {
        $GLOBALS['cmd_var_calls']++;
        [$sql, $name] = explode('|', $q);

        // LIKE: `_` stands for any one character, so a table can match a name it is not equal to.
        $pattern = '/^'.str_replace('_', '.', preg_quote($name, '/')).'$/';
        foreach (array_keys($has) as $table) {
            if ($sql === 'SHOW TABLES LIKE %s' && preg_match($pattern, $table)) {
                return $table;
            }
        }

        return null;
    });
    $GLOBALS['wpdb'] = $wpdb;

    return $wpdb;
}

/**
 * Replaces `run_child()` and records `[command, args, assoc, url]` for each call; `$codes` maps a URL to its exit
 * code (default 0).
 *
 * @param  ArrayObject<int, mixed>  $seen
 * @param  array<string, int>  $codes
 */
function cmd_children(ArrayObject $seen, array $codes = []): void
{
    redefine(Command::class.'::run_child', function ($command, $args, $assoc, $url) use ($seen, $codes): int {
        $seen[] = [$command, $args, $assoc, $url];

        return $codes[$url] ?? 0;
    });
}

it('runs on this site alone when --network is not set', function (): void {
    cmd_store(['headers' => fn ($kind): array => []]);
    Functions\expect('is_multisite')->never();

    (new Command)->status([], []);

    expect(cmd_calls('log'))->toContain('   nothing stored yet');
});

it('runs on this site alone when --network is set on a single site install', function (): void {
    cmd_store(['headers' => fn ($kind): array => []]);
    when('is_multisite')->justReturn(false);

    (new Command)->status([], ['network' => true]);

    expect(cmd_calls('log'))->toContain('   nothing stored yet');
});

it('stops when no table definition can be found for a network run', function (): void {
    cmd_network([[1, 'a.test', '/', ['provider']]], []);

    expect(fn () => (new Command)->status([], ['network' => true]))
        ->toThrow(RuntimeException::class, 'WP_CLI::error: No table definition JSON files found (is ACFCDT_JSON_DIR defined? try --json-dir).');
});

it('hands the json-dir override to the Site that lists the table definitions', function (): void {
    $given = new ArrayObject;
    cmd_network([], ['provider']);
    redefine(Site::class.'::__construct', function ($override = null) use ($given): void {
        $given[] = $override;
    });

    (new Command)->status([], ['network' => true, 'json-dir' => '/custom']);

    expect($given->getArrayCopy())->toBe(['/custom']);
});

it('runs the subcommand once per site with custom tables and skips the rest', function (): void {
    $seen = new ArrayObject;
    cmd_network([
        [1, 'a.test', '/', ['provider']],
        [2, 'b.test', '/blog/', []],
        [3, 'c.test', '/', ['other', 'provider']],
    ], ['provider']);
    cmd_children($seen);

    (new Command)->status(['x'], ['network' => true, 'json-dir' => '/j']);

    expect($GLOBALS['cmd_sites_args'])->toBe([['number' => 0, 'deleted' => 0]])
        ->and($seen->getArrayCopy())->toBe([
            ['acfcdt-migrate status', ['x'], ['json-dir' => '/j', 'require' => UAMSWP_ACFCDT_MIGRATOR_FILE], 'a.test/'],
            ['acfcdt-migrate status', ['x'], ['json-dir' => '/j', 'require' => UAMSWP_ACFCDT_MIGRATOR_FILE], 'c.test/'],
        ])
        ->and(CommandTestCli::$calls)->toBe([
            ['log', 'Network status: 2 site(s) with custom tables; 1 without (skipped).'],
            ['log', ''],
            ['success', 'Network status finished on 2 site(s).'],
        ]);
});

it("stops looking for a site's tables at the first one it has", function (): void {
    $seen = new ArrayObject;
    cmd_network([[1, 'a.test', '/', ['provider', 'location']]], ['provider', 'location']);
    cmd_children($seen);

    (new Command)->status([], ['network' => true]);

    expect($seen->count())->toBe(1)
        ->and($GLOBALS['cmd_var_calls'])->toBe(1);
});

it('looks past a table a site lacks to the next one', function (): void {
    $seen = new ArrayObject;
    cmd_network([[1, 'a.test', '/', ['location']]], ['provider', 'location']);
    cmd_children($seen);

    (new Command)->status([], ['network' => true]);

    expect($seen->count())->toBe(1)
        ->and(cmd_calls('log')[0])->toBe('Network status: 1 site(s) with custom tables; 0 without (skipped).');
});

it('does not count a table that only matches the name through the LIKE wildcard', function (): void {
    $seen = new ArrayObject;
    cmd_network([[1, 'a.test', '/', ['provXder']]], ['prov_der']);
    cmd_children($seen);

    (new Command)->status([], ['network' => true]);

    expect($seen->count())->toBe(0)
        ->and(cmd_calls('log')[0])->toBe('Network status: 0 site(s) with custom tables; 1 without (skipped).');
});

it('passes the plugin skip lists on to the child processes', function (array $config, array $expected): void {
    $seen = new ArrayObject;
    cmd_network([[1, 'a.test', '/', ['provider']]]);
    cmd_children($seen);
    CommandTestCli::$runner = (object) ['config' => $config];

    (new Command)->status([], ['network' => true]);

    expect($seen[0][2])->toBe(['require' => UAMSWP_ACFCDT_MIGRATOR_FILE] + $expected);
})->with([
    'none' => [[], []],
    'empty values' => [['skip-plugins' => '', 'skip-themes' => []], []],
    'an array and a string' => [['skip-plugins' => ['a', 'b'], 'skip-themes' => 'twentytwenty'], ['skip-plugins' => 'a,b', 'skip-themes' => 'twentytwenty']],
    'a string only' => [['skip-plugins' => 'a'], ['skip-plugins' => 'a']],
]);

it('warns, halts with 1 and names the sites whose child failed', function (): void {
    $seen = new ArrayObject;
    cmd_network([
        [1, 'a.test', '/', ['provider']],
        [2, 'b.test', '/blog/', ['provider']],
        [3, 'c.test', '/', ['provider']],
    ]);
    cmd_children($seen, ['a.test/' => 2, 'c.test/' => 1]);

    expect(fn () => (new Command)->status([], ['network' => true]))->toThrow(RuntimeException::class, 'WP_CLI::halt: 1');

    expect(cmd_calls('warning'))->toBe(['Non-zero exit on: 1 (a.test/), 3 (c.test/)'])
        ->and(cmd_calls('success'))->toBe([])
        ->and($seen->count())->toBe(3);
});

/**
 * Configures the process shims (tests/Unit/Fixtures/process-shims.php) and the WP-CLI helpers `run_child()` calls.
 *
 * @param  resource|false  $process
 */
function cmd_process(mixed $process, int $exit = 0, string|false $env = false): void
{
    CommandTestProcess::reset();
    CommandTestProcess::$handle = $process;
    CommandTestProcess::$exit = $exit;
    CommandTestProcess::$env = $env;

    when('WP_CLI\Utils\get_php_binary')->justReturn('/usr/bin/php');
    when('WP_CLI\Utils\get_home_dir')->justReturn('/home/me');
    when('WP_CLI\Utils\assoc_args_to_str')->alias(fn (array $a): string => '--assoc='.json_encode($a));
}

/** How many times the close shim was called. */
function cmd_closes(): int
{
    return count(array_filter(CommandTestProcess::$calls, fn (array $c): bool => $c[0] === 'fclose'));
}

/**
 * The call to the process shim named `$name`, as recorded.
 *
 * @return array<int, mixed>|null
 */
function cmd_process_call(string $name): ?array
{
    foreach (CommandTestProcess::$calls as $call) {
        if ($call[0] === $name) {
            return $call;
        }
    }

    return null;
}

it('launches the child process with the config path, php, the script, the subcommand and the options', function (): void {
    cmd_network([[1, 'a.test', '/', ['provider']]]);
    cmd_process(fopen('php://memory', 'r'));
    $argv = $GLOBALS['argv'] ?? null;
    $GLOBALS['argv'] = ['/usr/local/bin/wp'];
    CommandTestCli::$runner = (object) ['config' => ['path' => '/var/www', 'user' => 'admin', 'allow-root' => true]];

    (new Command)->status(['one', 'two words'], ['network' => true]);

    $GLOBALS['argv'] = $argv;

    $assoc = ['require' => UAMSWP_ACFCDT_MIGRATOR_FILE, 'path' => '/var/www', 'user' => 'admin', 'allow-root' => true, 'url' => 'a.test/'];
    $expected = sprintf(
        'WP_CLI_CONFIG_PATH=%s %s %s %s %s %s',
        escapeshellarg('/home/me/.wp-cli/config.yml'),
        escapeshellarg('/usr/bin/php'),
        escapeshellarg('/usr/local/bin/wp'),
        'acfcdt-migrate status',
        escapeshellarg('one').' '.escapeshellarg('two words'),
        '--assoc='.json_encode($assoc)
    );

    expect(cmd_process_call('getenv'))->toBe(['getenv', 'WP_CLI_CONFIG_PATH'])
        ->and(cmd_process_call('open'))->toBe(['open', $expected, [STDIN, STDOUT, STDERR]])
        ->and(cmd_process_call('close'))->toBe(['close']);
});

it('uses the config path from the environment when there is one', function (): void {
    cmd_network([[1, 'a.test', '/', ['provider']]]);
    cmd_process(fopen('php://memory', 'r'), 0, '/etc/wp.yml');

    (new Command)->status([], ['network' => true]);

    expect(cmd_process_call('open')[1])->toStartWith('WP_CLI_CONFIG_PATH='.escapeshellarg('/etc/wp.yml').' ');
});

it('keeps an option the caller already gave over the one in the WP-CLI config', function (): void {
    cmd_network([[1, 'a.test', '/', ['provider']]]);
    cmd_process(fopen('php://memory', 'r'));
    CommandTestCli::$runner = (object) ['config' => ['path' => '/from-config', 'user' => '', 'allow-root' => false]];

    (new Command)->status([], ['network' => true, 'path' => '/from-caller']);

    expect(cmd_process_call('open')[1])->toContain('--assoc='.json_encode(['path' => '/from-caller', 'require' => UAMSWP_ACFCDT_MIGRATOR_FILE, 'url' => 'a.test/']));
});

it('returns the exit code of the child process', function (): void {
    cmd_network([[1, 'a.test', '/', ['provider']]]);
    cmd_process(fopen('php://memory', 'r'), 4);

    expect(fn () => (new Command)->status([], ['network' => true]))->toThrow(RuntimeException::class, 'WP_CLI::halt: 1');

    expect(cmd_calls('warning'))->toBe(['Non-zero exit on: 1 (a.test/)']);
});

it('counts a child that could not be started as a failure', function (): void {
    cmd_network([[1, 'a.test', '/', ['provider']]]);
    cmd_process(false);

    expect(fn () => (new Command)->status([], ['network' => true]))->toThrow(RuntimeException::class, 'WP_CLI::halt: 1');

    expect(cmd_calls('warning')[0])->toStartWith('Could not start: WP_CLI_CONFIG_PATH=')
        ->and(cmd_calls('warning')[1])->toBe('Non-zero exit on: 1 (a.test/)')
        ->and(cmd_process_call('close'))->toBeNull();
});

it('returns 1 from a child that could not be started', function (): void {
    cmd_process(false);

    $run = new ReflectionMethod(Command::class, 'run_child');

    expect($run->invoke(new Command, 'acfcdt-migrate status', [], [], 'a.test/'))->toBe(1);
});

/**
 * Everything `archive()` touches, mocked: an output directory under the system temp directory, the Site's table
 * definitions, and a `$wpdb` that answers by SQL. Returns the directory and the list of queries it was asked.
 *
 * `$tables` is `[table name => ['columns' => [...], 'batches' => [sql => rows], 'orphans' => rows]]`; `$defs` is the
 * Site's active definitions.
 *
 * @param  list<array<string, mixed>>  $defs
 * @param  array<string, array<string, mixed>>  $tables
 * @param  list<string>  $acf_fields  identifiers `acf_get_field()` knows (or all of them, with `$acf_knows_everything`)
 * @return array{dir: string, queries: ArrayObject<int, mixed>, out_dir_args: ArrayObject<int, mixed>}
 */
function cmd_archive(array $defs, array $tables, array $acf_fields = [], bool $acf_knows_everything = false): array
{
    $dir = sys_get_temp_dir().'/acfcdt-command-test-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $GLOBALS['cmd_dirs'][] = $dir;

    $queries = new ArrayObject;
    $out_dir_args = new ArrayObject;

    redefine(Site::class.'::out_dir', function ($override = null, $sub = '') use ($dir, $out_dir_args): string {
        $out_dir_args[] = [$override, $sub];

        return $dir;
    });
    redefine(Site::class.'::active_defs', fn (): array => $defs);
    redefine(Site::class.'::prefixed', fn ($name): string => 'wp_'.$name);

    when('sanitize_file_name')->alias(fn ($s): string => 'clean-'.$s);
    when('wp_json_encode')->alias('json_encode');
    when('esc_sql')->alias(fn ($s): string => 'e_'.$s);
    when('acf_get_field')->alias(fn ($ident): array|false => $acf_knows_everything || in_array($ident, $acf_fields, true) ? ['key' => $ident] : false);

    $wpdb = Mockery::mock('wpdb');
    $wpdb->shouldReceive('prepare')->andReturnUsing(fn (string $q, ...$args): string => $q.'|'.json_encode($args));
    $wpdb->shouldReceive('get_col')->andReturnUsing(function (string $sql) use ($tables, $queries): array {
        $queries[] = $sql;
        $table = trim(explode('`', $sql)[1]);

        return $tables[$table]['columns'];
    });
    $wpdb->shouldReceive('get_results')->andReturnUsing(function (string $sql, string $output) use ($tables, $queries): array {
        // A paging loop that never advances fails here instead of hanging a mutation run.
        if (count($queries) > 50) {
            throw new LogicException('More than 50 queries: the archive is not paging.');
        }

        $queries[] = [$sql, $output];
        foreach ($tables as $t) {
            if (isset($t['results'][$sql])) {
                return $t['results'][$sql];
            }
        }

        throw new LogicException("Unexpected query: $sql");
    });
    $GLOBALS['wpdb'] = $wpdb;

    return ['dir' => $dir, 'queries' => $queries, 'out_dir_args' => $out_dir_args];
}

/**
 * @param  list<array{0: string, 1: string}>  $columns
 * @return array<string, mixed>
 */
function cmd_def(string $name, array $columns): array
{
    return ['name' => $name, 'columns' => array_map(fn (array $c): array => ['name' => $c[0], 'identifier' => $c[1]], $columns)];
}

it('archives a table to JSON lines, its orphaned columns to CSV, and a manifest', function (): void {
    $rows = [['id' => '1', 'post_id' => '10', 'col_a' => 'x'], ['id' => '2', 'post_id' => '11', 'col_a' => 'caf'."\u{e9}".'/']];
    $orphan_sql = "SELECT `e_post_id`,`e_col_b`,`e_col_c` FROM `wp_provider` WHERE (`e_col_b` IS NOT NULL AND `e_col_b` <> '' AND `e_col_b` <> '[]') OR (`e_col_c` IS NOT NULL AND `e_col_c` <> '' AND `e_col_c` <> '[]') ORDER BY post_id";
    $env = cmd_archive(
        [cmd_def('provider', [['col_a', 'field_a'], ['col_b', 'field_b']])],
        ['wp_provider' => [
            'columns' => ['id', 'post_id', 'col_a', 'col_b', 'col_c'],
            'results' => [
                'SELECT * FROM `wp_provider` WHERE id > %d ORDER BY id ASC LIMIT 500|[0]' => $rows,
                $orphan_sql => [['post_id' => '10', 'col_b' => 'orphan, "quoted"', 'col_c' => '']],
            ],
        ]],
        ['field_a']
    );

    (new Command)->archive([], ['stamp' => 'run1', 'dir' => '/base']);

    $dir = $env['dir'];
    $jsonl = "$dir/site-7-wp_provider.jsonl";
    $csv = "$dir/site-7-wp_provider-orphan-columns.csv";
    $manifest = json_decode((string) file_get_contents("$dir/site-7-manifest.json"), true);

    expect(cmd_closes())->toBe(2)
        ->and($env['out_dir_args']->getArrayCopy())->toBe([['/base', 'archive/clean-run1']])
        ->and(file_get_contents($jsonl))->toBe(
            '{"id":"1","post_id":"10","col_a":"x"}'."\n".'{"id":"2","post_id":"11","col_a":"caf'."\u{e9}".'/"}'."\n"
        )
        ->and(file_get_contents($csv))->toBe("post_id,col_b,col_c\n10,\"orphan, \"\"quoted\"\"\",\n")
        ->and(array_keys($manifest))->toBe(['blog_id', 'url', 'time', 'tables'])
        ->and($manifest['blog_id'])->toBe(7)
        ->and($manifest['url'])->toBe('https://example.test/')
        ->and($manifest['time'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and($manifest['tables'])->toBe([[
            'table' => 'wp_provider',
            'rows' => 2,
            'file' => 'site-7-wp_provider.jsonl',
            'sha256' => hash_file('sha256', $jsonl),
        ]])
        ->and(file_get_contents("$dir/site-7-manifest.json"))->toContain("{\n    \"blog_id\": 7")
        ->and(CommandTestCli::$calls)->toBe([
            ...cmd_header(),
            ['log', "   wp_provider: orphaned columns [col_b, col_c] \u{2014} 1 rows with data \u{2192} site-7-wp_provider-orphan-columns.csv"],
            ['log', "   wp_provider: 2 rows \u{2192} $jsonl"],
            ['log', '   Also take a SQL dump of these tables: wp db export --tables=wp_provider acfcdt-site-7.sql'],
            ['success', 'Archived site 7.'],
        ])
        ->and($env['queries']->getArrayCopy())->toBe([
            'SHOW COLUMNS FROM `wp_provider`',
            ['SELECT * FROM `wp_provider` WHERE id > %d ORDER BY id ASC LIMIT 500|[0]', ARRAY_A],
            'SHOW COLUMNS FROM `wp_provider`',
            [$orphan_sql, ARRAY_A],
        ]);
});

it('does not escape slashes or unicode in the JSON lines', function (): void {
    $env = cmd_archive(
        [cmd_def('provider', [['col_a', 'field_a']])],
        ['wp_provider' => ['columns' => ['id', 'col_a'], 'results' => [
            'SELECT * FROM `wp_provider` WHERE id > %d ORDER BY id ASC LIMIT 500|[0]' => [['id' => '1', 'col_a' => "a/b \u{e9}"]],
        ]]],
        ['field_a']
    );

    (new Command)->archive([], ['stamp' => 's']);

    expect(file_get_contents($env['dir'].'/site-7-wp_provider.jsonl'))->toBe('{"id":"1","col_a":"a/b '."\u{e9}".'"}'."\n");
});

it('pages a table with an id by the last id it read', function (): void {
    $first = array_map(fn (int $i): array => ['id' => (string) $i, 'col_a' => 'v'], range(1, 500));
    $env = cmd_archive(
        [cmd_def('provider', [['col_a', 'field_a']])],
        ['wp_provider' => ['columns' => ['id', 'col_a'], 'results' => [
            'SELECT * FROM `wp_provider` WHERE id > %d ORDER BY id ASC LIMIT 500|[0]' => $first,
            'SELECT * FROM `wp_provider` WHERE id > %d ORDER BY id ASC LIMIT 500|[500]' => [['id' => '501', 'col_a' => 'v']],
        ]]],
        ['field_a']
    );

    (new Command)->archive([], ['stamp' => 's']);

    $manifest = json_decode((string) file_get_contents($env['dir'].'/site-7-manifest.json'), true);

    expect($manifest['tables'][0]['rows'])->toBe(501)
        ->and(file($env['dir'].'/site-7-wp_provider.jsonl'))->toHaveCount(501);
});

it('pages a table with no id by offset', function (): void {
    $first = array_map(fn (int $i): array => ['post_id' => (string) $i, 'col_a' => 'v'], range(1, 500));
    $env = cmd_archive(
        [cmd_def('provider', [['col_a', 'field_a']])],
        ['wp_provider' => ['columns' => ['post_id', 'col_a'], 'results' => [
            'SELECT * FROM `wp_provider` LIMIT 500 OFFSET 0' => $first,
            'SELECT * FROM `wp_provider` LIMIT 500 OFFSET 500' => [['post_id' => '501', 'col_a' => 'v']],
        ]]],
        ['field_a']
    );

    (new Command)->archive([], ['stamp' => 's']);

    expect(array_slice($env['queries']->getArrayCopy(), 1))->toBe([
        ['SELECT * FROM `wp_provider` LIMIT 500 OFFSET 0', ARRAY_A],
        ['SELECT * FROM `wp_provider` LIMIT 500 OFFSET 500', ARRAY_A],
        'SHOW COLUMNS FROM `wp_provider`',
    ])
        ->and(file($env['dir'].'/site-7-wp_provider.jsonl'))->toHaveCount(501);
});

it('writes no orphan file when every column is mapped to an ACF field or is a key column', function (): void {
    $env = cmd_archive(
        [cmd_def('provider', [['col_a', 'field_a']])],
        ['wp_provider' => ['columns' => ['id', 'post_id', 'col_a'], 'results' => [
            'SELECT * FROM `wp_provider` WHERE id > %d ORDER BY id ASC LIMIT 500|[0]' => [],
        ]]],
        ['field_a']
    );

    (new Command)->archive([], ['stamp' => 's']);

    expect(cmd_closes())->toBe(1)
        ->and(glob($env['dir'].'/*orphan*'))->toBe([])
        ->and(cmd_calls('log'))->toBe([
            '',
            '%Y### Site 7: https://example.test/%n',
            "   wp_provider: 0 rows \u{2192} ".$env['dir'].'/site-7-wp_provider.jsonl',
            '   Also take a SQL dump of these tables: wp db export --tables=wp_provider acfcdt-site-7.sql',
        ]);
});

it('treats a column mapped to an identifier with no ACF field as an orphan', function (): void {
    $sql = "SELECT `e_post_id`,`e_col_b` FROM `wp_provider` WHERE (`e_col_b` IS NOT NULL AND `e_col_b` <> '' AND `e_col_b` <> '[]') ORDER BY post_id";
    $env = cmd_archive(
        [cmd_def('provider', [['col_a', 'field_a'], ['col_b', 'field_gone'], ['col_blank', '']])],
        ['wp_provider' => ['columns' => ['id', 'post_id', 'col_a', 'col_b'], 'results' => [
            'SELECT * FROM `wp_provider` WHERE id > %d ORDER BY id ASC LIMIT 500|[0]' => [],
            $sql => [],
        ]]],
        ['field_a']
    );

    (new Command)->archive([], ['stamp' => 's']);

    expect(file_get_contents($env['dir'].'/site-7-wp_provider-orphan-columns.csv'))->toBe("post_id,col_b\n")
        ->and(cmd_calls('log')[2])->toBe("   wp_provider: orphaned columns [col_b] \u{2014} 0 rows with data \u{2192} site-7-wp_provider-orphan-columns.csv");
});

it('counts a column with no mapped identifier as an orphan even when ACF would answer for any name', function (): void {
    $sql = "SELECT `e_post_id`,`e_col_c` FROM `wp_provider` WHERE (`e_col_c` IS NOT NULL AND `e_col_c` <> '' AND `e_col_c` <> '[]') ORDER BY post_id";
    cmd_archive(
        [cmd_def('provider', [['col_a', 'field_a']])],
        ['wp_provider' => ['columns' => ['id', 'col_a', 'col_c'], 'results' => [
            'SELECT * FROM `wp_provider` WHERE id > %d ORDER BY id ASC LIMIT 500|[0]' => [],
            $sql => [],
        ]]],
        [],
        true
    );

    (new Command)->archive([], ['stamp' => 's']);

    expect(cmd_calls('log')[2])->toBe("   wp_provider: orphaned columns [col_c] \u{2014} 0 rows with data \u{2192} site-7-wp_provider-orphan-columns.csv");
});

it('lists every archived table in the manifest and the dump command', function (): void {
    $env = cmd_archive(
        [cmd_def('provider', [['a', 'fa']]), cmd_def('location', [['b', 'fb']])],
        [
            'wp_provider' => ['columns' => ['id', 'a'], 'results' => ['SELECT * FROM `wp_provider` WHERE id > %d ORDER BY id ASC LIMIT 500|[0]' => [['id' => '1', 'a' => 'x']]]],
            'wp_location' => ['columns' => ['id', 'b'], 'results' => ['SELECT * FROM `wp_location` WHERE id > %d ORDER BY id ASC LIMIT 500|[0]' => []]],
        ],
        ['fa', 'fb']
    );

    (new Command)->archive([], ['stamp' => 's']);

    $manifest = json_decode((string) file_get_contents($env['dir'].'/site-7-manifest.json'), true);

    expect(array_column($manifest['tables'], 'table'))->toBe(['wp_provider', 'wp_location'])
        ->and(array_column($manifest['tables'], 'rows'))->toBe([1, 0])
        ->and(cmd_calls('log'))->toContain('   Also take a SQL dump of these tables: wp db export --tables=wp_provider,wp_location acfcdt-site-7.sql');
});

it('writes an empty manifest and no dump command when no table is active', function (): void {
    $env = cmd_archive([], []);

    (new Command)->archive([], ['stamp' => 's']);

    $manifest = json_decode((string) file_get_contents($env['dir'].'/site-7-manifest.json'), true);

    expect($manifest['tables'])->toBe([])
        ->and(CommandTestCli::$calls)->toBe([...cmd_header(), ['success', 'Archived site 7.']]);
});

it('names the archive folder by the time when no stamp is given', function (): void {
    $env = cmd_archive([], []);

    (new Command)->archive([], []);

    expect($env['out_dir_args'][0][0])->toBeNull()
        ->and($env['out_dir_args'][0][1])->toMatch('/^archive\/clean-\d{8}-\d{6}$/');
});

it('shares one stamp with the child processes of a network archive', function (): void {
    $seen = new ArrayObject;
    cmd_network([[1, 'a.test', '/', ['provider']]]);
    cmd_children($seen);

    (new Command)->archive([], ['network' => true]);

    expect($seen[0][0])->toBe('acfcdt-migrate archive')
        ->and($seen[0][2]['stamp'])->toMatch('/^\d{8}-\d{6}$/');
});

it('hands the json-dir override to the Site it archives', function (): void {
    $given = new ArrayObject;
    cmd_archive([], []);
    redefine(Site::class.'::__construct', function ($override = null) use ($given): void {
        $given[] = $override;
    });

    (new Command)->archive([], ['stamp' => 's', 'json-dir' => '/custom']);

    expect($given->getArrayCopy())->toBe(['/custom']);
});

it('hands every data subcommand to the child processes under --network, and does nothing else', function (string $sub, array $args): void {
    $seen = new ArrayObject;
    cmd_network([[1, 'a.test', '/', ['provider']]]);
    cmd_children($seen);
    // Nothing below the network branch may run: the Engine is never built.
    redefine(Engine::class.'::acf_ready', fn (): never => throw new LogicException('The Engine was built.'));

    (new Command)->{$sub}($args, ['network' => true]);

    expect($seen->count())->toBe(1)
        ->and($seen[0][0])->toBe('acfcdt-migrate '.$sub)
        ->and($seen[0][1])->toBe($args)
        ->and(cmd_calls('success'))->toBe(["Network $sub finished on 1 site(s)."]);
})->with([
    'inventory' => ['inventory', []],
    'snapshot' => ['snapshot', ['before']],
    'compare' => ['compare', ['a', 'b']],
    'backfill' => ['backfill', []],
    'rollback' => ['rollback', ['run-1']],
    'status' => ['status', []],
]);
