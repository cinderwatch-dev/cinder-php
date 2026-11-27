<?php

namespace Cinderwatch\Health;

use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * A probe that throws is recorded as errored so the remaining probes still report.
 */
class Inspector
{
    /** @var array<string, Probe> keyed by probe id, so re-registering an id replaces it */
    private array $probes = [];

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param  iterable<Probe>  $probes
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(iterable $probes = [], ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable;

        foreach ($probes as $probe) {
            $this->register($probe);
        }
    }

    public function register(Probe ...$probes): self
    {
        foreach ($probes as $probe) {
            $this->probes[$probe->id()] = $probe;
        }

        return $this;
    }

    /**
     * @param  iterable<Probe>  $probes
     */
    public function replace(iterable $probes): self
    {
        $this->probes = [];

        foreach ($probes as $probe) {
            $this->register($probe);
        }

        return $this;
    }

    /**
     * @return list<Probe>
     */
    public function probes(): array
    {
        return array_values($this->probes);
    }

    public function inspect(): HealthSnapshot
    {
        $findings = [];

        foreach ($this->probes as $probe) {
            try {
                $observation = $probe->examine();
            } catch (Throwable $throwable) {
                $observation = Observation::fromThrowable($throwable);
            }

            $findings[] = Finding::of($probe, $observation);
        }

        return new HealthSnapshot(($this->clock)(), $findings);
    }
}
