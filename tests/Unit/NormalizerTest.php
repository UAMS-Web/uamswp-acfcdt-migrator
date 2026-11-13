<?php

/**
 * Normalizer: raw ACF values reduced to a canonical shape, with WordPress mocked.
 *
 * @see includes/class-normalizer.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWP_ACFCDT_Migrator\Normalizer;

it('treats every empty form as the same empty value', function (mixed $raw): void {
    expect(Normalizer::value(['type' => 'text'], $raw))->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'empty array' => [[]],
    'false' => [false],
]);

it('reads false as "0" for a true/false field only', function (): void {
    expect(Normalizer::value(['type' => 'true_false'], false))->toBe('0');
});

it('compares scalars by their string form', function (): void {
    Functions\when('wp_json_encode')->alias('json_encode');

    expect(Normalizer::same(
        Normalizer::value(['type' => 'number'], 7),
        Normalizer::value(['type' => 'number'], '7'),
    ))->toBeTrue();
});

it('drops invalid and zero IDs from an ID field', function (): void {
    expect(Normalizer::value(['type' => 'relationship'], [10, 0, '', '12']))->toBe(['10', '12']);
});

it('flags an ID that is not all digits', function (): void {
    $field = ['type' => 'post_object'];

    expect(Normalizer::has_invalid_ids($field, '10522]'))->toBeTrue()
        ->and(Normalizer::has_invalid_ids($field, '10522'))->toBeFalse();
});

it('keys repeater rows by sub field name and drops empty cells', function (): void {
    $field = [
        'type' => 'repeater',
        'sub_fields' => [
            ['key' => 'field_a', 'name' => 'title', 'type' => 'text'],
            ['key' => 'field_b', 'name' => 'note', 'type' => 'text'],
        ],
    ];

    expect(Normalizer::value($field, [['field_a' => 'One', 'field_b' => '']]))
        ->toBe([['title' => 'One']]);
});
