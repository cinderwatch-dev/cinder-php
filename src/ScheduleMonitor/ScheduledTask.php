<?php

namespace Cinderwatch\ScheduleMonitor;

/**
 * Exactly one of `cron` and `intervalMinutes` is set.
 *
 * @phpstan-type WireTask array{key: string, summary: string|null, cron: string|null, interval_minutes: int|null, timezone: string, grace_minutes: int}
 */
class ScheduledTask
{
    public const MAX_KEY_LENGTH = 255;

    private function __construct(
        public readonly string $key,
        public readonly ?string $summary,
        public readonly ?string $cron,
        public readonly ?int $intervalMinutes,
        public readonly string $timezone,
        public readonly int $graceMinutes,
    ) {}

    public static function cron(string $key, string $expression, string $timezone, int $graceMinutes, ?string $summary = null): self
    {
        return new self(self::trimKey($key), self::nullIfEmpty($summary), $expression, null, $timezone, max(0, $graceMinutes));
    }

    public static function interval(string $key, int $minutes, string $timezone, int $graceMinutes, ?string $summary = null): self
    {
        return new self(self::trimKey($key), self::nullIfEmpty($summary), null, max(1, $minutes), $timezone, max(0, $graceMinutes));
    }

    /**
     * @return WireTask
     */
    public function toPayload(): array
    {
        return [
            'key' => $this->key,
            'summary' => $this->summary,
            'cron' => $this->cron,
            'interval_minutes' => $this->intervalMinutes,
            'timezone' => $this->timezone,
            'grace_minutes' => $this->graceMinutes,
        ];
    }

    private static function trimKey(string $key): string
    {
        return mb_substr($key, 0, self::MAX_KEY_LENGTH);
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
