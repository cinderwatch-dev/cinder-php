<?php

namespace Cinderwatch\Health;

/**
 * Lets the same probe type be registered twice, e.g. for two database connections.
 */
class AliasedProbe implements Probe
{
    public function __construct(
        private readonly Probe $inner,
        private readonly string $id,
        private readonly ?string $title = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title ?? $this->inner->title();
    }

    public function examine(): Observation
    {
        return $this->inner->examine();
    }
}
