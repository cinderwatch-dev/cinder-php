<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Logs\LogBuffer;
use Cinderwatch\Tracing\Tracer;
use Cinderwatch\Transport\ArrayTransport;

function logBuffer(ArrayTransport $transport, bool $reportLogs = true): LogBuffer
{
    $config = new CinderConfig(key: 'k', baseUrl: 'https://cinder.test', reportLogs: $reportLogs);

    return new LogBuffer($config, $transport, new Tracer($config, $transport));
}

function exportedLogRecords(ArrayTransport $transport, int $request = 0): array
{
    return $transport->sent[$request]['payload']['resourceLogs'][0]['scopeLogs'][0]['logRecords'];
}

it('ignores writes when log reporting is off', function () {
    $transport = new ArrayTransport;
    $logs = logBuffer($transport, reportLogs: false);

    $logs->write('hello');
    $logs->flush();

    expect($logs->pending())->toBe(0)
        ->and($transport->sent)->toBeEmpty();
})->group('logs');

it('holds lines until flushed and sends nothing when empty', function () {
    $transport = new ArrayTransport;
    $logs = logBuffer($transport);

    $logs->flush();
    $logs->write('first');
    $logs->write('second', 'ERROR');

    expect($transport->sent)->toBeEmpty()
        ->and($logs->pending())->toBe(2);

    $logs->flush();
    $records = exportedLogRecords($transport);

    expect($logs->pending())->toBe(0)
        ->and($transport->sent)->toHaveCount(1)
        ->and(array_column($records, 'body'))->toBe([['stringValue' => 'first'], ['stringValue' => 'second']])
        ->and($records[1]['severityText'])->toBe('error')
        ->and($records[1]['severityNumber'])->toBe(17);
})->group('logs');

it('gives lines outside a trace their own ids', function () {
    $transport = new ArrayTransport;
    $logs = logBuffer($transport);

    $logs->write('outside');
    $logs->flush();
    $record = exportedLogRecords($transport)[0];

    expect($record['traceId'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($record['spanId'])->toMatch('/^[0-9a-f]{16}$/');
})->group('logs');

it('redacts sensitive keys in the log context', function () {
    $transport = new ArrayTransport;
    $logs = logBuffer($transport);

    $logs->write('signed in', 'info', ['user' => 'rhys', 'password' => 'hunter2']);
    $logs->flush();

    expect(exportedLogRecords($transport)[0]['attributes'][0]['value']['kvlistValue']['values'])->toBe([
        ['key' => 'user', 'value' => ['stringValue' => 'rhys']],
        ['key' => 'password', 'value' => ['stringValue' => '[redacted]']],
    ]);
})->group('logs');

it('exports early once 500 lines are waiting', function () {
    $transport = new ArrayTransport;
    $logs = logBuffer($transport);

    foreach (range(1, 501) as $line) {
        $logs->write("line {$line}");
    }

    expect($transport->sent)->toHaveCount(1)
        ->and(exportedLogRecords($transport))->toHaveCount(500)
        ->and($logs->pending())->toBe(1);
})->group('logs');
