<?php

/**
 * Normalizer: raw ACF values reduced to a canonical shape, with WordPress mocked.
 *
 * Mutation run: `vendor/bin/pest tests/Unit/NormalizerTest.php --mutate --path=includes
 * --class='UAMSWP_ACFCDT_Migrator\Normalizer' --covered-only`, 138 mutants, 0 survivors (macOS, PHP 8.4.27,
 * PCOV; a working run, not the Linux run that closes the coverage ticket). The file is named in the command
 * because Patchwork and Pest's mutation stream wrapper cannot scan a test directory together.
 *
 * Mutants that no input can kill, so every killable mutant is dead:
 * - Three `?? ''` defaults for a missing field type (`value()`, `has_invalid_ids()`, `is_complex()`):
 *   the default never equals a type the class compares against, whatever its value. Annotated in the source.
 * - Two `(string) $k` casts on an array key (`row()`, `generic()`): PHP stores a numeric-string key as an
 *   int either way. Annotated in the source.
 * - The `[]` entry in `value()`'s empty list, and the `true` branches of `value()` and `generic()`, were
 *   dead code (each case reaches the same result by another path) and are removed; the tests that read
 *   `[]` and `true` stay as pins.
 *
 * @see includes/class-normalizer.php
 */

declare(strict_types=1);

use UAMSWP_ACFCDT_Migrator\Normalizer;

use function Brain\Monkey\Functions\when;

