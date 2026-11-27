<?php

namespace Cinderwatch\Health\Probes;

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

class PhpRuntimeProbe implements Probe
{
    public function __construct(
        private readonly string $minimumVersion = '8.1.0',
        private readonly string $runningVersion = PHP_VERSION,
    ) {}

    public function id(): string
    {
        return 'php-runtime';
    }

    public function title(): string
    {
        return 'PHP runtime';
    }

    public function examine(): Observation
    {
        $facts = ['running' => $this->runningVersion, 'minimum' => $this->minimumVersion];

        if (version_compare($this->runningVersion, $this->minimumVersion, '<')) {
            return Observation::unhealthy(
                "PHP {$this->runningVersion}",
                "Running PHP {$this->runningVersion}; at least {$this->minimumVersion} is required.",
                $facts,
            );
        }

        return Observation::healthy("PHP {$this->runningVersion}", $facts);
    }
}
