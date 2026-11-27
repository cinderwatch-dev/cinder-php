<?php

use Cinderwatch\Tracing\Span;

it('accepts attributes only while open', function () {
    $span = new Span('t', 's', null, 'Checkout', 100, ['step' => 'cart']);

    $span->annotate(['step' => 'payment', 'items' => 3]);

    expect($span->attribute('step'))->toBe('payment')
        ->and($span->attribute('missing'))->toBeNull()
        ->and($span->isOpen())->toBeTrue()
        ->and($span->endedAt())->toBeNull();

    $span->finish(200)->annotate(['step' => 'done']);

    expect($span->attributes())->toBe(['step' => 'payment', 'items' => 3])
        ->and($span->isOpen())->toBeFalse();
})->group('tracing');

it('keeps the first end time and never ends before it started', function () {
    $span = (new Span('t', 's', null, 'Checkout', 100))->finish(50)->finish(500);

    expect($span->startedAt())->toBe(100)
        ->and($span->endedAt())->toBe(100)
        ->and((new Span('t', 's', null, 'Now', 1))->finish()->endedAt())->toBeGreaterThan(1);
})->group('tracing');
