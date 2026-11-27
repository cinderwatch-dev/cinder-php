<?php

use Cinderwatch\CinderClient;
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Errors\ReportEnricher;
use Cinderwatch\Transport\ArrayTransport;
use Cinderwatch\Transport\BufferedTransport;

it('sends error reports through the transport when allowed', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(CinderConfig::make('token')->withBaseUrl('https://cinder.test'), $transport);

    $client->report(new RuntimeException('boom'));

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['url'])->toBe('https://cinder.test/api/v1/errors')
        ->and($transport->sent[0]['payload']['exception']['message'])->toBe('boom')
        ->and($transport->sent[0]['payload']['trace'])->toBeNull();
})->group('client');

it('skips throwables rejected by the report predicate', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(CinderConfig::make('token'), $transport);
    $client->reportOnlyWhen(fn (Throwable $e): bool => false);

    expect($client->report(new RuntimeException('boom')))->toBeNull()
        ->and($transport->sent)->toBeEmpty();
})->group('client');

it('records and flushes logs', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(CinderConfig::make('token')->withBaseUrl('https://cinder.test'), $transport);

    $client->logs()->write('hello', 'info', ['user' => 'rhys']);
    $client->logs()->flush();

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['url'])->toBe('https://cinder.test/api/v1/logs')
        ->and($transport->sent[0]['payload'])->toHaveKey('resourceLogs');
})->group('client');

it('drains the buffered transport after exporting pending logs', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(
        CinderConfig::make('token')->withBaseUrl('https://cinder.test'),
        new BufferedTransport($transport),
    );

    $client->logs()->write('hello', 'info');
    $client->flush();

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['url'])->toBe('https://cinder.test/api/v1/logs');
})->group('client');

it('links logs written during a trace to the active span', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(new CinderConfig(key: 'token', baseUrl: 'https://cinder.test', traceSampleRate: 0.0), $transport);

    $span = $client->tracer()->open('Job - Probe', ['cinder.entry.kind' => 'queue', 'cinder.entry.name' => 'App\\Jobs\\Probe']);
    $client->logs()->write('inside', 'warning');
    $client->tracer()->close($span);
    $client->logs()->flush();

    $record = $transport->sent[0]['payload']['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];

    expect($record['traceId'])->toBe($span->traceId)
        ->and($record['spanId'])->toBe($span->spanId)
        ->and($record['severityNumber'])->toBe(13)
        ->and($record['attributes'][0]['key'])->toBe('cinder.log.context')
        ->and($record['attributes'][1])->toBe(['key' => 'cinder.entry.kind', 'value' => ['stringValue' => 'queue']])
        ->and($record['attributes'][2])->toBe(['key' => 'cinder.entry.name', 'value' => ['stringValue' => 'App\\Jobs\\Probe']]);
})->group('client');

it('applies before-send filters to the queued payload', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(CinderConfig::make('token'), $transport);
    $client->beforeSend(fn (array $payload, Throwable $e): array => [...$payload, 'context' => ['scrubbed' => true]]);

    $client->report(new RuntimeException('secret detail'));

    expect($transport->sent[0]['payload']['context'])->toBe(['scrubbed' => true]);
})->group('client');

it('links errors raised inside a trace and copies the entry point onto them', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(new CinderConfig(key: 'token', traceSampleRate: 0.0), $transport);

    $span = $client->tracer()->open('Job - Probe', ['cinder.entry.kind' => 'queue', 'cinder.entry.name' => 'App\\Jobs\\Probe']);
    $payload = $client->report(new RuntimeException('boom'));
    $client->tracer()->close($span);

    expect($payload['trace'])->toBe(['trace_id' => $span->traceId, 'span_id' => $span->spanId])
        ->and($payload['context']['cinder.entry.kind'])->toBe('queue')
        ->and($payload['context']['cinder.entry.name'])->toBe('App\\Jobs\\Probe');
})->group('client');

it('sets a custom fingerprint per report or through a resolver', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(CinderConfig::make('token'), $transport);

    expect($client->report(new RuntimeException('a'), fingerprint: ['checkout'])['fingerprint'])->toBe(['checkout']);

    $client->fingerprintUsing(fn (Throwable $e): array => ['by-class', $e::class]);

    expect($client->report(new LogicException('b'))['fingerprint'])->toBe(['by-class', LogicException::class])
        ->and($client->report(new LogicException('c'), fingerprint: ['explicit'])['fingerprint'])->toBe(['explicit']);
})->group('client');

it('makes a buffered client from a key or a config', function () {
    $fromKey = CinderClient::make('key');
    $config = CinderConfig::make('other')->withBaseUrl('https://cinder.test');
    $transport = new ArrayTransport;
    $fromConfig = CinderClient::make($config, $transport);

    expect($fromKey->config()->key)->toBe('key')
        ->and($fromKey->transport())->toBeInstanceOf(BufferedTransport::class)
        ->and($fromConfig->config())->toBe($config)
        ->and($fromConfig->transport())->toBe($transport);
})->group('client');

it('reports nothing when error reporting is off', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(new CinderConfig(key: 'token', reportErrors: false), $transport);

    expect($client->shouldReport(new RuntimeException('boom')))->toBeFalse()
        ->and($client->report(new RuntimeException('boom')))->toBeNull()
        ->and($transport->sent)->toBeEmpty();
})->group('client');

it('builds a report without sending it', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(CinderConfig::make('token'), $transport);
    $client->fingerprintUsing(fn (Throwable $e): array => ['by-class', $e::class]);

    $payload = $client->buildReport(new RuntimeException('boom'), ['order.id' => 42], handled: true);

    expect($payload['exception']['message'])->toBe('boom')
        ->and($payload['handled'])->toBeTrue()
        ->and($payload['context']['order.id'])->toBe(42)
        ->and($payload['fingerprint'])->toBe(['by-class', RuntimeException::class])
        ->and($client->buildReport(new RuntimeException('boom'), fingerprint: ['explicit'])['fingerprint'])->toBe(['explicit'])
        ->and($transport->sent)->toBeEmpty();
})->group('client');

it('adds enricher attributes to reported errors', function () {
    $transport = new ArrayTransport;
    $client = (new CinderClient(CinderConfig::make('token'), $transport))->addEnricher(new class implements ReportEnricher
    {
        public function attributesFor(Throwable $throwable): array
        {
            return ['tenant.id' => 'acme'];
        }
    });

    $client->report(new RuntimeException('boom'));

    expect($transport->sent[0]['payload']['context']['tenant.id'])->toBe('acme');
})->group('client');

it('exports finished spans when flushing', function () {
    $transport = new ArrayTransport;
    $client = new CinderClient(CinderConfig::make('token')->withBaseUrl('https://cinder.test'), $transport);

    $client->tracer()->open('Request - /');
    $client->tracer()->recordCompleted('Query', [], 1, 2);
    $client->flush();

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['url'])->toBe('https://cinder.test/api/v1/traces');
})->group('client');
