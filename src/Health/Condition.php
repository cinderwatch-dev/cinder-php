<?php

namespace Cinderwatch\Health;

enum Condition: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
    case Errored = 'errored';
    case NotApplicable = 'not_applicable';

    public function isProblem(): bool
    {
        return $this === self::Unhealthy || $this === self::Errored;
    }

    public function needsAttention(): bool
    {
        return $this->isProblem() || $this === self::Degraded;
    }

    /** Higher is worse. */
    public function severity(): int
    {
        return match ($this) {
            self::NotApplicable => 0,
            self::Healthy => 1,
            self::Degraded => 2,
            self::Unhealthy => 3,
            self::Errored => 4,
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Healthy => 'healthy',
            self::Degraded => 'degraded',
            self::Unhealthy => 'unhealthy',
            self::Errored => 'errored',
            self::NotApplicable => 'not applicable',
        };
    }
}
