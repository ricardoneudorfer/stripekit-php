<?php

declare(strict_types=1);

namespace StripeKit\Support;

class Tokens
{
    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function generateCheckoutId(): string
    {
        return 'cs_kit_' . bin2hex(random_bytes(20));
    }

    public static function generateInvoiceNumber(string $prefix = 'INV'): string
    {
        $year = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y');
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        return sprintf('%s-%d-%s', $prefix, $year, $suffix);
    }
}
