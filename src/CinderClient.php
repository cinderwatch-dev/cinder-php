<?php

namespace Cinderwatch;

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Errors\ErrorReportBuilder;
use Cinderwatch\Errors\ReportEnricher;
use Cinderwatch\Logs\LogBuffer;
use Cinderwatch\Tracing\Tracer;
use Cinderwatch\Transport\BufferedTransport;
use Cinderwatch\Transport\SyncTransport;
use Cinderwatch\Transport\Transport;
use Closure;
use Throwable;

class CinderClient
{
    private readonly ErrorReportBuilder $reports;

    private readonly Tracer $tracer;

    private readonly LogBuffer $logs;

    /** @var Closure(Throwable): bool|null */
    private ?Closure $reportPredicate = null;

    /** @var Closure(Throwable): (list<string>|null)|null */
    private ?Closure $fingerprintResolver = null;

    /** @var list<Closure(array<string, mixed>, Throwable): array<string, mixed>> */
    private array $payloadFilters = [];

    public function __construct(
        private readonly CinderConfig $config,
        private readonly Transport $transport,
        ?ErrorReportBuilder $reports = null,
        ?Tracer $tracer = null,
        ?LogBuffer $logs = null,
    ) {
        $this->reports = $reports ?? new ErrorReportBuilder($config);
        $this->tracer = $tracer ?? new Tracer($config, $transport);
        $this->logs = $logs ?? new LogBuffer($config, $transport, $this->tracer);
    }

    /** Queues payloads in memory and sends them at shutdown, or when you call flush(). */
    public static function make(CinderConfig|string $config, ?Transport $transport = null): self
    {
        $config = is_string($config) ? CinderConfig::make($config) : $config;

        return new self($config, $transport ?? new BufferedTransport(new SyncTransport($config)));
    }

    public function config(): CinderConfig
    {
        return $this->config;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function tracer(): Tracer
    {
        return $this->tracer;
    }

    public function logs(): LogBuffer
    {
        return $this->logs;
    }

    public function addEnricher(ReportEnricher $enricher): self
    {
        $this->reports->addEnricher($enricher);

        return $this;
    }

    /**
     * @param  Closure(Throwable): bool  $predicate
     */
    public function reportOnlyWhen(Closure $predicate): self
    {
        $this->reportPredicate = $predicate;

        return $this;
    }

    /**
     * @param  Closure(array<string, mixed>, Throwable): array<string, mixed>  $filter
     */
    public function beforeSend(Closure $filter): self
    {
        $this->payloadFilters[] = $filter;

        return $this;
    }

    public function shouldReport(Throwable $throwable): bool
    {
        if (! $this->config->reportErrors) {
            return false;
        }

        return $this->reportPredicate === null || (bool) ($this->reportPredicate)($throwable);
    }

    /**
     * Return null or an empty list from the resolver to keep the default grouping.
     *
     * @param  Closure(Throwable): (list<string>|null)  $resolver
     */
    public function fingerprintUsing(Closure $resolver): self
    {
        $this->fingerprintResolver = $resolver;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>|null  $fingerprint
     * @return array<string, mixed>|null
     */
    public function report(Throwable $throwable, array $context = [], ?bool $handled = null, ?array $fingerprint = null): ?array
    {
        if (! $this->shouldReport($throwable)) {
            return null;
        }

        $payload = $this->reports->build(
            $throwable,
            [...$this->tracer->rootEntryAttributes(), ...$context],
            $handled,
            $this->tracer->activeTraceId(),
            $this->tracer->activeSpanId(),
            $fingerprint ?? $this->resolveFingerprint($throwable),
        )->toPayload();

        foreach ($this->payloadFilters as $filter) {
            $payload = $filter($payload, $throwable);
        }

        $this->transport->send($this->config->errorsUrl(), $payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>|null  $fingerprint
     * @return array<string, mixed>
     */
    public function buildReport(Throwable $throwable, array $context = [], ?bool $handled = null, ?array $fingerprint = null): array
    {
        return $this->reports->build(
            $throwable,
            $context,
            $handled,
            fingerprint: $fingerprint ?? $this->resolveFingerprint($throwable),
        )->toPayload();
    }

    /**
     * @return list<string>|null
     */
    private function resolveFingerprint(Throwable $throwable): ?array
    {
        return $this->fingerprintResolver === null ? null : ($this->fingerprintResolver)($throwable);
    }

    public function flush(): void
    {
        $this->tracer->flush();
        $this->logs->flush();

        if ($this->transport instanceof BufferedTransport) {
            $this->transport->flush();
        }
    }
}
