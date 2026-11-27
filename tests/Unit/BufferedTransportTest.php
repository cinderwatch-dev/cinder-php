<?php

use Cinderwatch\Transport\ArrayTransport;
use Cinderwatch\Transport\BufferedTransport;

it('holds payloads until flushed and sends them in order', function () {
    $inner = new ArrayTransport;
    $transport = new BufferedTransport($inner);

    $transport->send('https://cinder.test/api/v1/errors', ['event_id' => 'a']);
    $transport->send('https://cinder.test/api/v1/heartbeats/x', ['event' => 'started'], 'heartbeat-token');

    expect($inner->sent)->toBeEmpty();

    $transport->flush();
    $transport->flush();

    expect($inner->sent)->toBe([
        ['url' => 'https://cinder.test/api/v1/errors', 'payload' => ['event_id' => 'a'], 'apiToken' => null],
        ['url' => 'https://cinder.test/api/v1/heartbeats/x', 'payload' => ['event' => 'started'], 'apiToken' => 'heartbeat-token'],
    ]);
})->group('transport');
