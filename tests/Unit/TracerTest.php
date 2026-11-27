<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Tracing\Span;
use Cinderwatch\Tracing\Tracer;
use Cinderwatch\Transport\ArrayTransport;

function exportedSpans(ArrayTransport $transport): array
{
    return array_merge(...array_map(
        fn (array $sent): array => $sent['payload']['resourceSpans'][0]['scopeSpans'][0]['spans'],
        $transport->sent,
    ));
}

it('nests spans and exports the trace when the root closes', function () {
    $transport = new ArrayTransport;
    $tracer = new Tracer(new CinderConfig(key: 'k', baseUrl: 'https://cinder.test', traceSampleRate: 1.0), $transport);

    $root = $tracer->open('Request - /', ['cinder.entry.kind' => 'web', 'cinder.entry.name' => '/', 'http.request.method' => 'GET']);
    $child = $tracer->open('Child');
    $query = $tracer->recordCompleted('Query', [], 100, 200);

    expect($tracer->rootEntryType())->toBe('web')
        ->and($tracer->rootEntryAttributes())->toBe(['cinder.entry.kind' => 'web', 'cinder.entry.name' => '/'])
        ->and($tracer->activeSpanId())->toBe($child->spanId)
        ->and($query?->parentSpanId)->toBe($child->spanId);

    $tracer->close($child);
    expect($transport->sent)->toBeEmpty();

    $tracer->close($root);

    $spans = exportedSpans($transport);

    expect($transport->sent[0]['url'])->toBe('https://cinder.test/api/v1/traces')
        ->and(array_column($spans, 'name'))->toBe(['Query', 'Child', 'Request - /'])
        ->and(array_unique(array_column($spans, 'traceId')))->toHaveCount(1)
        ->and($spans[1]['parentSpanId'])->toBe($root->spanId)
        ->and($tracer->hasActiveTrace())->toBeFalse();
})->group('tracing');

it('keeps trace identifiers but exports nothing for unsampled traces', function () {
    $transport = new ArrayTransport;
    $tracer = new Tracer(new CinderConfig(key: 'k', traceSampleRate: 0.0), $transport);

    $root = $tracer->open('Request - /');

    expect($tracer->activeTraceId())->toBe($root->traceId)
        ->and($tracer->isRecording())->toBeFalse();

    $tracer->close($root);

    expect($transport->sent)->toBeEmpty();
})->group('tracing');

it('ignores completed work recorded outside a trace', function () {
    $tracer = new Tracer(new CinderConfig(key: 'k'), new ArrayTransport);

    expect($tracer->recordCompleted('Query', [], 1, 2))->toBeNull();
})->group('tracing');

it('ignores closing a span it does not hold', function () {
    $transport = new ArrayTransport;
    $tracer = new Tracer(new CinderConfig(key: 'k'), $transport);
    $root = $tracer->open('Request - /');

    $tracer->close(new Span('x', 'y', null, 'Stranger', 1));

    expect($tracer->activeSpanId())->toBe($root->spanId)
        ->and($tracer->isRecording())->toBeTrue()
        ->and($transport->sent)->toBeEmpty();
})->group('tracing');

it('does not record traces when trace reporting is off', function () {
    $tracer = new Tracer(new CinderConfig(key: 'k', reportTraces: false), new ArrayTransport);

    $tracer->open('Request - /');

    expect($tracer->hasActiveTrace())->toBeTrue()
        ->and($tracer->isRecording())->toBeFalse()
        ->and($tracer->rootEntryType())->toBeNull();
})->group('tracing');

it('samples a fraction of traces at fractional rates', function () {
    $tracer = new Tracer(new CinderConfig(key: 'k', traceSampleRate: 0.5), new ArrayTransport);
    $recorded = [];

    foreach (range(1, 200) as $trace) {
        $root = $tracer->open('Request - /');
        $recorded[] = $tracer->isRecording();
        $tracer->close($root);
    }

    expect($recorded)->toContain(true)
        ->and($recorded)->toContain(false);
})->group('tracing');

it('exports early once 1000 spans are waiting', function () {
    $transport = new ArrayTransport;
    $tracer = new Tracer(new CinderConfig(key: 'k'), $transport);
    $tracer->open('Import');

    foreach (range(1, 1000) as $query) {
        $tracer->recordCompleted('Query', [], 1, 2);
    }

    expect($transport->sent)->toHaveCount(1)
        ->and(exportedSpans($transport))->toHaveCount(1000)
        ->and($tracer->hasActiveTrace())->toBeTrue();
})->group('tracing');
