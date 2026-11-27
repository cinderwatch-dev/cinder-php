<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Errors\CallSite;
use Cinderwatch\Errors\ErrorReport;
use Cinderwatch\Errors\ErrorReportBuilder;
use Cinderwatch\Errors\ExceptionSnapshot;
use Cinderwatch\Errors\ReportEnricher;

class ReportProbe
{
    public function explode(): never
    {
        throw new RuntimeException('Self-hosted ingress contract test');
    }
}

function errorFixture(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/error-report.json'), true, 512, JSON_THROW_ON_ERROR);
}

it('serialises a report exactly as the ingest fixture', function () {
    $report = new ErrorReport(
        eventId: '0f4b2b0e-6a43-4d63-9a43-6f3f3f1f9b1c',
        occurredAt: '2026-11-23T16:00:00.123456Z',
        handled: false,
        exception: new ExceptionSnapshot(
            type: 'RuntimeException',
            message: 'Order could not be stored',
            code: '0',
            frames: [
                new CallSite('/var/www/app/Http/Controllers/OrderController.php', 42, 'store', 'App\Http\Controllers\OrderController', [
                    41 => '        try {',
                    42 => "            throw new RuntimeException('Order could not be stored', 0, \$previous);",
                    43 => '        }',
                ], true),
                new CallSite('/var/www/vendor/laravel/framework/src/Illuminate/Routing/Controller.php', 54, 'callAction', 'Illuminate\Routing\Controller', [], false),
                new CallSite('/var/www/public/index.php', 17, '{main}', null, [], true),
            ],
            cause: new ExceptionSnapshot(
                type: 'PDOException',
                message: 'SQLSTATE[23000]: Integrity constraint violation',
                code: '23000',
                frames: [new CallSite('/var/www/app/Models/Order.php', 88, 'save', 'App\Models\Order', [], true)],
            ),
        ),
        traceId: str_repeat('a', 32),
        spanId: '1000000000000001',
        fingerprint: ['orders', 'store'],
        context: [
            'cinder.entry.kind' => 'web',
            'cinder.entry.name' => '/orders',
            'cinder.entry.handler' => 'App\Http\Controllers\OrderController@store',
            'cinder.entry.route' => '/orders',
            'http.request.method' => 'POST',
            'url.path' => '/orders',
            'user.id' => '42',
            'vcs.ref.head.revision' => '4f1c2e9',
            'vcs.ref.head.name' => 'main',
            'cinder.vcs.message' => 'Ship checkout',
            'process.runtime.name' => 'php',
            'process.runtime.version' => '8.4.0',
            'cinder.framework.name' => 'laravel',
            'cinder.framework.version' => '12.0.0',
        ],
        rootPath: '/var/www',
        service: 'Shop',
        serviceVersion: '1.4.0',
        environment: 'production',
        sdkName: 'cinderwatch/cinder-laravel',
        sdkVersion: '0.2.0',
    );

    expect($report->toPayload())->toBe(errorFixture());
})->group('errors');

