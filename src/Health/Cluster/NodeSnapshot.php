<?php

namespace Cinderwatch\Health\Cluster;

use Cinderwatch\Health\HealthSnapshot;

/**
 * When `stale` is true the real snapshot was missing or too old and `snapshot` holds a stand-in finding.
 */
class NodeSnapshot
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly HealthSnapshot $snapshot,
        public readonly bool $stale = false,
    ) {}
}
