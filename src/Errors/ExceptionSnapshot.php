<?php

namespace Cinderwatch\Errors;

/**
 * @phpstan-import-type WireFrame from CallSite
 */
class ExceptionSnapshot
{
    /**
     * @param  list<CallSite>  $frames  Innermost call first.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly string $code,
        public readonly array $frames,
        public readonly ?self $cause = null,
    ) {}

    /**
     * @return array{type: string, message: string, code: string, frames: list<WireFrame>, cause: array<string, mixed>|null}
     */
    public function toPayload(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'code' => $this->code,
            'frames' => array_map(static fn (CallSite $site): array => $site->toPayload(), $this->frames),
            'cause' => $this->cause?->toPayload(),
        ];
    }
}
