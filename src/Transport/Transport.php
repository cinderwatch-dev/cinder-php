<?php

namespace Cinderwatch\Transport;

interface Transport
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $url, array $payload, ?string $apiToken = null): void;
}
