<?php

namespace Cinderwatch\Errors;

/**
 * @phpstan-type WireSource array{start_line: int, lines: list<string>}
 * @phpstan-type WireFrame array{path: string, line: int, function: string, class: string|null, in_app: bool, source: WireSource|null}
 */
class CallSite
{
    /**
     * @param  array<int, string>  $excerpt  Consecutive source lines keyed by their 1-based line number.
     */
    public function __construct(
        public readonly string $path,
        public readonly int $line,
        public readonly string $function,
        public readonly ?string $className,
        public readonly array $excerpt,
        public readonly bool $inApplication,
    ) {}

    /**
     * @return WireFrame
     */
    public function toPayload(): array
    {
        return [
            'path' => $this->path,
            'line' => $this->line,
            'function' => $this->function,
            'class' => $this->className,
            'in_app' => $this->inApplication,
            'source' => $this->source(),
        ];
    }

    /**
     * @return WireSource|null
     */
    private function source(): ?array
    {
        if ($this->excerpt === []) {
            return null;
        }

        return [
            'start_line' => (int) array_key_first($this->excerpt),
            'lines' => array_values($this->excerpt),
        ];
    }
}
