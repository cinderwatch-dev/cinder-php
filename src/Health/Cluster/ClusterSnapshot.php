<?php

namespace Cinderwatch\Health\Cluster;

use Cinderwatch\Health\Finding;
use Cinderwatch\Health\HealthSnapshot;

class ClusterSnapshot
{
    /**
     * @param  list<NodeSnapshot>  $nodes
     */
    public function __construct(
        public readonly HealthSnapshot $combined,
        public readonly array $nodes,
    ) {}

    /**
     * Findings are attributed to their node, and titles that collide across nodes get the node label appended.
     *
     * @param  list<NodeSnapshot>  $nodes
     */
    public static function merge(HealthSnapshot $takenAtSource, array $nodes): self
    {
        $titleOccurrences = [];

        foreach ($nodes as $node) {
            foreach ($node->snapshot->findings as $finding) {
                $titleOccurrences[$finding->probeTitle] = ($titleOccurrences[$finding->probeTitle] ?? 0) + 1;
            }
        }

        $merged = [];

        foreach ($nodes as $node) {
            foreach ($node->snapshot->findings as $finding) {
                $attributed = $finding->onNode($node->name);

                if ($titleOccurrences[$finding->probeTitle] > 1) {
                    $attributed = $attributed->retitled("{$finding->probeTitle} ({$node->label})");
                }

                $merged[] = $attributed;
            }
        }

        return new self(new HealthSnapshot($takenAtSource->takenAt, $merged), $nodes);
    }

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->combined->findings;
    }
}