beforeEach(function (): void {
    // wp_json_encode() is json_encode() plus a depth guard; the plain call is enough here.
    when('wp_json_encode')->alias('json_encode');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function normalizer_sub_field(string $key, string $name, string $type = 'text', array $overrides = []): array
{
    return ['key' => $key, 'name' => $name, 'type' => $type] + $overrides;
}

it('treats every empty form as the same empty value', function (mixed $raw): void {
    expect(Normalizer::value(['type' => 'text'], $raw))->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'empty array' => [[]],
    'false' => [false],
]);

it('reads 0 and "0" as values, not as empty', function (mixed $raw): void {
    expect(Normalizer::value(['type' => 'text'], $raw))->toBe('0');
})->with([
    'int' => [0],
    'string' => ['0'],
]);

it('reads false as "0" for a true/false field only', function (): void {
    expect(Normalizer::value(['type' => 'true_false'], false))->toBe('0')
        ->and(Normalizer::value(['type' => 'text'], false))->toBeNull()
        ->and(Normalizer::value([], false))->toBeNull();
});

it('reads true as "1" whatever the field type', function (string $type): void {
    expect(Normalizer::value(['type' => $type], true))->toBe('1');
})->with(['true_false', 'text', 'relationship']);

it('reads a field with no type as a plain value', function (): void {
    expect(Normalizer::value([], 'x'))->toBe('x')
        ->and(Normalizer::value([], ['a' => 1]))->toBe(['a' => '1']);
});

it('compares scalars by their string form', function (): void {
    expect(Normalizer::same(
        Normalizer::value(['type' => 'number'], 7),
        Normalizer::value(['type' => 'number'], '7'),
    ))->toBeTrue();
});

it('reads a float and an int as their string forms', function (): void {
    expect(Normalizer::value(['type' => 'number'], 1.5))->toBe('1.5')
        ->and(Normalizer::value(['type' => 'number'], 42))->toBe('42');
});

it('turns an object into the same shape as its array form', function (): void {
    $object = (object) ['b' => 2, 'a' => 'x'];

    expect(Normalizer::value(['type' => 'text'], $object))->toBe(['a' => 'x', 'b' => '2'])
        ->and(Normalizer::value(['type' => 'text'], $object))->toBe(Normalizer::value(['type' => 'text'], ['a' => 'x', 'b' => 2]));
});

it('drops invalid and zero IDs from an ID field', function (): void {
    expect(Normalizer::value(['type' => 'relationship'], [10, 0, '', '12']))->toBe(['10', '12']);
});

it('keeps only the IDs of an ID list, whatever the array keys', function (): void {
    expect(Normalizer::value(['type' => 'gallery'], [0 => 5, 1 => 6, 3 => 9]))->toBe(['5', '6', '9']);
});

it('reads a single ID as one string and a list as a list', function (string $type): void {
    expect(Normalizer::value(['type' => $type], 12))->toBe('12')
        ->and(Normalizer::value(['type' => $type], [12]))->toBe(['12']);
})->with(['taxonomy', 'relationship', 'post_object', 'gallery', 'user', 'image', 'file']);

it('reads an ID field with only zero or empty IDs as empty', function (mixed $raw): void {
    expect(Normalizer::value(['type' => 'image'], $raw))->toBeNull();
})->with([
    'zero' => [0],
    'zero string' => ['0'],
    'zeros' => [[0, '0', '']],
]);

it('reads an object in an ID field through its array form', function (): void {
    expect(Normalizer::value(['type' => 'user'], (object) ['ID' => 4]))->toBe(['4']);
});

it('keeps a corrupted ID in an ID field so it can be flagged', function (): void {
    expect(Normalizer::value(['type' => 'post_object'], '10522]'))->toBe('10522]');
});

it('reads a repeater row count of 0 as empty and any other count as itself', function (): void {
    expect(Normalizer::value(['type' => 'repeater'], '0'))->toBeNull()
        ->and(Normalizer::value(['type' => 'flexible_content'], '0'))->toBeNull()
        ->and(Normalizer::value(['type' => 'repeater'], '3'))->toBe('3')
        ->and(Normalizer::value(['type' => 'group'], '0'))->toBe('0')
        ->and(Normalizer::value(['type' => 'text'], '0'))->toBe('0');
});

it('keys repeater rows by sub field name and drops empty cells', function (): void {
    $field = [
        'type' => 'repeater',
        'sub_fields' => [
            normalizer_sub_field('field_a', 'title'),
            normalizer_sub_field('field_b', 'note'),
        ],
    ];

    expect(Normalizer::value($field, [['field_a' => 'One', 'field_b' => '']]))
        ->toBe([['title' => 'One']]);
});

it('reads repeater rows keyed by name as well as by field key', function (): void {
    $field = [
        'type' => 'repeater',
        'sub_fields' => [normalizer_sub_field('field_a', 'title')],
    ];

    expect(Normalizer::value($field, [['title' => 'One'], ['field_a' => 'Two']]))
        ->toBe([['title' => 'One'], ['title' => 'Two']]);
});

it('keeps an all-empty repeater row as an empty row so the row count still matters', function (): void {
    $field = ['type' => 'repeater', 'sub_fields' => [normalizer_sub_field('field_a', 'title')]];

    expect(Normalizer::value($field, [['field_a' => '']]))->toBe([[]])
        ->and(Normalizer::value($field, [['field_a' => ''], ['field_a' => 'x']]))->toBe([[], ['title' => 'x']]);
});

it('sorts the cells of a row by name', function (): void {
    $field = [
        'type' => 'repeater',
        'sub_fields' => [normalizer_sub_field('field_z', 'zed'), normalizer_sub_field('field_a', 'alpha')],
    ];

    $row = Normalizer::value($field, [['field_z' => '1', 'field_a' => '2']]);

    expect(array_keys($row[0]))->toBe(['alpha', 'zed']);
});

it('reads a repeater with no sub fields through the generic form', function (): void {
    expect(Normalizer::value(['type' => 'repeater'], [['x' => 1, 'a' => '']]))->toBe([['x' => '1']]);
});

it('reads a cell that matches no sub field by its own key', function (): void {
    $field = ['type' => 'repeater', 'sub_fields' => [normalizer_sub_field('field_a', 'title')]];

    expect(Normalizer::value($field, [['field_a' => 'One', 'extra' => 5, 'blank' => '']]))
        ->toBe([['extra' => '5', 'title' => 'One']]);
});

it('prefers the field key over the name when both could match a cell', function (): void {
    $field = [
        'type' => 'repeater',
        'sub_fields' => [
            normalizer_sub_field('field_a', 'one'),
            normalizer_sub_field('two', 'field_a'),
        ],
    ];

    expect(Normalizer::value($field, [['field_a' => 'x']]))->toBe([['one' => 'x']]);
});

it('ignores a sub field that has no name when matching cells by name', function (): void {
    $field = [
        'type' => 'repeater',
        'sub_fields' => [['key' => 'field_a', 'name' => '', 'type' => 'text'], ['name' => 'real', 'type' => 'text']],
    ];

    expect(Normalizer::value($field, [['' => 'blank', 'real' => 'yes']]))->toBe([['' => 'blank', 'real' => 'yes']]);
});

it('normalizes the cells of a row by their own sub field type', function (): void {
    $field = [
        'type' => 'repeater',
        'sub_fields' => [
            normalizer_sub_field('field_flag', 'flag', 'true_false'),
            normalizer_sub_field('field_img', 'image', 'image'),
        ],
    ];

    expect(Normalizer::value($field, [['field_flag' => false, 'field_img' => '7']]))
        ->toBe([['flag' => '0', 'image' => '7']]);
});

it('reads a row that is not an array through the generic form', function (): void {
    $field = ['type' => 'repeater', 'sub_fields' => []];

    expect(Normalizer::value($field, ['loose', 5]))->toBe(['loose', '5']);
});

it('reads a flexible content row by its layout and keeps the layout name', function (): void {
    $field = [
        'type' => 'flexible_content',
        'layouts' => [
            ['name' => 'hero', 'sub_fields' => [normalizer_sub_field('field_t', 'title')]],
            ['name' => 'text', 'sub_fields' => [normalizer_sub_field('field_b', 'body')]],
        ],
    ];

    $rows = [
        ['acf_fc_layout' => 'hero', 'field_t' => 'Hi'],
        ['acf_fc_layout' => 'text', 'field_b' => 'Body', 'field_t' => 'stray'],
    ];

    expect(Normalizer::value($field, $rows))->toBe([
        ['title' => 'Hi', 'acf_fc_layout' => 'hero'],
        ['body' => 'Body', 'field_t' => 'stray', 'acf_fc_layout' => 'text'],
    ]);
});

it('skips a flexible content layout with no name and reads one with no sub fields', function (): void {
    $field = [
        'type' => 'flexible_content',
        'layouts' => [
            ['sub_fields' => [normalizer_sub_field('field_t', 'title')]],
            ['name' => 'bare'],
        ],
    ];

    expect(Normalizer::value($field, [
        ['acf_fc_layout' => 'bare', 'field_t' => 'x'],
        ['acf_fc_layout' => 'missing', 'field_t' => 'y'],
    ]))->toBe([
        ['field_t' => 'x', 'acf_fc_layout' => 'bare'],
        ['field_t' => 'y', 'acf_fc_layout' => 'missing'],
    ]);
});

it('reads a flexible content value with no layouts, and rows that are not arrays', function (): void {
    expect(Normalizer::value(['type' => 'flexible_content'], [['acf_fc_layout' => 'a', 'k' => 'v']]))
        ->toBe([['k' => 'v', 'acf_fc_layout' => 'a']])
        ->and(Normalizer::value(['type' => 'flexible_content'], ['loose']))->toBe(['loose'])
        ->and(Normalizer::value(['type' => 'flexible_content'], [['k' => 'v']]))->toBe([['k' => 'v', 'acf_fc_layout' => '']]);
});

it('reads a group or clone through its sub fields', function (string $type): void {
    $field = ['type' => $type, 'sub_fields' => [normalizer_sub_field('field_a', 'title'), normalizer_sub_field('field_b', 'note')]];

    expect(Normalizer::value($field, ['field_a' => 'One', 'field_b' => '']))->toBe(['title' => 'One']);
})->with(['group', 'clone']);

it('reads a group whose cells are all empty as empty', function (): void {
    $field = ['type' => 'group', 'sub_fields' => [normalizer_sub_field('field_a', 'title')]];

    expect(Normalizer::value($field, ['field_a' => '']))->toBeNull();
});

it('reads a group or clone with no sub fields through the generic form', function (string $type): void {
    expect(Normalizer::value(['type' => $type], ['b' => 2, 'a' => '', 'c' => 'x']))->toBe(['b' => '2', 'c' => 'x'])
        ->and(Normalizer::value(['type' => $type, 'sub_fields' => []], ['a' => 1]))->toBe(['a' => '1']);
})->with(['group', 'clone']);

it('reads an array in any other field type through the generic form', function (): void {
    expect(Normalizer::value(['type' => 'checkbox'], ['a', '', 'b']))->toBe(['a', null, 'b']);
});

it('reads a generic value of every shape', function (): void {
    expect(Normalizer::generic(null))->toBeNull()
        ->and(Normalizer::generic(''))->toBeNull()
        ->and(Normalizer::generic([]))->toBeNull()
        ->and(Normalizer::generic(false))->toBeNull()
        ->and(Normalizer::generic(true))->toBe('1')
        ->and(Normalizer::generic(0))->toBe('0')
        ->and(Normalizer::generic('0'))->toBe('0')
        ->and(Normalizer::generic(7))->toBe('7')
        ->and(Normalizer::generic(2.5))->toBe('2.5')
        ->and(Normalizer::generic('text'))->toBe('text');
});

it('keeps the position of an empty item in a list', function (): void {
    expect(Normalizer::generic(['a', '', 'b']))->toBe(['a', null, 'b'])
        ->and(Normalizer::generic([1, [2, 3]]))->toBe(['1', ['2', '3']]);
});

it('drops empty entries from an associative array and sorts it by key', function (): void {
    /** @var array<string, string> $sorted */
    $sorted = Normalizer::generic(['b' => 1, 'a' => 2]);

    expect(Normalizer::generic(['b' => 1, 'a' => 2, 'c' => '', 'd' => null, 'e' => false]))->toBe(['a' => '2', 'b' => '1'])
        ->and(array_keys($sorted))->toBe(['a', 'b']);
});

it('reads an associative array of only empty entries as empty', function (): void {
    expect(Normalizer::generic(['a' => '', 'b' => null]))->toBeNull();
});

it('treats an array whose keys are not 0..n as associative', function (array $raw, array $expected): void {
    expect(Normalizer::generic($raw))->toBe($expected);
})->with([
    'starts at 1' => [[1 => 'a', 2 => 'b'], ['1' => 'a', '2' => 'b']],
    'has a gap' => [[0 => 'a', 2 => 'b'], ['0' => 'a', '2' => 'b']],
    'reversed is sorted by key' => [[1 => 'b', 0 => 'a'], [0 => 'a', 1 => 'b']],
    'is a list' => [[0 => 'a', 1 => 'b'], ['a', 'b']],
]);

it('reads an object in a generic value through its array form', function (): void {
    expect(Normalizer::generic((object) ['k' => 3]))->toBe(['k' => '3']);
});

it('reads a nested generic value', function (): void {
    expect(Normalizer::generic(['outer' => ['inner' => 1, 'gone' => ''], 'list' => [1, 2]]))
        ->toBe(['list' => ['1', '2'], 'outer' => ['inner' => '1']]);
});

it('renders stable JSON without escaping slashes or unicode', function (): void {
    expect(Normalizer::json(['path' => 'a/b', 'name' => "caf\u{e9}"]))->toBe("{\"path\":\"a/b\",\"name\":\"caf\u{e9}\"}")
        ->and(Normalizer::json(null))->toBe('null');
});

it('compares two normalized values by their JSON', function (): void {
    expect(Normalizer::same(['a' => '1'], ['a' => '1']))->toBeTrue()
        ->and(Normalizer::same(['a' => '1'], ['a' => '2']))->toBeFalse()
        ->and(Normalizer::same(null, null))->toBeTrue()
        ->and(Normalizer::same(null, '0'))->toBeFalse()
        ->and(Normalizer::same(['a', 'b'], ['b', 'a']))->toBeFalse();
});

it('flags an ID that is not all digits', function (): void {
    $field = ['type' => 'post_object'];

    expect(Normalizer::has_invalid_ids($field, '10522]'))->toBeTrue()
        ->and(Normalizer::has_invalid_ids($field, '10522'))->toBeFalse();
});

it('flags an ID list with any invalid ID', function (): void {
    $field = ['type' => 'relationship'];

    expect(Normalizer::has_invalid_ids($field, ['1', '2']))->toBeFalse()
        ->and(Normalizer::has_invalid_ids($field, ['1', '2x']))->toBeTrue()
        ->and(Normalizer::has_invalid_ids($field, ['1', 2]))->toBeTrue()
        ->and(Normalizer::has_invalid_ids($field, ['1', ['2']]))->toBeTrue();
});

it('flags nothing for an empty value or a field that does not hold IDs', function (): void {
    expect(Normalizer::has_invalid_ids(['type' => 'image'], null))->toBeFalse()
        ->and(Normalizer::has_invalid_ids(['type' => 'text'], 'abc'))->toBeFalse()
        ->and(Normalizer::has_invalid_ids([], 'abc'))->toBeFalse();
});

it('knows which field types are complex', function (string $type, bool $complex): void {
    expect(Normalizer::is_complex(['type' => $type]))->toBe($complex);
})->with([
    'repeater' => ['repeater', true],
    'flexible_content' => ['flexible_content', true],
    'group' => ['group', true],
    'clone' => ['clone', true],
    'text' => ['text', false],
    'relationship' => ['relationship', false],
]);

it('reads a field with no type as not complex', function (): void {
    expect(Normalizer::is_complex([]))->toBeFalse();
});

it('reads a cell keyed by its sub field name through that sub field', function (): void {
    $field = ['type' => 'repeater', 'sub_fields' => [normalizer_sub_field('field_flag', 'flag', 'true_false')]];

    expect(Normalizer::value($field, [['flag' => false]]))->toBe([['flag' => '0']]);
});

it('only matches a cell to a sub field that has a name', function (): void {
    $field = [
        'type' => 'repeater',
        'sub_fields' => [
            ['key' => 'field_a', 'type' => 'text'],
            ['key' => 'field_b', 'name' => '', 'type' => 'true_false'],
        ],
    ];

    expect(Normalizer::value($field, [['' => false, 'extra' => 'x']]))->toBe([['extra' => 'x']]);
});

it('reads sub fields, layouts and layout sub fields that are not arrays as one entry', function (): void {
    expect(Normalizer::value(['type' => 'repeater', 'sub_fields' => 'loose'], [['a' => 1]]))->toBe([['a' => '1']])
        ->and(Normalizer::value(['type' => 'group', 'sub_fields' => 'loose'], ['a' => 1]))->toBe(['a' => '1'])
        ->and(Normalizer::value(['type' => 'flexible_content', 'layouts' => 'loose'], [['acf_fc_layout' => 'x', 'a' => 1]]))
        ->toBe([['a' => '1', 'acf_fc_layout' => 'x']])
        ->and(Normalizer::value(
            ['type' => 'flexible_content', 'layouts' => [['name' => 'x', 'sub_fields' => 'loose']]],
            [['acf_fc_layout' => 'x', 'a' => 1]],
        ))->toBe([['a' => '1', 'acf_fc_layout' => 'x']]);
});
