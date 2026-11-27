<?php

namespace Cinderwatch\Transport;

class ArrayTransport implements Transport
{
    /** @var list<array{url: string, payload: array<string, mixed>, apiToken: ?string}> */
    public array $sent = [];

    public function send(string $url, array $payload, ?string $apiToken = null): void
    {
        $this->sent[] = [
            'url' => $url,
            'payload' => $payload,
            'apiToken' => $apiToken,
        ];
    }
}
