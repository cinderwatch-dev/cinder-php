<?php

use Cinderwatch\ScheduleMonitor\HeartbeatEvent;
use Cinderwatch\ScheduleMonitor\ScheduleClient;
use Cinderwatch\ScheduleMonitor\ScheduledTask;
use Http\Client\Exception\TransferException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

function scheduleFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/'.$name), true, 512, JSON_THROW_ON_ERROR);
}

function scheduleClient(MockClient $http): ScheduleClient
{
    $factory = new Psr17Factory;

    return new ScheduleClient('token', 'https://cinder.test/api/v1', $http, $factory, $factory);
}

it('replaces the task list with a PUT to the schedule endpoint', function () {
    $http = new MockClient;
    $http->addResponse(new Response(200, [], json_encode(scheduleFixture('schedule-sync-response.json'), JSON_THROW_ON_ERROR)));

    $registered = scheduleClient($http)->sync([
        ScheduledTask::cron('inspire', '* * * * *', 'UTC', 5, 'Display an inspiring quote'),
    ]);

    $request = $http->getLastRequest();

    expect($request->getMethod())->toBe('PUT')
        ->and((string) $request->getUri())->toBe('https://cinder.test/api/v1/schedule')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer token')
        ->and(json_decode((string) $request->getBody(), true))->toBe(scheduleFixture('schedule-sync-request.json'))
        ->and($registered)->toBe(scheduleFixture('schedule-sync-response.json')['tasks']);
})->group('schedule');

it('describes interval tasks with a null cron expression', function () {
    expect(ScheduledTask::interval('wp_version_check', 720, 'Europe/London', 15, 'WP-Cron hook')->toPayload())->toBe([
        'key' => 'wp_version_check',
        'summary' => 'WP-Cron hook',
        'cron' => null,
        'interval_minutes' => 720,
        'timezone' => 'Europe/London',
        'grace_minutes' => 15,
    ]);
})->group('schedule');

it('caps task keys at 255 characters', function () {
    expect(mb_strlen(ScheduledTask::cron(str_repeat('k', 300), '* * * * *', 'UTC', 5)->key))->toBe(255);
})->group('schedule');

it('registers a single task with a POST', function () {
    $http = new MockClient;
    $http->addResponse(new Response(201, [], json_encode(scheduleFixture('schedule-sync-response.json')['tasks'][0], JSON_THROW_ON_ERROR)));

    $registered = scheduleClient($http)->register(ScheduledTask::cron('inspire', '* * * * *', 'UTC', 5, 'Display an inspiring quote'));

    $request = $http->getLastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://cinder.test/api/v1/schedule/tasks')
        ->and(json_decode((string) $request->getBody(), true))->toBe(scheduleFixture('schedule-sync-request.json')['tasks'][0])
        ->and($registered['id'] ?? null)->toBe('9b2f6c1e-5d4a-4f3b-8e7d-2a1c0b9f8e7d');
})->group('schedule');

it('lists the currently monitored tasks', function () {
    $http = new MockClient;
    $http->addResponse(new Response(200, [], '{"tasks":[{"key":"inspire","id":"abc","heartbeat_url":"u","last_event":"succeeded","last_event_at":"2026-11-23T16:00:00.000000Z"}]}'));

    $tasks = scheduleClient($http)->tasks();

    expect($http->getLastRequest()->getMethod())->toBe('GET')
        ->and((string) $http->getLastRequest()->getUri())->toBe('https://cinder.test/api/v1/schedule')
        ->and($tasks[0]['last_event'])->toBe('succeeded');
})->group('schedule');

it('throws when Cinder rejects a sync', function () {
    $http = new MockClient;
    $http->addResponse(new Response(401, [], '{"message":"Unauthenticated."}'));

    scheduleClient($http)->sync([]);
})->throws(RuntimeException::class, 'HTTP 401')->group('schedule');

it('posts heartbeats without credentials', function () {
    $http = new MockClient;
    $http->addResponse(new Response(202, [], '{"ok":true}'));

    scheduleClient($http)->heartbeat('abc-uuid', HeartbeatEvent::Succeeded, [
        'duration_ms' => 1234,
        'memory_bytes' => 20971520,
        'exit_code' => 0,
        'error' => null,
    ]);

    $request = $http->getLastRequest();

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://cinder.test/api/v1/heartbeats/abc-uuid')
        ->and($request->hasHeader('Authorization'))->toBeFalse()
        ->and(json_decode((string) $request->getBody(), true))->toBe(scheduleFixture('heartbeat-succeeded.json'));
})->group('schedule');

it('sends started and failed heartbeat events', function (HeartbeatEvent $event, array $metrics, array $body) {
    $http = new MockClient;
    $http->addResponse(new Response(202));

    scheduleClient($http)->heartbeat('abc', $event, $metrics);

    expect(json_decode((string) $http->getLastRequest()->getBody(), true))->toBe($body);
})->with([
    [HeartbeatEvent::Started, [], ['event' => 'started']],
    [HeartbeatEvent::Failed, ['exit_code' => 1, 'error' => 'Boom'], ['event' => 'failed', 'exit_code' => 1, 'error' => 'Boom']],
])->group('schedule');

it('never throws from a heartbeat', function () {
    $http = new MockClient;
    $http->addException(new TransferException('network down'));

    scheduleClient($http)->heartbeat('abc');

    expect(true)->toBeTrue();
})->group('schedule');

it('skips registered tasks it cannot identify', function () {
    $http = new MockClient;
    $http->addResponse(new Response(200, [], '{"tasks":[{"key":"inspire","id":"abc"},{"key":"broken"},"nonsense"]}'));
    $http->addResponse(new Response(200, [], '{"message":"ok"}'));
    $http->addResponse(new Response(200, [], 'not json'));

    $client = scheduleClient($http);

    expect($client->sync([]))->toBe([['key' => 'inspire', 'id' => 'abc', 'heartbeat_url' => '']])
        ->and($client->sync([]))->toBe([])
        ->and($client->tasks())->toBe([]);
})->group('schedule');

it('returns null when a registered task comes back without an id', function () {
    $http = new MockClient;
    $http->addResponse(new Response(201, [], '{"key":"inspire"}'));

    expect(scheduleClient($http)->register(ScheduledTask::interval('inspire', 0, 'UTC', -5, ' ')))->toBeNull()
        ->and(json_decode((string) $http->getLastRequest()->getBody(), true))->toBe([
            'key' => 'inspire',
            'summary' => null,
            'cron' => null,
            'interval_minutes' => 1,
            'timezone' => 'UTC',
            'grace_minutes' => 0,
        ]);
})->group('schedule');
