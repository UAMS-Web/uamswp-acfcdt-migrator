<?php

/**
 * Engine::backfill_batch(), the write step: what is backed up before a field is written, how the value reaches ACF,
 * and the postmeta rows ACF rewrites on OTHER posts as a side effect of that write.
 *
 * The side effect is the subject of the regression tests: ACF Extended's bidirectional relationships answer a write on
 * one post by rewriting the related post's value and its `_<field>` reference row (to the related field's key, which can
 * differ from the one stored). A rollback that restores only the rows the written field owns leaves the related post's
 * reference changed. The stand-in for `acf_update_value()` below does what ACF Extended does: it fires the postmeta
 * filters for the related post and changes its rows, and the tests read the backup rows the engine recorded first.
 * Whether a rollback then restores them is the Integration suite's; the same sequence was reproduced end to end
 * against a copy of the rehearsal database (22 posts, 99 fields): the ordered `wp_postmeta` dump differed in 2 rows
 * after a rollback before the fix and in none after it.
 *
 * Mutation run: `vendor/bin/pest tests/Unit/EngineBackfillWriteTest.php --mutate --path=includes
 * --class='UAMSWP_ACFCDT_Migrator\Engine' --covered-only` (macOS, PHP 8.4.27, PCOV; a working run): no survivor in
 * `write_with_trail()`, the one annotation on the call (the `?? ''` default is never used, because every field has an
 * effective value by then). The survivors outside that method belong to the rest of `backfill_batch()`.
 *
 * The rest of `backfill_batch()` (dry runs, clearing, invalid values, the run header) belongs to the backfill test
 * file of the coverage ticket; this file covers the write step only, and `Store`, `Site` and `HookGuard` are
 * redefined with Patchwork. Helper names carry the `eng_bw` prefix.
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
    $GLOBALS['eng_bw_state'] = new ArrayObject([
        'puts' => [],
        'mode' => 'on',
        'meta' => [],
        'hooks' => [],
        'cleaned' => [],
        'updates' => [],
        'added' => [],
        'removed' => [],
        'after' => null,
        'side_effect' => null,
        'throw' => false,
    ]);

    $state = $GLOBALS['eng_bw_state'];

    redefine(Store::class.'::install', fn (): null => null);
    redefine(Store::class.'::get', fn ($kind, $ref, $post_id = 0, $field = ''): ?array => $kind === 'run' ? ['dry' => false, 'no_clear' => false] : null);
    redefine(Store::class.'::put', function ($kind, $ref, $post_id, $field, $data, $mode = 'replace') use ($state): int {
        $puts = $state['puts'];
        $puts[] = [$kind, $ref, $post_id, $field, $data, $mode];
        $state['puts'] = $puts;

        return 1;
    });
    redefine(Store::class.'::change_summary', fn ($stamp): array => ['stats' => [], 'by_field' => []]);

    redefine(HookGuard::class.'::__construct', fn (): null => null);
    redefine(HookGuard::class.'::has_intercepts', fn (): bool => true);
    redefine(HookGuard::class.'::attach', function () use ($state): void {
        $state['mode'] = 'on';
    });
    redefine(HookGuard::class.'::detach', function () use ($state): void {
        $state['mode'] = 'off';
    });
    redefine(HookGuard::class.'::reset_caches', fn (): null => null);
    redefine(HookGuard::class.'::flush_acf_values', fn (): null => null);

    redefine(Site::class.'::post_types', fn (): array => ['expertise']);
    redefine(Site::class.'::meta_rows_for_field', function ($post_id, $name, $complex) use ($state): array {
        $rows = [];
        foreach ($state['meta'] as $key => $value) {
            [$pid, $meta_key] = explode(':', (string) $key, 2);
            if ((int) $pid === (int) $post_id && ($meta_key === $name || $meta_key === '_'.$name || ($complex && str_starts_with(ltrim($meta_key, '_'), $name.'_')))) {
                $rows[] = [$meta_key, $value];
            }
        }

        return $rows;
    });

    when('get_current_blog_id')->justReturn(5);
    when('home_url')->alias(fn (string $path = ''): string => 'https://example.test'.$path);
    when('wp_slash')->alias(fn (mixed $v): string => 'slashed('.$v.')');
    when('wp_cache_delete')->justReturn(true);
    when('clean_post_cache')->alias(function (int $id) use ($state): void {
        $cleaned = $state['cleaned'];
        $cleaned[] = $id;
        $state['cleaned'] = $cleaned;
    });
    when('add_filter')->alias(function (string $hook, callable $cb, int $priority = 10, int $args = 1) use ($state): bool {
        $hooks = $state['hooks'];
        $hooks[$hook][] = $cb;
        $state['hooks'] = $hooks;
        $added = $state['added'];
        $added[$hook] = [$priority, $args];
        $state['added'] = $added;

        return true;
    });
    when('remove_filter')->alias(function (string $hook, callable $cb, int $priority = 10) use ($state): bool {
        $removed = $state['removed'];
        $removed[$hook] = $priority;
        $state['removed'] = $removed;
        $hooks = $state['hooks'];
        $hooks[$hook] = array_values(array_filter($hooks[$hook] ?? [], fn ($c): bool => $c !== $cb));
        $state['hooks'] = $hooks;

        return true;
    });
    when('acf_get_value')->alias(function (int $post_id, array $field) use ($state): mixed {
        if ($state['mode'] === 'on') {
            return 'new';
        }

        if ($state['after'] !== null && $state['updates'] !== []) {
            return $state['after'];
        }

        return $state['meta'][$post_id.':'.$field['name']] ?? null;
    });
    when('acf_update_value')->alias(function (mixed $value, int $post_id, array $field) use ($state): bool {
        $updates = $state['updates'];
        $updates[] = [$value, $post_id, $field['name']];
        $state['updates'] = $updates;
        $meta = $state['meta'];
        $meta[$post_id.':'.$field['name']] = preg_replace('/^slashed\((.*)\)$/', '$1', (string) $value);
        $state['meta'] = $meta;

        if ($state['side_effect'] !== null) {
            ($state['side_effect'])($state);
        }

        if ($state['throw']) {
            throw new RuntimeException('write failed');
        }

        return true;
    });
});

afterEach(function (): void {
    unset($GLOBALS['eng_bw_state'], $GLOBALS['wpdb']);
});

/**
 * Runs one batch over one post holding the given field, whose current postmeta value is `old`.
 *
 * @param  array<string, mixed>  $meta  `"<post>:<meta_key>" => value` rows the stand-in postmeta holds
 * @param  array<string, mixed>  $field
 */
