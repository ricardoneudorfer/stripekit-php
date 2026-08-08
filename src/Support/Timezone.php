<?php

declare(strict_types=1);

namespace StripeKit\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use StripeKit\Exceptions\ConfigurationError;
use Throwable;

class Timezone
{
    public const DEFAULT_TIMEZONE = 'UTC';

    public static function assertValidTimezone(string $timezone): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new ConfigurationError(sprintf('Invalid IANA timezone identifier: "%s". Use a value like "Europe/Amsterdam" or "UTC".', $timezone));
        }
    }

    public static function unixToUtcIso(?int $unixSeconds): ?string
    {
        if ($unixSeconds === null) {
            return null;
        }

        return (new DateTimeImmutable('@' . $unixSeconds))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function utcIsoToTimezone(?string $utcIso, string $timezone): ?string
    {
        if (!$utcIso) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($utcIso, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        if ($timezone === self::DEFAULT_TIMEZONE) {
            return $utcIso;
        }

        $local = $date->setTimezone(new DateTimeZone($timezone));

        return $local->format('Y-m-d\TH:i:s');
    }

    /**
     * @return array{utc: string|null, local: string|null, timezone: string}
     */
    public static function unixToTimezone(?int $unixSeconds, string $timezone): array
    {
        $utc = self::unixToUtcIso($unixSeconds);
        $local = self::utcIsoToTimezone($utc, $timezone);

        return ['utc' => $utc, 'local' => $local, 'timezone' => $timezone];
    }

    public static function nowUtcIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function nowInTimezone(string $timezone): string
    {
        return self::utcIsoToTimezone(self::nowUtcIso(), $timezone) ?? self::nowUtcIso();
    }

    public static function addDaysUtcIso(string $fromUtcIso, int $days): string
    {
        $date = new DateTimeImmutable($fromUtcIso, new DateTimeZone('UTC'));
        $interval = new DateInterval('P' . abs($days) . 'D');

        $date = $days >= 0 ? $date->add($interval) : $date->sub($interval);

        return $date->format('Y-m-d\TH:i:s.v\Z');
    }
}
