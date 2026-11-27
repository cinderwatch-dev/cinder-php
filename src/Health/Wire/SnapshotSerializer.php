<?php

namespace Cinderwatch\Health\Wire;

use Cinderwatch\Health\Cluster\ClusterSnapshot;
use Cinderwatch\Health\Cluster\NodeSnapshot;
use Cinderwatch\Health\Condition;
use Cinderwatch\Health\Finding;
use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Observation;
use Cinderwatch\Support\Time;
use DateTimeImmutable;
use JsonException;
use stdClass;

/**
 * The `GET /cinder/v1/health` wire format. Stored snapshots use it too, so they can be served verbatim.
 *
 * @phpstan-type WireProbe array{id: string, title: string, condition: string, summary: string, detail: string, facts: array<string, mixed>|stdClass, node?: string}
 * @phpstan-type WireNode array{name: string, label: string, generated_at: string, stale: bool, probes: list<WireProbe>}
 * @phpstan-type WireSnapshot array{generated_at: string, condition: string, probes: list<WireProbe>}
 * @phpstan-type WireCluster array{generated_at: string, condition: string, probes: list<WireProbe>, nodes: list<WireNode>}
 */
class SnapshotSerializer
{
    /**
     * A single-node snapshot: probes carry no `node` and there is no `nodes` key.
     *
     * @return WireSnapshot
     */
    public function snapshotToPayload(HealthSnapshot $snapshot): array
    {
        return [
            'generated_at' => Time::rfc3339($snapshot->takenAt),
            'condition' => $snapshot->overallCondition()->value,
            'probes' => $this->probes($snapshot, withNode: false),
        ];
    }

    /**
     * A cluster without nodes is emitted in the single-node shape.
     *
     * @return WireSnapshot|WireCluster
     */
    public function clusterToPayload(ClusterSnapshot $cluster): array
    {
        if ($cluster->nodes === []) {
            return $this->snapshotToPayload($cluster->combined);
        }

        return [
            'generated_at' => Time::rfc3339($cluster->combined->takenAt),
            'condition' => $cluster->combined->overallCondition()->value,
            'probes' => $this->probes($cluster->combined, withNode: true),
            'nodes' => array_map(
                fn (NodeSnapshot $node): array => [
                    'name' => $node->name,
                    'label' => $node->label,
                    'generated_at' => Time::rfc3339($node->snapshot->takenAt),
                    'stale' => $node->stale,
                    'probes' => $this->probes($node->snapshot, withNode: false),
                ],
                $cluster->nodes,
            ),
        ];
    }

    /**
     * @return WireProbe
     */
    public function findingToPayload(Finding $finding, bool $withNode = false): array
    {
        $observation = $finding->observation;

        $payload = [
            'id' => $finding->probeId,
            'title' => $finding->probeTitle,
            'condition' => $observation->condition->value,
            'summary' => $observation->summary,
            'detail' => $observation->detail,
            // Empty facts must encode as `{}`, not `[]`.
            'facts' => $observation->facts === [] ? new stdClass : $observation->facts,
        ];

        if ($withNode && $finding->node !== null) {
            $payload['node'] = $finding->node;
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function snapshotFromPayload(array $payload): HealthSnapshot
    {
        $findings = [];
        $items = is_array($payload['probes'] ?? null) ? $payload['probes'] : [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = is_string($item['id'] ?? null) ? $item['id'] : 'unknown';
            /** @var array<string, mixed> $facts */
            $facts = is_array($item['facts'] ?? null) ? $item['facts'] : [];

            $findings[] = new Finding(
                $id,
                is_string($item['title'] ?? null) ? $item['title'] : $id,
                new Observation(
                    $this->conditionFrom($item['condition'] ?? null),
                    is_string($item['summary'] ?? null) ? $item['summary'] : '',
                    is_string($item['detail'] ?? null) ? $item['detail'] : '',
                    $facts,
                ),
            );
        }

        return new HealthSnapshot(
            Time::parseRfc3339($payload['generated_at'] ?? null) ?? (new DateTimeImmutable)->setTimestamp(0),
            $findings,
        );
    }

    /**
     * @throws JsonException
     */
    public function encode(HealthSnapshot $snapshot): string
    {
        return json_encode($this->snapshotToPayload($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns null for anything that is not a recognisable stored snapshot.
     */
    public function decode(mixed $encoded): ?HealthSnapshot
    {
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        try {
            $payload = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload) || Time::parseRfc3339($payload['generated_at'] ?? null) === null) {
            return null;
        }

        return $this->snapshotFromPayload($payload);
    }

    /**
     * Unknown values are treated as errored so corrupt data never looks healthy.
     */
    private function conditionFrom(mixed $value): Condition
    {
        return (is_string($value) ? Condition::tryFrom($value) : null) ?? Condition::Errored;
    }

    /**
     * @return list<WireProbe>
     */
    private function probes(HealthSnapshot $snapshot, bool $withNode): array
    {
        return array_map(
            fn (Finding $finding): array => $this->findingToPayload($finding, $withNode),
            $snapshot->findings,
        );
    }
}
