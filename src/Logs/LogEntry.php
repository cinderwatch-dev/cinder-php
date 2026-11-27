<?php

namespace Cinderwatch\Logs;

class LogEntry
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly int $timeUnixNano,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly string $severityText,
        public readonly int $severityNumber,
        public readonly string $body,
        public readonly array $attributes = [],
    ) {}
}
