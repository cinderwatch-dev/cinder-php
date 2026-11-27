<?php

namespace Cinderwatch\Health;

class Finding
{
    public function __construct(
        public readonly string $probeId,
        public readonly string $probeTitle,
        public readonly Observation $observation,
        public readonly ?string $node = null,
    ) {}

    public static function of(Probe $probe, Observation $observation): self
    {
        return new self($probe->id(), $probe->title(), $observation);
    }

    public function condition(): Condition
    {
        return $this->observation->condition;
    }

    public function retitled(string $title): self
    {
        return new self($this->probeId, $title, $this->observation, $this->node);
    }

    public function onNode(string $node): self
    {
        return new self($this->probeId, $this->probeTitle, $this->observation, $node);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function withFacts(array $facts): self
    {
        return new self($this->probeId, $this->probeTitle, $this->observation->withFacts($facts), $this->node);
    }
}
