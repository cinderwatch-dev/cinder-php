<?php

namespace Cinderwatch\Errors;

class ErrorReport
{
    /**
     * @param  string  $occurredAt  RFC 3339 UTC timestamp with microseconds.
     * @param  list<string>|null  $fingerprint  Overrides server-side grouping when set.
     * @param  array<string, mixed>  $context  Flat map of dotted keys.
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $occurredAt,
        public readonly ?bool $handled,
        public readonly ExceptionSnapshot $exception,
        public readonly ?string $traceId,
        public readonly ?string $spanId,
        public readonly ?array $fingerprint,
        public readonly array $context,
        public readonly ?string $rootPath,
        public readonly string $service,
        public readonly ?string $serviceVersion,
        public readonly ?string $environment,
        public readonly string $sdkName,
        public readonly string $sdkVersion,
    ) {}

    /**
     * Key order mirrors the ingest contract.
     *
     * @return array{
     *     event_id: string,
     *     occurred_at: string,
     *     handled: bool|null,
     *     exception: array<string, mixed>,
     *     trace: array{trace_id: string, span_id: string}|null,
     *     fingerprint: list<string>|null,
     *     context: array<string, mixed>,
     *     runtime: array{root_path: string|null, service: string, version: string|null, environment: string|null},
     *     sdk: array{name: string, version: string},
     * }
     */
    public function toPayload(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'handled' => $this->handled,
            'exception' => $this->exception->toPayload(),
            'trace' => $this->traceId !== null && $this->spanId !== null
                ? ['trace_id' => $this->traceId, 'span_id' => $this->spanId]
                : null,
            'fingerprint' => $this->fingerprint,
            'context' => $this->context,
            'runtime' => [
                'root_path' => $this->rootPath,
                'service' => $this->service,
                'version' => $this->serviceVersion,
                'environment' => $this->environment,
            ],
            'sdk' => [
                'name' => $this->sdkName,
                'version' => $this->sdkVersion,
            ],
        ];
    }
}
