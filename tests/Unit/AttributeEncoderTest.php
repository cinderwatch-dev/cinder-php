<?php

use Cinderwatch\Health\Condition;
use Cinderwatch\Otlp\AttributeEncoder;
use Cinderwatch\Tracing\SpanCategory;

enum EncoderSuit
{
    case Hearts;
}

it('encodes enums, stringables and other objects as strings', function () {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'order #42';
        }
    };

    expect((new AttributeEncoder)->keyValues([
        'kind' => SpanCategory::DatabaseQuery,
        'suit' => EncoderSuit::Hearts,
        'order' => $stringable,
        'at' => new DateTimeImmutable,
        7 => Condition::Healthy,
    ]))->toBe([
        ['key' => 'kind', 'value' => ['stringValue' => 'query']],
        ['key' => 'suit', 'value' => ['stringValue' => 'Hearts']],
        ['key' => 'order', 'value' => ['stringValue' => 'order #42']],
        ['key' => 'at', 'value' => ['stringValue' => 'DateTimeImmutable']],
        ['key' => '7', 'value' => ['stringValue' => 'healthy']],
    ]);
})->group('otlp');
