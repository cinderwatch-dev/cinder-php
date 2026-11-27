<?php

namespace Cinderwatch\Logs;

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Otlp\OtlpPayloadBuilder;
use Cinderwatch\Support\IdGenerator;
use Cinderwatch\Support\Redactor;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Support\Time;
use Cinderwatch\Tracing\Tracer;
use Cinderwatch\Transport\Transport;

/**
 * Each line is linked to the span that is active when it is written.
 */
class LogBuffer
{
    /** Export early once this many lines are waiting, to bound memory in long processes. */
    private const EXPORT_BATCH_LIMIT = 500;

    /** @var list<LogEntry> */
    private array $entries = [];

    private readonly OtlpPayloadBuilder $payloads;

    public function __construct(
        private readonly CinderConfig $config,
        private readonly Transport $transport,
        private readonly Tracer $tracer,
        ?OtlpPayloadBuilder $payloads = null,
    ) {
        $this->payloads = $payloads ?? new OtlpPayloadBuilder($config);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function write(string $message, string $level = 'info', array $context = []): void
    {
        if (! $this->config->reportLogs) {
            return;
        }

        $this->entries[] = new LogEntry(
            timeUnixNano: Time::unixNano(),
            traceId: $this->tracer->activeTraceId() ?? IdGenerator::traceId(),
            spanId: $this->tracer->activeSpanId() ?? IdGenerator::spanId(),
            severityText: Severity::text($level),
            severityNumber: Severity::number($level),
            body: $message,
            attributes: [
                TelemetryAttributeKeys::LogContext => Redactor::redact($context, $this->config->sensitiveKeys),
                TelemetryAttributeKeys::EntryKind => PHP_SAPI === 'cli' ? 'cli' : 'web',
                ...$this->tracer->rootEntryAttributes(),
            ],
        );

        if (count($this->entries) >= self::EXPORT_BATCH_LIMIT) {
            $this->flush();
        }
    }

    public function pending(): int
    {
        return count($this->entries);
    }

    public function flush(): void
    {
        if ($this->entries === []) {
            return;
        }

        $entries = $this->entries;
        $this->entries = [];

        $this->transport->send($this->config->logsUrl(), $this->payloads->logs($entries));
    }
}
