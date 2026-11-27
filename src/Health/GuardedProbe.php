<?php

namespace Cinderwatch\Health;

use Closure;

class GuardedProbe implements Probe
{
    /**
     * @param  Closure(): bool  $guard
     */
    public function __construct(
        private readonly Probe $inner,
        private readonly Closure $guard,
        private readonly string $reason = 'Guard condition was not met.',
    ) {}

    public function id(): string
    {
        return $this->inner->id();
    }

    public function title(): string
    {
        return $this->inner->title();
    }

    public function examine(): Observation
    {
        if (! ($this->guard)()) {
            return Observation::notApplicable($this->reason);
        }

        return $this->inner->examine();
    }
}
