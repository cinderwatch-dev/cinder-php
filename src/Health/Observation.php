<?php

namespace Cinderwatch\Health;

use Throwable;

class Observation
{
    /**
     * @param  string  $summary  Short dashboard phrase such as "42% used".
     * @param  string  $detail  Explanation used in alerts, empty when healthy.
     * @param  array<string, mixed>  $facts  JSON-safe context about what was measured.
     */
    public function __construct(
        public readonly Condition $condition,
        public readonly string $summary,
        public readonly string $detail = '',
        public readonly array $facts = [],
    ) {}

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function healthy(string $summary, array $facts = []): self
    {
        return new self(Condition::Healthy, $summary, '', $facts);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function degraded(string $summary, string $detail, array $facts = []): self
    {
        return new self(Condition::Degraded, $summary, $detail, $facts);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function unhealthy(string $summary, string $detail, array $facts = []): self
    {
        return new self(Condition::Unhealthy, $summary, $detail, $facts);
    }

    public static function notApplicable(string $reason): self
    {
        return new self(Condition::NotApplicable, 'Not applicable', $reason);
    }

    public static function fromThrowable(Throwable $throwable): self
    {
        return new self(
            Condition::Errored,
            'Probe error',
            $throwable->getMessage() !== '' ? $throwable->getMessage() : $throwable::class,
            ['exception' => $throwable::class],
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function withFacts(array $facts): self
    {
        return new self($this->condition, $this->summary, $this->detail, [...$this->facts, ...$facts]);
    }
}