function eng_bw_run(array $meta, array $field = ['name' => 'physician_expertise', 'key' => 'field_expertise_physicians', 'type' => 'text'], int $post_id = 55): array
{
    $state = $GLOBALS['eng_bw_state'];
    $state['meta'] = $meta;

    redefine(Site::class.'::fields_for_post', fn ($id, $type): array => [$field['name'] => $field]);

    /** @var wpdb&MockInterface $wpdb */
    $wpdb = Mockery::mock('wpdb');
    $wpdb->posts = 'wpx_posts';
    $wpdb->shouldReceive('prepare')->andReturnUsing(fn (string $q, ...$args): string => $q);
    $wpdb->shouldReceive('get_results')->andReturn([(object) ['ID' => (string) $post_id, 'post_type' => 'expertise', 'post_status' => 'publish', 'post_title' => 'Cancer Care', 'post_modified' => '2026-01-01 00:00:00']]);
    $GLOBALS['wpdb'] = $wpdb;

    return (new Engine)->backfill_batch('r1', 0, 10);
}

/** The recorded `Store::put()` calls of one kind. */
function eng_bw_puts(string $kind): array
{
    return array_values(array_filter($GLOBALS['eng_bw_state']['puts'], fn (array $p): bool => $p[0] === $kind));
}

// the write step

it("backs up the field's own rows, then writes the effective value slashed", function (): void {
    $result = eng_bw_run(['55:physician_expertise' => 'old', '55:_physician_expertise' => 'field_physician_expertise', '55:other' => 'x']);

    expect($result['written'])->toBe(1)
        ->and($result['mismatches'])->toBe(0)
        ->and($GLOBALS['eng_bw_state']['updates'])->toBe([['slashed(new)', 55, 'physician_expertise']])
        ->and(eng_bw_puts('backup'))->toBe([[
            'backup', 'r1', 55, 'physician_expertise',
            ['post_id' => 55, 'field' => 'physician_expertise', 'complex' => false, 'rows' => [['physician_expertise', 'old'], ['_physician_expertise', 'field_physician_expertise']]],
            'ignore',
        ]])
        ->and($GLOBALS['eng_bw_state']['cleaned'])->toBe([55]);
});

