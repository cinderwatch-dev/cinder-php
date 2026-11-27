<?php

namespace Cinderwatch\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

/**
 * Healthy when any HTTP response comes back, or only below 400 with `requireSuccessStatus`.
 */
class UrlReachableProbe implements Probe
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeoutSeconds = 5,
        private readonly bool $requireSuccessStatus = false,
    ) {}

    public function id(): string
    {
        return 'url-reachable';
    }

    public function title(): string
    {
        return 'Reachability of '.(parse_url($this->url, PHP_URL_HOST) ?: $this->url);
    }

    public function examine(): Observation
    {
        if ($this->url === '') {
            return Observation::unhealthy('Not configured', 'No URL was given to probe.');
        }

        $headers = $this->fetchHeaders($this->url, $this->timeoutSeconds);

        if ($headers === null) {
            return Observation::unhealthy('Unreachable', "No response from {$this->url} within {$this->timeoutSeconds}s.", ['url' => $this->url]);
        }

        $statusCode = $this->statusCodeFrom($headers);
        $facts = ['url' => $this->url, 'status_code' => $statusCode];

        if ($this->requireSuccessStatus && ($statusCode === null || $statusCode >= 400)) {
            return Observation::unhealthy('HTTP '.($statusCode ?? '?'), "{$this->url} answered with HTTP ".($statusCode ?? 'unknown').'.', $facts);
        }

        return Observation::healthy('Reachable', $facts);
    }

    /**
     * The raw response header lines, or null when nothing answered.
     *
     * @return array<array-key, mixed>|null
     */
    protected function fetchHeaders(string $url, int $timeoutSeconds): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);

        if ($handle === false) {
            return null;
        }

        $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
        fclose($handle);

        return is_array($headers) ? $headers : [];
    }

    /**
     * Redirects add a status line per hop, so the last one wins.
     *
     * @param  array<array-key, mixed>  $headers
     */
    private function statusCodeFrom(array $headers): ?int
    {
        $statusCode = null;

        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $statusCode = (int) $match[1];
            }
        }

        return $statusCode;
    }
}
