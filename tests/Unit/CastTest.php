<?php

/**
 * Cast: typed reads of `mixed` values.
 *
 * @see includes/class-cast.php
 */

declare(strict_types=1);

use UAMSWP_ACFCDT_Migrator\Cast;

it('reads a scalar as a string and anything else as an empty string', function (mixed $value, string $expected): void {
    expect(Cast::str($value))->toBe($expected);
})->with([
    'string' => ['abc', 'abc'],
    'integer' => [7, '7'],
    'float' => [1.5, '1.5'],
    'true' => [true, '1'],
    'false' => [false, ''],
    'null' => [null, ''],
    'array' => [['a'], ''],
    'object' => [new stdClass, ''],
]);

it('reads a numeric or boolean value as an integer and anything else as zero', function (mixed $value, int $expected): void {
    expect(Cast::int($value))->toBe($expected);
})->with([
    'integer' => [7, 7],
    'numeric string' => ['42', 42],
    'float' => [3.9, 3],
    'true' => [true, 1],
    'false' => [false, 0],
    'text' => ['abc', 0],
    'null' => [null, 0],
    'array' => [[1], 0],
]);

it('returns an array unchanged and anything else as an empty array', function (mixed $value, array $expected): void {
    expect(Cast::arr($value))->toBe($expected);
})->with([
    'list' => [[1, 2], [1, 2]],
    'map' => [['a' => 1], ['a' => 1]],
    'empty' => [[], []],
    'string' => ['abc', []],
    'null' => [null, []],
    'object' => [new stdClass, []],
]);

it('reads the values of an array as strings, in order and without keys', function (): void {
    expect(Cast::strings(['x' => 'a', 'y' => 2, 'z' => ['n'], 'w' => null]))->toBe(['a', '2', '', '']);
});

it('reads a value that is not an array as no strings', function (): void {
    expect(Cast::strings('abc'))->toBe([]);
});
