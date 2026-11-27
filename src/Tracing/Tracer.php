<?php

namespace Cinderwatch\Tracing;

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Otlp\OtlpPayloadBuilder;
use Cinderwatch\Support\IdGenerator;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Support\Time;
use Cinderwatch\Transport\Transport;

/**
 * The first span opened is the root and decides whether the trace is sampled. Closing the
 * last open span ends the trace. Unsampled traces still hand out ids so logs and errors correlate.
 */
class Tracer
{
    /** Export early once this many spans are waiting, to bound memory in long traces. */
    private const EXPORT_BATCH_LIMIT = 1000;

    /** @var list<Span> Spans currently open, outermost first. */
    private array $openSpans = [];

    /** @var list<Span> Finished spans of sampled traces awaiting export. */
    private array $exportQueue = [];

    private ?string $traceId = null;

    private bool $sampled = false;

    /** @var array<string, mixed> Entry-point attributes declared by the root span. */
    private array $rootEntry = [];

    private readonly OtlpPayloadBuilder $payloads;

    public function __construct(
        private readonly CinderConfig $config,
        private readonly Transport $transport,
        ?OtlpPayloadBuilder $payloads = null,
    ) {
        $this->payloads = $payloads ?? new OtlpPayloadBuilder($config);
    }

    public function hasActiveTrace(): bool
    {
        return $this->traceId !== null;
    }

    public function activeTraceId(): ?string
    {
        return $this->traceId;
    }

    public function activeSpanId(): ?string
    {
        $innermost = end($this->openSpans);

        return $innermost === false ? null : $innermost->spanId;
    }

    /** `web`, `cli`, `queue` or `cron`, as declared by the root span. */
    public function rootEntryType(): ?string
    {
        $kind = $this->rootEntry[TelemetryAttributeKeys::EntryKind] ?? null;

        return is_string($kind) && $kind !== '' ? $kind : null;
    }

    /**
     * Copied onto errors and logs raised inside the trace. Empty outside a trace.
     *
     * @return array<string, mixed>
     */
    public function rootEntryAttributes(): array
    {
        return $this->rootEntry;
    }

    public function isRecording(): bool
    {
        return $this->traceId !== null && $this->sampled;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function open(string $name, array $attributes = []): Span
    {
        if ($this->traceId === null) {
            $this->beginTrace($attributes);
        }

        $span = new Span(
            traceId: (string) $this->traceId,
            spanId: IdGenerator::spanId(),
            parentSpanId: $this->activeSpanId(),
            name: $name,
            startedAt: Time::unixNano(),
            attributes: $attributes,
        );

        $this->openSpans[] = $span;

        return $span;
    }

    public function close(Span $span, ?int $endedAt = null): void
    {
        $position = array_search($span, $this->openSpans, true);

        if ($position === false) {
            return;
        }

        array_splice($this->openSpans, $position, 1);
        $this->queue($span->finish($endedAt));

        if ($this->openSpans === []) {
            $this->endTrace();
        }
    }

    /**
     * Records work that already happened, such as a query. Does nothing outside a trace.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordCompleted(string $name, array $attributes, int $startedAt, int $endedAt): ?Span
    {
        if ($this->traceId === null) {
            return null;
        }

        $span = new Span(
            traceId: $this->traceId,
            spanId: IdGenerator::spanId(),
            parentSpanId: $this->activeSpanId(),
            name: $name,
            startedAt: $startedAt,
            attributes: $attributes,
        );

        $this->queue($span->finish($endedAt));

        return $span;
    }

    public function flush(): void
    {
        if ($this->exportQueue === []) {
            return;
        }

        $spans = $this->exportQueue;
        $this->exportQueue = [];

        $this->transport->send($this->config->tracesUrl(), $this->payloads->traces($spans));
    }

    /**
     * @param  array<string, mixed>  $rootAttributes
     */
    private function beginTrace(array $rootAttributes): void
    {
        $this->traceId = IdGenerator::traceId();
        $this->sampled = $this->rollSample();

        $this->rootEntry = array_filter(
            array_intersect_key($rootAttributes, array_flip([
                TelemetryAttributeKeys::EntryKind,
                TelemetryAttributeKeys::EntryName,
                TelemetryAttributeKeys::EntryHandler,
                TelemetryAttributeKeys::EntryRoute,
            ])),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    private function endTrace(): void
    {
        $this->flush();

        $this->traceId = null;
        $this->sampled = false;
        $this->rootEntry = [];
    }

    private function rollSample(): bool
    {
        $rate = $this->config->traceSampleRate;

        if (! $this->config->reportTraces || $rate <= 0.0) {
            return false;
        }

        return $rate >= 1.0 || random_int(1, 1_000_000) <= (int) round($rate * 1_000_000);
    }

    private function queue(Span $span): void
    {
        if (! $this->sampled) {
            return;
        }

        $this->exportQueue[] = $span;

        if (count($this->exportQueue) >= self::EXPORT_BATCH_LIMIT) {
            $this->flush();
        }
    }
}
