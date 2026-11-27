<?php

namespace Cinderwatch\ScheduleMonitor;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type RegisteredTask array{key: string, id: string, heartbeat_url: string}
 */
class ScheduleClient
{
    private ClientInterface $client;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    private string $apiBaseUrl;

    /**
     * @param  string  $apiBaseUrl  The versioned API root, e.g. `https://cinderwatch.dev/api/v1`.
     */
    public function __construct(
        private readonly string $apiToken,
        string $apiBaseUrl,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/').'/';
        $this->client = $client ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @param  list<ScheduledTask>  $tasks
     * @return list<RegisteredTask>
     *
     * @throws RuntimeException when Cinder rejects the request
     */
    public function sync(array $tasks): array
    {
        $response = $this->request('PUT', 'schedule', [
            'tasks' => array_map(static fn (ScheduledTask $task): array => $task->toPayload(), $tasks),
        ]);

        return self::registeredTasks($response['tasks'] ?? null);
    }

    /**
     * @return RegisteredTask|null
     *
     * @throws RuntimeException when Cinder rejects the request
     */
    public function register(ScheduledTask $task): ?array
    {
        return self::registeredTasks([$this->request('POST', 'schedule/tasks', $task->toPayload())])[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException when Cinder rejects the request
     */
    public function tasks(): array
    {
        $tasks = $this->request('GET', 'schedule')['tasks'] ?? null;

        return is_array($tasks) ? array_values(array_filter($tasks, is_array(...))) : [];
    }

    /**
     * Never throws, a failed heartbeat must not break the task being monitored.
     *
     * @param  array{duration_ms?: int|null, memory_bytes?: int|null, exit_code?: int|null, error?: string|null}  $metrics
     */
    public function heartbeat(string $taskId, HeartbeatEvent $event = HeartbeatEvent::Succeeded, array $metrics = []): void
    {
        try {
            $this->request('POST', 'heartbeats/'.rawurlencode($taskId), [
                'event' => $event->value,
                ...array_filter($metrics, static fn (mixed $value): bool => $value !== null),
            ], authenticate: false);
        } catch (Throwable) {
        }
    }

    /**
     * @return list<RegisteredTask>
     */
    private static function registeredTasks(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $tasks = [];

        foreach ($items as $item) {
            if (is_array($item) && is_string($item['key'] ?? null) && is_string($item['id'] ?? null)) {
                $tasks[] = [
                    'key' => $item['key'],
                    'id' => $item['id'],
                    'heartbeat_url' => is_string($item['heartbeat_url'] ?? null) ? $item['heartbeat_url'] : '',
                ];
            }
        }

        return $tasks;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<array-key, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null, bool $authenticate = true): array
    {
        $request = $this->requestFactory
            ->createRequest($method, $this->apiBaseUrl.$path)
            ->withHeader('Accept', 'application/json');

        if ($authenticate) {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->apiToken);
        }

        if ($payload !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        }

        $response = $this->client->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Cinder %s /%s failed with HTTP %d.', $method, $path, $status));
        }

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
