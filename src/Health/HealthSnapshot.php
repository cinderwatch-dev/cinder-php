<?php

namespace Cinderwatch\Health;

use DateTimeImmutable;

class HealthSnapshot
{
    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(
        public readonly DateTimeImmutable $takenAt,
        public readonly array $findings,
    ) {}

    /**
     * @param  list<Finding>  $findings
     */
    public static function at(int $unixTimestamp, array $findings): self
    {
        return new self((new DateTimeImmutable)->setTimestamp($unixTimestamp), $findings);
    }

    public function ageInSeconds(?DateTimeImmutable $now = null): int
    {
        return ($now ?? new DateTimeImmutable)->getTimestamp() - $this->takenAt->getTimestamp();
    }

    public function hasProblems(): bool
    {
        return $this->any(static fn (Condition $condition): bool => $condition->isProblem());
    }

    public function needsAttention(): bool
    {
        return $this->any(static fn (Condition $condition): bool => $condition->needsAttention());
    }

    public function worstCondition(): ?Condition
    {
        $worst = null;

        foreach ($this->findings as $finding) {
            if ($worst === null || $finding->condition()->severity() > $worst->severity()) {
                $worst = $finding->condition();
            }
        }

        return $worst;
    }

    /** The worst finding, or healthy when only not-applicable findings exist. */
    public function overallCondition(): Condition
    {
        $overall = Condition::Healthy;

        foreach ($this->findings as $finding) {
            if ($finding->condition()->severity() > $overall->severity()) {
                $overall = $finding->condition();
            }
        }

        return $overall;
    }

    /**
     * @param  callable(Condition): bool  $predicate
     */
    private function any(callable $predicate): bool
    {
        foreach ($this->findings as $finding) {
            if ($predicate($finding->condition())) {
                return true;
            }
        }

        return false;
    }
}
