<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Logs\LogEntry;
use Cinderwatch\Logs\Severity;
use Cinderwatch\Otlp\OtlpPayloadBuilder;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Tracing\Span;
use Cinderwatch\Tracing\SpanCategory;

/** The PHP version written into the OTLP fixtures; swapped for the running version before comparing. */
const FIXTURE_PHP_VERSION = '8.4.0';

function contractConfig(): CinderConfig
{
    return new CinderConfig(
        key: 'key',
        serviceName: 'Shop',
        serviceVersion: '1.4.0',
        stage: 'production',
        sdkName: 'cinderwatch/cinder-laravel',
        sdkVersion: '0.2.0',
        frameworkName: 'laravel',
        frameworkVersion: '12.0.0',
        vcsRevision: '4f1c2e9',
        vcsBranch: 'main',
        vcsMessage: 'Ship checkout',
    );
}

function contractFixture(string $name): string
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/'.$name), true, 512, JSON_THROW_ON_ERROR);

    return str_replace('"'.FIXTURE_PHP_VERSION.'"', '"'.PHP_VERSION.'"', (string) json_encode($fixture));
}

it('builds trace payloads identical to the ingest fixture', function () {
    $span = new Span(
        traceId: str_repeat('a', 32),
        spanId: '1000000000000001',
        parentSpanId: null,
        name: 'Request - /orders',
        startedAt: 1700000000000000000,
        attributes: [
            TelemetryAttributeKeys::SpanKind => SpanCategory::WebRequest->value,
            TelemetryAttributeKeys::EntryKind => 'web',
            TelemetryAttributeKeys::EntryName => '/orders',
            TelemetryAttributeKeys::EntryHandler => 'App\Http\Controllers\OrderController@store',
            TelemetryAttributeKeys::EntryRoute => '/orders',
            'http.request.method' => 'POST',
        ],
    );
    $span->finish(1700000000120000000);

    $payload = (new OtlpPayloadBuilder(contractConfig()))->traces([$span]);

    expect(json_encode($payload))->toBe(contractFixture('trace-payload.json'));
})->group('otlp');

it('builds log payloads identical to the ingest fixture', function () {
    $entry = new LogEntry(
        timeUnixNano: 1700000000000000000,
        traceId: str_repeat('a', 32),
        spanId: '1000000000000001',
        severityText: Severity::text('INFO'),
        severityNumber: Severity::number('INFO'),
        body: 'User {user} signed in',
        attributes: [TelemetryAttributeKeys::LogContext => ['user' => 'rhys'], TelemetryAttributeKeys::EntryKind => 'cli'],
    );

    $payload = (new OtlpPayloadBuilder(contractConfig()))->logs([$entry]);

    expect(json_encode($payload))->toBe(contractFixture('log-payload.json'));
})->group('otlp');

it('drops unset resource attributes', function () {
    $attributes = (new OtlpPayloadBuilder(new CinderConfig(key: 'k', serviceName: 'Plain')))->traces([])['resourceSpans'][0]['resource']['attributes'];

    expect(array_column($attributes, 'key'))->toBe([
        'service.name',
        'telemetry.sdk.name',
        'telemetry.sdk.version',
        'telemetry.sdk.language',
        'process.runtime.name',
        'process.runtime.version',
    ])->and($attributes[1]['value'])->toBe(['stringValue' => 'cinderwatch/cinder-php'])
        ->and($attributes[2]['value'])->toBe(['stringValue' => '1.0.0']);
})->group('otlp');

it('uses the contract span kinds', function () {
    expect(array_map(fn (SpanCategory $kind): string => $kind->value, SpanCategory::cases()))
        ->toBe(['route', 'command', 'job', 'query', 'http_client', 'view', 'livewire', 'custom']);
})->group('otlp');

it('maps log levels onto OTLP severity numbers', function (string $level, int $number) {
    expect(Severity::number($level))->toBe($number);
})->with([
    ['trace', 1], ['debug', 5], ['info', 9], ['notice', 10], ['warning', 13],
    ['error', 17], ['critical', 18], ['alert', 19], ['emergency', 21],
])->group('otlp');

it('encodes nested and non-scalar attribute values', function () {
    $span = new Span('t', 's', null, 'App', 1, [
        'list' => [1, null, 'two'],
        'map' => ['enabled' => true, 'ratio' => 0.5],
        'skipped' => null,
    ]);

    $attributes = (new OtlpPayloadBuilder(contractConfig()))->traces([$span])['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'];

    expect($attributes)->toBe([
        ['key' => 'list', 'value' => ['arrayValue' => ['values' => [['intValue' => 1], ['stringValue' => 'two']]]]],
        ['key' => 'map', 'value' => ['kvlistValue' => ['values' => [
            ['key' => 'enabled', 'value' => ['boolValue' => true]],
            ['key' => 'ratio', 'value' => ['doubleValue' => 0.5]],
        ]]]],
    ]);
})->group('otlp');
