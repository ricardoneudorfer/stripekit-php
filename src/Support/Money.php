<?php

declare(strict_types=1);

namespace StripeKit\Support;

class Money
{
    private const ZERO_DECIMAL_CURRENCIES = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg',
        'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    public static function isZeroDecimalCurrency(string $currency): bool
    {
        return in_array(strtolower($currency), self::ZERO_DECIMAL_CURRENCIES, true);
    }

    public static function toMinorUnits(float $amount, string $currency): int
    {
        return self::isZeroDecimalCurrency($currency) ? (int) round($amount) : (int) round($amount * 100);
    }

    public static function toMajorUnits(int $amountInMinorUnits, string $currency): float
    {
        return self::isZeroDecimalCurrency($currency) ? (float) $amountInMinorUnits : $amountInMinorUnits / 100;
    }

    public static function formatMoney(int $amountInMinorUnits, string $currency, string $locale = 'en-US'): string
    {
        $major = self::toMajorUnits($amountInMinorUnits, $currency);
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        return $formatter->formatCurrency($major, strtoupper($currency));
    }
}
