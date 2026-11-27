<?php

namespace Cinderwatch\Transport;

use Cinderwatch\Config\CinderConfig;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

class SyncTransport implements Transport
{
    private ClientInterface $client;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly CinderConfig $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->client = $client ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function send(string $url, array $payload, ?string $apiToken = null): void
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable) {
            // An unencodable payload must not crash the monitored app.
            return;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->config->sdkName.'/'.$this->config->sdkVersion,
        ];

        $token = $apiToken ?? $this->config->key;

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if ($this->config->gzip && function_exists('gzencode')) {
            $compressed = gzencode($body);

            if ($compressed !== false) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        $attempts = max(1, $this->config->maxRetries + 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $status = $this->client->sendRequest($this->request($url, $headers, $body))->getStatusCode();
            } catch (Throwable) {
                // Network failures are retried, then dropped so reporting never crashes the app.
                continue;
            }

            $accepted = $status >= 200 && $status < 300;
            $rejected = $status >= 400 && $status < 500 && $status !== 429;

            if ($accepted || $rejected) {
                return;
            }
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(string $url, array $headers, string $body): RequestInterface
    {
        $request = $this->requestFactory->createRequest('POST', $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request->withBody($this->streamFactory->createStream($body));
    }
}
