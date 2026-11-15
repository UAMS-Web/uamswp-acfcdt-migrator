<?php

declare(strict_types=1);

namespace UAMSWP_ACFCDT_Migrator;

/**
 * Typed reads of values that arrive as `mixed`: decoded JSON, a stored record, a WordPress filter result.
 *
 * Each method answers with the requested type and a neutral value when the input is not usable, so a caller can pass
 * the result straight to a function that takes `string`, `int` or `array`.
 */
class Cast
{
    /**
     * @return string The scalar as a string, or `''` for anything else.
     */
    public static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return int The numeric or boolean value as an integer, or `0` for anything else.
     */
    public static function int(mixed $value): int
    {
        return is_numeric($value) || is_bool($value) ? (int) $value : 0;
    }

    /**
     * @return array<mixed> The array itself, or `[]` for anything else.
     */
    public static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string> The array's values as strings, in order; an element that is not a scalar becomes `''`.
     */
    public static function strings(mixed $value): array
    {
        return array_values(array_map(self::str(...), self::arr($value)));
    }
}
