<?php

namespace Cinderwatch\Logs;

/**
 * Maps PSR-3 / Monolog level names onto OpenTelemetry severity numbers.
 *
 * @see https://opentelemetry.io/docs/specs/otel/logs/data-model/#field-severitynumber
 */
class Severity
{
    private const DEFAULT = 9;

    /** @var array<string, int> */
    private const NUMBERS = [
        'trace' => 1,
        'debug' => 5,
        'info' => 9,
        'notice' => 10,
        'warn' => 13,
        'warning' => 13,
        'error' => 17,
        'critical' => 18,
        'alert' => 19,
        'emergency' => 21,
    ];

    public static function number(string $level): int
    {
        return self::NUMBERS[self::text($level)] ?? self::DEFAULT;
    }

    public static function text(string $level): string
    {
        return strtolower(trim($level));
    }
}
