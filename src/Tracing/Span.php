<?php

namespace Cinderwatch\Tracing;

use Cinderwatch\Support\Time;

class Span
{
    private ?int $endedAt = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $name,
        private readonly int $startedAt,
        private array $attributes = [],
    ) {}

    public function startedAt(): int
    {
        return $this->startedAt;
    }

    /** Unix nanoseconds, or null while the span is open. */
    public function endedAt(): ?int
    {
        return $this->endedAt;
    }

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function attribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Ignored once the span has finished.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function annotate(array $attributes): self
    {
        if ($this->isOpen()) {
            $this->attributes = [...$this->attributes, ...$attributes];
        }

        return $this;
    }

    /** The first call wins, later calls are no-ops. */
    public function finish(?int $endedAt = null): self
    {
        if ($this->isOpen()) {
            $this->endedAt = max($this->startedAt, $endedAt ?? Time::unixNano());
        }

        return $this;
    }
}
