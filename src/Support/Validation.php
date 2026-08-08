<?php

declare(strict_types=1);

namespace StripeKit\Support;

use StripeKit\Exceptions\ValidationError;

class Validation
{
    private const EMAIL_REGEX = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
    private const CURRENCY_REGEX = '/^[a-z]{3}$/';

    public static function assertValidEmail(string $email): void
    {
        if (!preg_match(self::EMAIL_REGEX, $email)) {
            throw new ValidationError(sprintf('Invalid email address: "%s".', $email));
        }
    }

    public static function assertValidCurrency(string $currency): void
    {
        if (!preg_match(self::CURRENCY_REGEX, strtolower($currency))) {
            throw new ValidationError(sprintf('Invalid currency code: "%s". Expected a 3-letter ISO code such as "usd" or "eur".', $currency));
        }
    }

    public static function assertMinimumAmount(int $amountInMinorUnits, int $minimum = 50): void
    {
        if ($amountInMinorUnits < $minimum) {
            throw new ValidationError(sprintf('Amount must be an integer of at least %d minor currency units.', $minimum));
        }
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function slugifyFieldKey(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($key));
    }

    /**
     * @param list<array{key: string, label: string, type?: string, required?: bool, pattern?: string, patternHint?: string}> $schema
     * @param array<string, string> $submitted
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public static function validateCustomFieldSchema(array $schema, array $submitted): array
    {
        $errors = [];
        $values = [];

        foreach ($schema as $field) {
            $raw = $submitted[$field['key']] ?? null;
            $value = is_string($raw) ? trim($raw) : '';

            if (($field['required'] ?? false) && $value === '') {
                $errors[$field['key']] = ($field['label'] ?? $field['key']) . ' is required.';
                continue;
            }

            if ($value !== '' && !empty($field['pattern'])) {
                if (!preg_match('/' . str_replace('/', '\/', $field['pattern']) . '/', $value)) {
                    $errors[$field['key']] = $field['patternHint'] ?? ($field['label'] . ' format is invalid.');
                    continue;
                }
            }

            if ($value !== '') {
                $values[$field['key']] = $value;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }
}
