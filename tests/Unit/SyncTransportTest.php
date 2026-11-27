<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Transport\SyncTransport;
use Http\Client\Exception\TransferException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

function syncTransport(MockClient $http): SyncTransport
{
    $factory = new Psr17Factory;

    return new SyncTransport(new CinderConfig(key: 'token', baseUrl: 'https://cinder.test', gzip: false), $http, $factory, $factory);
}

it('authenticates with a bearer token', function () {
    $http = new MockClient;
    $http->addResponse(new Response(202));

    syncTransport($http)->send('https://cinder.test/api/v1/errors', ['event_id' => 'x']);

    $request = $http->getLastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer token')
        ->and($request->getHeaderLine('User-Agent'))->toBe('cinderwatch/cinder-php/'.CinderConfig::SDK_VERSION);
})->group('transport');

it('prefers a per-call token over the configured key', function () {
    $http = new MockClient;
    $http->addResponse(new Response(202));

    syncTransport($http)->send('https://cinder.test/api/v1/errors', [], 'other');

    expect($http->getLastRequest()->getHeaderLine('Authorization'))->toBe('Bearer other');
})->group('transport');

it('sends payloads containing invalid utf-8 with the bytes substituted', function () {
    $http = new MockClient;
    $http->addResponse(new Response(202));

    syncTransport($http)->send('https://cinder.test/api/v1/errors', [
        'exception' => ['message' => 'boom', 'frames' => [['source' => ['start_line' => 1, 'lines' => ["valid line\xBD\x87 binary"]]]]],
    ]);

    $body = json_decode((string) $http->getLastRequest()->getBody(), true);

    expect($body['exception']['message'])->toBe('boom')
        ->and($body['exception']['frames'][0]['source']['lines'][0])->toBe("valid line\u{FFFD}\u{FFFD} binary");
})->group('transport');

it('compresses the body with gzip when enabled', function () {
    $http = new MockClient;
    $factory = new Psr17Factory;
    $transport = new SyncTransport(new CinderConfig(key: 'token'), $http, $factory, $factory);

    $transport->send('https://cinder.test/api/v1/logs', ['hello' => 'world']);

    $request = $http->getLastRequest();

    expect($request->getHeaderLine('Content-Encoding'))->toBe('gzip')
        ->and(gzdecode((string) $request->getBody()))->toBe('{"hello":"world"}');
})->group('transport');

it('sends no authorization header without a key or token', function () {
    $http = new MockClient;
    $factory = new Psr17Factory;

    (new SyncTransport(new CinderConfig(key: '', gzip: false), $http, $factory, $factory))->send('https://cinder.test/api/v1/heartbeats/abc', []);

    expect($http->getLastRequest()->hasHeader('Authorization'))->toBeFalse();
})->group('transport');

it('drops payloads that cannot be encoded', function () {
    $http = new MockClient;

    syncTransport($http)->send('https://cinder.test/api/v1/errors', ['ratio' => NAN]);

    expect($http->getRequests())->toBeEmpty();
})->group('transport');

it('retries server errors and rate limits up to the configured attempts', function (int $status) {
    $http = new MockClient;
    $factory = new Psr17Factory;
    $http->setDefaultResponse(new Response($status));

    (new SyncTransport(new CinderConfig(key: 'token', gzip: false, maxRetries: 2), $http, $factory, $factory))->send('https://cinder.test/api/v1/errors', []);

    expect($http->getRequests())->toHaveCount(3);
})->with([500, 503, 429])->group('transport');

it('stops after a response that retrying cannot fix', function (int $status) {
    $http = new MockClient;
    $http->addResponse(new Response($status));

    syncTransport($http)->send('https://cinder.test/api/v1/errors', []);

    expect($http->getRequests())->toHaveCount(1);
})->with([202, 401, 422])->group('transport');

it('retries network failures and then gives up quietly', function () {
    $http = new MockClient;
    $http->addException(new TransferException('connection refused'));
    $http->addException(new TransferException('connection refused'));

    syncTransport($http)->send('https://cinder.test/api/v1/errors', ['event_id' => 'x']);

    expect($http->getRequests())->toHaveCount(2);
})->group('transport');

it('sends the next attempt after a network failure', function () {
    $http = new MockClient;
    $http->addException(new TransferException('connection reset'));
    $http->addResponse(new Response(202));

    syncTransport($http)->send('https://cinder.test/api/v1/errors', ['event_id' => 'x']);

    expect($http->getRequests())->toHaveCount(2)
        ->and((string) $http->getRequests()[1]->getBody())->toBe('{"event_id":"x"}');
})->group('transport');
