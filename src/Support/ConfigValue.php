<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Laravel\Support;

use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;

/**
 * Reads single values of config/livck-cloud.php. Accepts what env() yields as well: numbers
 * arrive as strings, "true" and "false" as booleans, an unset variable as null. A missing or
 * empty value falls back to the default.
 *
 * Error messages name the key and the type of a wrong value; they quote the value only for
 * numbers and switches, never for strings such as a token.
 *
 * @internal
 */
final class ConfigValue
{
    public static function string(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new ConfigurationException(sprintf('%s must be a string, got %s.', $key, get_debug_type($value)));
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function float(mixed $value, string $key, float $default): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        throw new ConfigurationException(sprintf('%s must be a number, got %s.', $key, self::describe($value)));
    }

    public static function int(mixed $value, string $key, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[+-]?\d+\z/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new ConfigurationException(sprintf('%s must be a whole number, got %s.', $key, self::describe($value)));
    }

    public static function bool(mixed $value, string $key, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $bool = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;

        if ($bool === null) {
            throw new ConfigurationException(sprintf('%s must be true or false, got %s.', $key, self::describe($value)));
        }

        return $bool;
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) || is_int($value) ? sprintf('"%s"', $value) : get_debug_type($value);
    }
}
