<?php

use Cinderwatch\Config\CinderConfig;

it('trims the base url and names the service by default', function () {
    $config = new CinderConfig(key: 'key', baseUrl: 'https://cinder.test/');

    expect($config->baseUrl)->toBe('https://cinder.test')
        ->and($config->serviceName)->toBe('PHP application')
        ->and($config->apiPrefix())->toBe('https://cinder.test/api/v1')
        ->and($config->errorsUrl())->toBe('https://cinder.test/api/v1/errors')
        ->and($config->logsUrl())->toBe('https://cinder.test/api/v1/logs')
        ->and($config->tracesUrl())->toBe('https://cinder.test/api/v1/traces');
})->group('config');

it('returns changed copies from the with methods', function () {
    $original = CinderConfig::make('key');

    $changed = $original
        ->withBaseUrl('https://self-hosted.test/')
        ->withStage('staging')
        ->withServiceName('Shop')
        ->withApplicationPath('/var/www')
        ->withSensitiveKeys(['pin']);

    expect($changed->baseUrl)->toBe('https://self-hosted.test')
        ->and($changed->stage)->toBe('staging')
        ->and($changed->serviceName)->toBe('Shop')
        ->and($changed->applicationPath)->toBe('/var/www')
        ->and($changed->sensitiveKeys)->toBe(['pin'])
        ->and($original->baseUrl)->toBe('https://cinderwatch.dev')
        ->and($original->stage)->toBeNull()
        ->and($original->serviceName)->toBe('PHP application')
        ->and($original->applicationPath)->toBeNull()
        ->and($original->sensitiveKeys)->toContain('password');
})->group('config');

it('describes the service as an otlp resource', function () {
    $config = new CinderConfig(key: 'key', serviceName: 'Shop', serviceVersion: '1.4.0', stage: 'production', frameworkName: 'laravel');

    expect($config->resourceAttributes())->toBe([
        'service.name' => 'Shop',
        'service.version' => '1.4.0',
        'deployment.environment.name' => 'production',
        'telemetry.sdk.name' => 'cinderwatch/cinder-php',
        'telemetry.sdk.version' => CinderConfig::SDK_VERSION,
        'telemetry.sdk.language' => 'php',
        'process.runtime.name' => 'php',
        'process.runtime.version' => PHP_VERSION,
        'cinder.framework.name' => 'laravel',
        'cinder.framework.version' => null,
        'vcs.ref.head.revision' => null,
        'vcs.ref.head.name' => null,
        'cinder.vcs.message' => null,
    ]);
})->group('config');
