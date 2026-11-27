<?php

namespace Cinderwatch\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class Time
{
    /** RFC 3339 in UTC with microseconds, e.g. `2026-11-23T16:00:00.123456Z`. */
    private const RFC3339_MICRO = 'Y-m-d\TH:i:s.u\Z';

    public static function unixNano(?float $microtime = null): int
    {
        return (int) (($microtime ?? microtime(true)) * 1_000_000_000);
    }

    public static function rfc3339(?DateTimeInterface $moment = null): string
    {
        $moment = DateTimeImmutable::createFromInterface($moment ?? new DateTimeImmutable);

        return $moment->setTimezone(new DateTimeZone('UTC'))->format(self::RFC3339_MICRO);
    }

    public static function parseRfc3339(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
