<?php

namespace Cinderwatch\Transport;

class BufferedTransport implements Transport
{
    /** @var list<array{url: string, payload: array<string, mixed>, apiToken: ?string}> */
    private array $queue = [];

    private bool $registered = false;

    public function __construct(
        private readonly Transport $inner,
    ) {}

    public function send(string $url, array $payload, ?string $apiToken = null): void
    {
        $this->queue[] = [
            'url' => $url,
            'payload' => $payload,
            'apiToken' => $apiToken,
        ];

        $this->registerShutdownFlush();
    }

    public function flush(): void
    {
        $items = $this->queue;
        $this->queue = [];

        foreach ($items as $item) {
            $this->inner->send($item['url'], $item['payload'], $item['apiToken']);
        }
    }

    private function registerShutdownFlush(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        register_shutdown_function($this->flush(...));
    }
}