it('builds payloads with the same structure as the fixture', function () {
    $config = new CinderConfig(key: 'k', serviceName: 'Shop', serviceVersion: '1.4.0', stage: 'production', applicationPath: '/var/www', frameworkName: 'laravel', frameworkVersion: '12.0.0', vcsRevision: '4f1c2e9', vcsBranch: 'main', vcsMessage: 'Ship checkout');
    $exception = new RuntimeException('outer', 0, new LogicException('inner', 7));

    $payload = (new ErrorReportBuilder($config))->build($exception, [], false, str_repeat('a', 32), '1000000000000001', ['a'])->toPayload();
    $fixture = errorFixture();

    expect(array_keys($payload))->toBe(array_keys($fixture))
        ->and(array_keys($payload['exception']))->toBe(array_keys($fixture['exception']))
        ->and(array_keys($payload['exception']['frames'][0]))->toBe(array_keys($fixture['exception']['frames'][0]))
        ->and(array_keys($payload['exception']['cause']))->toBe(array_keys($fixture['exception']['cause']))
        ->and(array_keys($payload['runtime']))->toBe(array_keys($fixture['runtime']))
        ->and(array_keys($payload['trace']))->toBe(['trace_id', 'span_id'])
        ->and($payload['event_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($payload['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        ->and($payload['exception']['cause']['code'])->toBe('7')
        ->and($payload['runtime'])->toBe(['root_path' => '/var/www', 'service' => 'Shop', 'version' => '1.4.0', 'environment' => 'production'])
        ->and($payload['sdk'])->toBe(['name' => 'cinderwatch/cinder-php', 'version' => CinderConfig::SDK_VERSION])
        ->and($payload['context'])->toMatchArray([
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
            'cinder.framework.name' => 'laravel',
            'cinder.framework.version' => '12.0.0',
            'vcs.ref.head.revision' => '4f1c2e9',
            'vcs.ref.head.name' => 'main',
            'cinder.vcs.message' => 'Ship checkout',
        ])
        ->and($payload['context'])->toHaveKey('cinder.entry.kind');
})->group('errors');

it('attributes the throw site to the function that threw, with a source excerpt', function () {
    try {
        (new ReportProbe)->explode();
    } catch (RuntimeException $exception) {
        $payload = (new ErrorReportBuilder(CinderConfig::make('key')))->build($exception)->toPayload();
    }

    $top = $payload['exception']['frames'][0];
    $source = $top['source'];

    expect($top['path'])->toBe(__FILE__)
        ->and($top['function'])->toBe('explode')
        ->and($top['class'])->toBe(ReportProbe::class)
        ->and($top['in_app'])->toBeTrue()
        ->and($source['start_line'])->toBe($top['line'] - 5)
        ->and($source['lines'])->toHaveCount(11)
        ->and($source['lines'][$top['line'] - $source['start_line']])->toContain("throw new RuntimeException('Self-hosted ingress contract test');")
        ->and($payload['exception']['frames'][1]['path'])->toBe(__FILE__);
})->group('errors');

it('emits a null source for frames whose file cannot be read', function () {
    expect((new CallSite('/does/not/exist.php', 3, 'f', null, [], true))->toPayload()['source'])->toBeNull();
})->group('errors');

it('nests wrapped exceptions as causes, capped at five levels', function () {
    $exception = new LogicException('level 7');

    foreach (range(6, 0) as $level) {
        $exception = new RuntimeException("level {$level}", $level, $exception);
    }

    $payload = (new ErrorReportBuilder(CinderConfig::make('key')))->build($exception)->toPayload();

    $messages = [];
    $node = $payload['exception'];

    while ($node !== null) {
        $messages[] = $node['message'];
        expect($node['frames'])->not->toBeEmpty();
        $node = $node['cause'];
    }

    expect($messages)->toBe(['level 0', 'level 1', 'level 2', 'level 3', 'level 4', 'level 5'])
        ->and($payload['exception']['cause']['code'])->toBe('1');
})->group('errors');

it('sends a null trace when no trace is active', function () {
    $payload = (new ErrorReportBuilder(CinderConfig::make('key')))->build(new RuntimeException('boom'))->toPayload();

    expect($payload['trace'])->toBeNull()
        ->and($payload['fingerprint'])->toBeNull()
        ->and($payload['handled'])->toBeNull();
})->group('errors');

it('normalises fingerprints to a non-empty list of strings or null', function (?array $given, ?array $expected) {
    $payload = (new ErrorReportBuilder(CinderConfig::make('key')))->build(new RuntimeException('boom'), fingerprint: $given)->toPayload();

    expect($payload['fingerprint'])->toBe($expected);
})->with([
    [null, null],
    [[], null],
    [['', null], null],
    [['checkout', 42], ['checkout', '42']],
])->group('errors');

it('merges enricher context and redacts sensitive keys', function () {
    $builder = (new ErrorReportBuilder(CinderConfig::make('key')))->addEnricher(new class implements ReportEnricher
    {
        public function attributesFor(Throwable $throwable): array
        {
            return ['request.id' => 'abc', 'token' => 'leak'];
        }
    });

    $context = $builder->build(new RuntimeException('boom'), ['password' => 'secret', 'user' => 'rhys'])->context;

    expect($context['password'])->toBe('[redacted]')
        ->and($context['token'])->toBe('[redacted]')
        ->and($context['user'])->toBe('rhys')
        ->and($context['request.id'])->toBe('abc');
})->group('errors');
