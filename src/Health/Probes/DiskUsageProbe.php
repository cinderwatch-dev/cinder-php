<?php

namespace Cinderwatch\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Closure;
use InvalidArgumentException;

class DiskUsageProbe implements Probe
{
    /** @var Closure(string): array{total: float|false, free: float|false} */
    private readonly Closure $measure;

    /**
     * @param  (Closure(string): array{total: float|false, free: float|false})|null  $measure
     */
    public function __construct(
        private readonly string $path = '/',
        private readonly int $warnAbovePercent = 70,
        private readonly int $failAbovePercent = 90,
        ?Closure $measure = null,
    ) {
        if ($warnAbovePercent > $failAbovePercent) {
            throw new InvalidArgumentException('warnAbovePercent must not exceed failAbovePercent.');
        }

        $this->measure = $measure ?? static fn (string $path): array => [
            'total' => @disk_total_space($path),
            'free' => @disk_free_space($path),
        ];
    }

    public function id(): string
    {
        return 'disk-usage';
    }

    public function title(): string
    {
        return 'Disk usage';
    }

    public function examine(): Observation
    {
        ['total' => $total, 'free' => $free] = ($this->measure)($this->path);

        if ($total === false || $free === false || $total <= 0) {
            return Observation::unhealthy('Unknown', "Could not read disk capacity for {$this->path}.", ['path' => $this->path]);
        }

        $usedPercent = (int) round(100 * ($total - $free) / $total);
        $facts = [
            'path' => $this->path,
            'used_percent' => $usedPercent,
            'free_bytes' => (int) $free,
        ];
        $summary = "{$usedPercent}% used";

        if ($usedPercent > $this->failAbovePercent) {
            return Observation::unhealthy($summary, "{$this->path} is {$usedPercent}% full (limit {$this->failAbovePercent}%).", $facts);
        }

        if ($usedPercent > $this->warnAbovePercent) {
            return Observation::degraded($summary, "{$this->path} is {$usedPercent}% full (warning at {$this->warnAbovePercent}%).", $facts);
        }

        return Observation::healthy($summary, $facts);
    }
}
