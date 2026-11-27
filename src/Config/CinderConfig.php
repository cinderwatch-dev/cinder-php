<?php

namespace Cinderwatch\Config;

use Cinderwatch\Support\TelemetryAttributeKeys;

class CinderConfig
{
    /** Composer package name reported as `telemetry.sdk.name` when no adapter overrides it. */
    public const SDK_NAME = 'cinderwatch/cinder-php';

    public const SDK_VERSION = '1.0.0';

    public function __construct(
        public string $key,
        public string $baseUrl = 'https://cinderwatch.dev',
        public ?string $serviceName = null,
        public ?string $serviceVersion = null,
        public ?string $stage = null,
        public bool $reportErrors = true,
        public bool $reportTraces = true,
        public bool $reportLogs = true,
        public float $traceSampleRate = 1.0,
        public int $timeoutSeconds = 5,
        public int $connectTimeoutSeconds = 2,
        public int $maxRetries = 1,
        public bool $gzip = true,
        /** @var list<string> */
        public array $sensitiveKeys = [
            'password',
            'password_confirmation',
            'current_password',
            'secret',
            'token',
            'api_key',
            'authorization',
            'cookie',
            'credit_card',
        ],
        public ?string $applicationPath = null,
        public string $sdkName = self::SDK_NAME,
        public string $sdkVersion = self::SDK_VERSION,
        /** `laravel`, `wordpress`, or null for plain PHP. */
        public ?string $frameworkName = null,
        public ?string $frameworkVersion = null,
        /** Deployed commit hash, branch and subject. */
        public ?string $vcsRevision = null,
        public ?string $vcsBranch = null,
        public ?string $vcsMessage = null,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->serviceName ??= 'PHP application';
    }

    public static function make(string $key): self
    {
        return new self($key);
    }

    public function withBaseUrl(string $baseUrl): self
    {
        $clone = clone $this;
        $clone->baseUrl = rtrim($baseUrl, '/');

        return $clone;
    }

    public function withStage(?string $stage): self
    {
        $clone = clone $this;
        $clone->stage = $stage;

        return $clone;
    }

    public function withServiceName(string $serviceName): self
    {
        $clone = clone $this;
        $clone->serviceName = $serviceName;

        return $clone;
    }

    public function withApplicationPath(?string $applicationPath): self
    {
        $clone = clone $this;
        $clone->applicationPath = $applicationPath;

        return $clone;
    }

    /** @param list<string> $keys */
    public function withSensitiveKeys(array $keys): self
    {
        $clone = clone $this;
        $clone->sensitiveKeys = $keys;

        return $clone;
    }

    /**
     * Null values are dropped when the resource is encoded.
     *
     * @return array<string, string|null>
     */
    public function resourceAttributes(): array
    {
        return [
            TelemetryAttributeKeys::ServiceName => $this->serviceName,
            TelemetryAttributeKeys::ServiceVersion => $this->serviceVersion,
            TelemetryAttributeKeys::DeploymentEnvironment => $this->stage,
            TelemetryAttributeKeys::SdkName => $this->sdkName,
            TelemetryAttributeKeys::SdkVersion => $this->sdkVersion,
            TelemetryAttributeKeys::SdkLanguage => 'php',
            TelemetryAttributeKeys::RuntimeName => 'php',
            TelemetryAttributeKeys::RuntimeVersion => PHP_VERSION,
            TelemetryAttributeKeys::FrameworkName => $this->frameworkName,
            TelemetryAttributeKeys::FrameworkVersion => $this->frameworkVersion,
            TelemetryAttributeKeys::VcsRevision => $this->vcsRevision,
            TelemetryAttributeKeys::VcsBranch => $this->vcsBranch,
            TelemetryAttributeKeys::VcsMessage => $this->vcsMessage,
        ];
    }

    public function apiPrefix(): string
    {
        return $this->baseUrl.'/api/v1';
    }

    public function errorsUrl(): string
    {
        return $this->apiPrefix().'/errors';
    }

    public function logsUrl(): string
    {
        return $this->apiPrefix().'/logs';
    }

    public function tracesUrl(): string
    {
        return $this->apiPrefix().'/traces';
    }
}