it('does not write a field whose value is unchanged', function (): void {
    $result = eng_bw_run(['55:physician_expertise' => 'new']);

    expect($result['written'])->toBe(0)
        ->and($GLOBALS['eng_bw_state']['updates'])->toBe([])
        ->and(eng_bw_puts('backup'))->toBe([])
        ->and($GLOBALS['eng_bw_state']['cleaned'])->toBe([]);
});

it('reports a write whose result does not read back', function (): void {
    $GLOBALS['eng_bw_state']['after'] = 'other';

    $result = eng_bw_run(['55:physician_expertise' => 'old']);

    expect($result['mismatches'])->toBe(1)
        ->and($result['warnings'])->toBe(['Post 55 field physician_expertise: postmeta does not match after write.'])
        ->and(eng_bw_puts('change')[0][4]['write_mismatch'])->toBeTrue();
});

// the rows ACF rewrites on other posts

/** The stand-in for ACF Extended's bidirectional write: the related post's value and reference rows change. */
function eng_bw_bidirectional(int $related): Closure
{
    return function (ArrayObject $state) use ($related): void {
        foreach (['update_post_metadata', 'add_post_metadata'] as $hook) {
            foreach ($state['hooks'][$hook] ?? [] as $callback) {
                $callback(null, $related, '_physician_expertise', 'field_expertise_physicians');
            }
        }

        $meta = $state['meta'];
        $meta[$related.':_physician_expertise'] = 'field_expertise_physicians';
        $state['meta'] = $meta;
    };
}

it('backs up the reference row ACF rewrites on a related post, as it was before the write', function (): void {
    $GLOBALS['eng_bw_state']['side_effect'] = eng_bw_bidirectional(37);

    eng_bw_run([
        '55:physician_expertise' => 'old',
        '37:physician_expertise' => 'a:1:{i:0;s:2:"55";}',
        '37:_physician_expertise' => 'field_physician_expertise',
        '37:unrelated' => 'x',
        '37:physician_expertise_extra' => 'e',
    ]);

    $related = array_values(array_filter(eng_bw_puts('backup'), fn (array $p): bool => $p[2] === 37));
    expect($related)->not->toBe([])
        ->and($related[0])->toBe([
            'backup', 'r1', 37, 'physician_expertise',
            ['post_id' => 37, 'field' => 'physician_expertise', 'complex' => false, 'rows' => [['physician_expertise', 'a:1:{i:0;s:2:"55";}'], ['_physician_expertise', 'field_physician_expertise']]],
            'ignore',
        ])
        ->and($GLOBALS['eng_bw_state']['meta']['37:_physician_expertise'])->toBe('field_expertise_physicians');
});

it("backs up other rows of the written post too, but not the field's own", function (): void {
    $GLOBALS['eng_bw_state']['side_effect'] = function (ArrayObject $state): void {
        foreach ($state['hooks']['update_post_metadata'] ?? [] as $callback) {
            $callback(null, 55, 'physician_expertise', 'x');
            $callback(null, 55, '_physician_expertise', 'x');
            $callback(null, 55, '_sibling', 'x');
        }
    };

    eng_bw_run(['55:physician_expertise' => 'old', '55:sibling' => 's', '55:_sibling' => 'field_sibling']);

    $fields = array_column(eng_bw_puts('backup'), 3);
    expect($fields)->toBe(['physician_expertise', 'sibling'])
        ->and(eng_bw_puts('backup')[1][4]['rows'])->toBe([['sibling', 's'], ['_sibling', 'field_sibling']]);
});

it("treats a complex field's sub-field rows on its own post as its own", function (): void {
    $GLOBALS['eng_bw_state']['side_effect'] = function (ArrayObject $state): void {
        foreach ($state['hooks']['update_post_metadata'] ?? [] as $callback) {
            $callback(null, 55, 'physician_expertise_0_name', 'x');
            $callback(null, 55, '_physician_expertise_0_name', 'x');
        }
    };

    eng_bw_run(['55:physician_expertise' => 'old'], ['name' => 'physician_expertise', 'key' => 'field_x', 'type' => 'repeater']);

    expect(array_column(eng_bw_puts('backup'), 3))->toBe(['physician_expertise']);
});

