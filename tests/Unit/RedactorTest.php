<?php

use Cinderwatch\Support\Redactor;

it('redacts sensitive keys at any depth, ignoring case', function () {
    expect(Redactor::redact([
        'user' => 'rhys',
        'Password' => 'hunter2',
        'request' => [
            'headers' => ['AUTHORIZATION' => 'Bearer abc', 'accept' => 'application/json'],
            'tags' => ['a', 'b'],
        ],
    ], ['password', 'Authorization']))->toBe([
        'user' => 'rhys',
        'Password' => '[redacted]',
        'request' => [
            'headers' => ['AUTHORIZATION' => '[redacted]', 'accept' => 'application/json'],
            'tags' => ['a', 'b'],
        ],
    ]);
})->group('support');
