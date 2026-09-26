<?php

declare(strict_types=1);

use LIVCK\Cloud\Laravel\Exceptions\ConfigurationException;
use LIVCK\Cloud\Laravel\Support\ConfigValue;

describe('strings', function (): void {
    it('trims strings and treats empty ones as unset', function (): void {
        expect(ConfigValue::string('  de  ', 'k'))->toBe('de')
            ->and(ConfigValue::string('   ', 'k'))->toBeNull()
            ->and(ConfigValue::string(null, 'k'))->toBeNull();
    });

    it('names the key and the type of a wrong value, never the value', function (): void {
        expect(fn(): ?string => ConfigValue::string(4711, 'livck-cloud.connections.default.token'))
            ->toThrow(ConfigurationException::class, 'livck-cloud.connections.default.token must be a string, got int.');
    });
});

describe('numbers', function (): void {
    it('reads floats from numbers and numeric strings', function (mixed $value, float $expected): void {
        expect(ConfigValue::float($value, 'k', 30.0))->toBe($expected);
    })->with([
        'int' => [45, 45.0],
        'float' => [2.5, 2.5],
        'env string' => [' 12.5 ', 12.5],
        'unset' => [null, 30.0],
        'empty' => ['', 30.0],
    ]);

    it('refuses a float that is not a number', function (): void {
        expect(fn(): float => ConfigValue::float('soon', 'livck-cloud.connections.default.timeout', 30.0))
            ->toThrow(ConfigurationException::class, 'livck-cloud.connections.default.timeout must be a number, got "soon".');
    });

    it('reads whole numbers from ints and integer strings', function (mixed $value, int $expected): void {
        expect(ConfigValue::int($value, 'k', 2))->toBe($expected);
    })->with([
        'int' => [5, 5],
        'env string' => [' 3 ', 3],
        'signed' => ['+4', 4],
        'unset' => [null, 2],
        'empty' => ['', 2],
    ]);

    it('refuses a fraction or anything else as a whole number', function (mixed $value, string $described): void {
        expect(fn(): int => ConfigValue::int($value, 'livck-cloud.connections.default.max_retries', 2))
            ->toThrow(ConfigurationException::class, sprintf('livck-cloud.connections.default.max_retries must be a whole number, got %s.', $described));
    })->with([
        'fraction' => ['2.5', '"2.5"'],
        'float' => [2.5, 'float'],
        'array' => [[1], 'array'],
    ]);
});

describe('switches', function (): void {
    it('reads booleans the way env() and humans write them', function (mixed $value, bool $expected): void {
        expect(ConfigValue::bool($value, 'k', true))->toBe($expected);
    })->with([
        'true' => [true, true],
        'false' => [false, false],
        'string false' => ['false', false],
        'string off' => ['off', false],
        'string 1' => ['1', true],
        'int 0' => [0, false],
        'unset' => [null, true],
        'empty' => ['', true],
    ]);

    it('refuses anything else', function (): void {
        expect(fn(): bool => ConfigValue::bool('maybe', 'livck-cloud.connections.default.idempotency', true))
            ->toThrow(ConfigurationException::class, 'livck-cloud.connections.default.idempotency must be true or false, got "maybe".')
            ->and(fn(): bool => ConfigValue::bool(2.0, 'k', true))
            ->toThrow(ConfigurationException::class, 'k must be true or false, got float.');
    });
});