it('backs up a sub-field-shaped row on another post even for a complex field', function (): void {
    $GLOBALS['eng_bw_state']['side_effect'] = function (ArrayObject $state): void {
        foreach ($state['hooks']['update_post_metadata'] ?? [] as $callback) {
            $callback(null, 37, 'physician_expertise_0_name', 'x');
        }
    };

    eng_bw_run(['55:physician_expertise' => 'old', '37:physician_expertise_0_name' => 'n'], ['name' => 'physician_expertise', 'key' => 'field_x', 'type' => 'repeater']);

    expect(array_column(eng_bw_puts('backup'), 2, 3))->toHaveKey('physician_expertise_0_name');
});

it('compares object ids as integers and meta keys as strings', function (): void {
    $GLOBALS['eng_bw_state']['side_effect'] = function (ArrayObject $state): void {
        foreach ($state['hooks']['update_post_metadata'] ?? [] as $callback) {
            $callback(null, '55', 'physician_expertise', 'x');
            $callback(null, '37', 5, 'x');
        }
    };

    eng_bw_run(['55:physician_expertise' => 'old']);

    expect(array_column(eng_bw_puts('backup'), 2, 3))->toBe(['physician_expertise' => 55, '5' => 37]);
});

it("does not take a longer name for a complex field's own sub-field rows", function (): void {
    $GLOBALS['eng_bw_state']['side_effect'] = function (ArrayObject $state): void {
        foreach ($state['hooks']['update_post_metadata'] ?? [] as $callback) {
            $callback(null, 55, 'physician_expertisefoo', 'x');
        }
    };

    eng_bw_run(['55:physician_expertise' => 'old'], ['name' => 'physician_expertise', 'key' => 'field_x', 'type' => 'repeater']);

    expect(array_column(eng_bw_puts('backup'), 3))->toBe(['physician_expertise', 'physician_expertisefoo']);
});

it('leaves a same-named sibling of a simple field on its own post to be backed up', function (): void {
    $GLOBALS['eng_bw_state']['side_effect'] = function (ArrayObject $state): void {
        foreach ($state['hooks']['update_post_metadata'] ?? [] as $callback) {
            $callback(null, 55, 'physician_expertise_extra', 'x');
        }
    };

    eng_bw_run(['55:physician_expertise' => 'old', '55:physician_expertise_extra' => 'e']);

    expect(array_column(eng_bw_puts('backup'), 3))->toBe(['physician_expertise', 'physician_expertise_extra']);
});

it('watches adds and deletes as well as updates', function (): void {
    $GLOBALS['eng_bw_state']['side_effect'] = function (ArrayObject $state): void {
        foreach (['add_post_metadata' => 'added', 'delete_post_metadata' => 'deleted'] as $hook => $key) {
            foreach ($state['hooks'][$hook] ?? [] as $callback) {
                $callback(null, 37, $key, 'x');
            }
        }
    };

    eng_bw_run(['55:physician_expertise' => 'old', '37:added' => 'a', '37:deleted' => 'd']);

    expect(array_column(eng_bw_puts('backup'), 3))->toBe(['physician_expertise', 'added', 'deleted']);
});

it('returns the filter value untouched so the write goes ahead', function (): void {
    $seen = new ArrayObject;
    $GLOBALS['eng_bw_state']['side_effect'] = function (ArrayObject $state) use ($seen): void {
        foreach ($state['hooks']['update_post_metadata'] ?? [] as $callback) {
            $seen['checks'] = [$callback(null, 37, 'x', 'v'), $callback('short-circuit', 37, 'x', 'v')];
        }
    };

    eng_bw_run(['55:physician_expertise' => 'old']);

    expect($seen['checks'])->toBe([null, 'short-circuit']);
});

it('registers the watch at the first priority and removes it once the write is done', function (): void {
    eng_bw_run(['55:physician_expertise' => 'old']);

    $state = $GLOBALS['eng_bw_state'];
    expect($state['added'])->toBe(['update_post_metadata' => [1, 3], 'add_post_metadata' => [1, 3], 'delete_post_metadata' => [1, 3]])
        ->and($state['removed'])->toBe(['update_post_metadata' => 1, 'add_post_metadata' => 1, 'delete_post_metadata' => 1])
        ->and(array_filter($state['hooks']))->toBe([]);
});

it('removes the watch even when the write throws', function (): void {
    $GLOBALS['eng_bw_state']['throw'] = true;

    expect(fn (): array => eng_bw_run(['55:physician_expertise' => 'old']))->toThrow(RuntimeException::class, 'write failed')
        ->and(array_filter($GLOBALS['eng_bw_state']['hooks']))->toBe([]);
});
