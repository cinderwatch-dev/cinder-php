<?php

namespace Cinderwatch\Errors;

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Support\IdGenerator;
use Cinderwatch\Support\Redactor;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Support\Time;
use Throwable;

class ErrorReportBuilder
{
    /** How many wrapped causes are described below the reported throwable. */
    public const MAX_CAUSE_DEPTH = 5;

    private readonly StackWalker $stackWalker;

    /** @var list<ReportEnricher> */
    private array $enrichers = [];

    public function __construct(
        private readonly CinderConfig $config,
        ?StackWalker $stackWalker = null,
    ) {
        $this->stackWalker = $stackWalker ?? new StackWalker($config->applicationPath);
    }

    public function addEnricher(ReportEnricher $enricher): self
    {
        $this->enrichers[] = $enricher;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>|null  $fingerprint  An empty list counts as none.
     */
    public function build(
        Throwable $throwable,
        array $context = [],
        ?bool $handled = null,
        ?string $traceId = null,
        ?string $spanId = null,
        ?array $fingerprint = null,
    ): ErrorReport {
        return new ErrorReport(
            eventId: IdGenerator::uuid(),
            occurredAt: Time::rfc3339(),
            handled: $handled,
            exception: $this->snapshot($throwable, 0),
            traceId: $traceId,
            spanId: $traceId === null ? null : ($spanId ?? IdGenerator::spanId()),
            fingerprint: self::normaliseFingerprint($fingerprint),
            context: $this->collectContext($throwable, $context),
            rootPath: $this->config->applicationPath,
            service: (string) $this->config->serviceName,
            serviceVersion: $this->config->serviceVersion,
            environment: $this->config->stage,
            sdkName: $this->config->sdkName,
            sdkVersion: $this->config->sdkVersion,
        );
    }

    /**
     * @param  list<mixed>|null  $fingerprint
     * @return list<string>|null
     */
    public static function normaliseFingerprint(?array $fingerprint): ?array
    {
        if ($fingerprint === null) {
            return null;
        }

        $parts = [];

        foreach ($fingerprint as $part) {
            if (is_scalar($part) && (string) $part !== '') {
                $parts[] = (string) $part;
            }
        }

        return $parts === [] ? null : $parts;
    }

    private function snapshot(Throwable $throwable, int $depth): ExceptionSnapshot
    {
        $previous = $throwable->getPrevious();

        return new ExceptionSnapshot(
            type: $throwable::class,
            message: $throwable->getMessage(),
            code: (string) $throwable->getCode(),
            frames: $this->stackWalker->walk($throwable),
            cause: $previous !== null && $depth < self::MAX_CAUSE_DEPTH
                ? $this->snapshot($previous, $depth + 1)
                : null,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function collectContext(Throwable $throwable, array $context): array
    {
        $layers = [$this->baseContext(), $context];

        foreach ($this->enrichers as $enricher) {
            $layers[] = $enricher->attributesFor($throwable);
        }

        return Redactor::redact(array_merge(...$layers), $this->config->sensitiveKeys);
    }

    /**
     * @return array<string, string>
     */
    private function baseContext(): array
    {
        $console = PHP_SAPI === 'cli';
        $argv = $_SERVER['argv'] ?? null;
        $uri = $_SERVER['REQUEST_URI'] ?? null;

        return array_filter([
            TelemetryAttributeKeys::EntryKind => $console ? 'cli' : 'web',
            TelemetryAttributeKeys::EntryName => $console
                ? (is_array($argv) && is_string($argv[0] ?? null) ? $argv[0] : 'cli')
                : (is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '/'),
            TelemetryAttributeKeys::RuntimeName => 'php',
            TelemetryAttributeKeys::RuntimeVersion => PHP_VERSION,
            TelemetryAttributeKeys::FrameworkName => $this->config->frameworkName,
            TelemetryAttributeKeys::FrameworkVersion => $this->config->frameworkVersion,
            TelemetryAttributeKeys::VcsRevision => $this->config->vcsRevision,
            TelemetryAttributeKeys::VcsBranch => $this->config->vcsBranch,
            TelemetryAttributeKeys::VcsMessage => $this->config->vcsMessage,
        ], static fn (?string $value): bool => $value !== null);
    }
}
