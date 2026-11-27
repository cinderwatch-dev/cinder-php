<?php

namespace Cinderwatch\Otlp;

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Logs\LogEntry;
use Cinderwatch\Tracing\Span;

class OtlpPayloadBuilder
{
    private const STATUS_UNSET = 0;

    /** W3C trace-flags byte; Cinder ingests every log regardless of trace sampling. */
    private const TRACE_FLAGS = '01';

    public function __construct(
        private readonly CinderConfig $config,
        private readonly AttributeEncoder $encoder = new AttributeEncoder,
    ) {}

    /**
     * @param  list<Span>  $spans
     * @return array{resourceSpans: list<array<string, mixed>>}
     */
    public function traces(array $spans): array
    {
        return [
            'resourceSpans' => [
                $this->wrap('scopeSpans', 'spans', array_map($this->span(...), $spans)),
            ],
        ];
    }

    /**
     * @param  list<LogEntry>  $entries
     * @return array{resourceLogs: list<array<string, mixed>>}
     */
    public function logs(array $entries): array
    {
        return [
            'resourceLogs' => [
                $this->wrap('scopeLogs', 'logRecords', array_map($this->logRecord(...), $entries)),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function wrap(string $scopeListKey, string $itemListKey, array $items): array
    {
        return [
            'resource' => [
                'attributes' => $this->encoder->keyValues($this->config->resourceAttributes()),
                'droppedAttributesCount' => 0,
            ],
            $scopeListKey => [[
                'scope' => [
                    'name' => $this->config->sdkName,
                    'version' => $this->config->sdkVersion,
                    'attributes' => [],
                    'droppedAttributesCount' => 0,
                ],
                $itemListKey => $items,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function span(Span $span): array
    {
        return [
            'traceId' => $span->traceId,
            'spanId' => $span->spanId,
            'parentSpanId' => $span->parentSpanId,
            'name' => $span->name,
            'startTimeUnixNano' => $span->startedAt(),
            'endTimeUnixNano' => $span->endedAt() ?? $span->startedAt(),
            'attributes' => $this->encoder->keyValues($span->attributes()),
            'droppedAttributesCount' => 0,
            'events' => [],
            'droppedEventsCount' => 0,
            'links' => [],
            'droppedLinksCount' => 0,
            'status' => ['code' => self::STATUS_UNSET],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function logRecord(LogEntry $entry): array
    {
        return [
            'timeUnixNano' => $entry->timeUnixNano,
            'observedTimeUnixNano' => $entry->timeUnixNano,
            'traceId' => $entry->traceId,
            'spanId' => $entry->spanId,
            'flags' => self::TRACE_FLAGS,
            'severityText' => $entry->severityText,
            'severityNumber' => $entry->severityNumber,
            'attributes' => $this->encoder->keyValues($entry->attributes),
            'body' => ['stringValue' => $entry->body],
        ];
    }
}
