<?php

use Cinderwatch\Health\Cluster\ClusterSnapshot;
use Cinderwatch\Health\Cluster\NodeSnapshot;
use Cinderwatch\Health\Condition;
use Cinderwatch\Health\Finding;
use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Wire\SnapshotSerializer;

function wireFixtureSnapshot(): HealthSnapshot
{
    return new HealthSnapshot(new DateTimeImmutable('2026-11-23T16:00:00.123456Z'), [
        new Finding('debug-mode', 'Debug mode', Observation::healthy('Disabled', ['expected' => false, 'actual' => false])),
        new Finding('disk-usage', 'Disk usage', Observation::unhealthy('95% used', '/ is 95% full (limit 90%).', ['path' => '/', 'used_percent' => 95])),
        new Finding('horizon', 'Horizon', Observation::notApplicable('laravel/horizon is not installed.')),
    ]);
}

/**
 * Re-encode a fixture so it compares byte-for-byte with `json_encode` of a payload
 * (keeping empty `facts` as objects).
 */
function healthFixtureJson(string $name): string
{
    return (string) json_encode(json_decode((string) file_get_contents(__DIR__.'/../Fixtures/'.$name), false, 512, JSON_THROW_ON_ERROR));
}

it('pins the wire JSON of a single-node snapshot', function () {
    $payload = (new SnapshotSerializer)->snapshotToPayload(wireFixtureSnapshot());

    expect(json_encode($payload))->toBe(healthFixtureJson('health-snapshot.json'))
        ->and($payload)->not->toHaveKey('nodes')
        ->and($payload['probes'][0])->not->toHaveKey('node');
})->group('health');

it('pins the wire JSON of a cluster with a stale peer', function () {
    $web = new NodeSnapshot('web', 'Web', new HealthSnapshot(new DateTimeImmutable('2026-11-23T16:00:00Z'), [
        new Finding('database', 'Database', Observation::healthy('mysql')),
        new Finding('queue', 'Queue', Observation::degraded('Backlog', '500 jobs waiting', ['pending_jobs' => 500])),
    ]));
    $worker = new NodeSnapshot('worker', 'Worker', new HealthSnapshot(new DateTimeImmutable('2026-11-23T15:59:30Z'), [
        new Finding('worker-snapshot', 'Worker report', Observation::unhealthy(
            'Report missing',
            'The Worker server has not stored a health snapshot within the last 180 seconds.',
            ['max_age_seconds' => 180],
        )),
    ]), stale: true);

    $payload = (new SnapshotSerializer)->clusterToPayload(ClusterSnapshot::merge($web->snapshot, [$web, $worker]));

    expect(json_encode($payload))->toBe(healthFixtureJson('health-cluster.json'));
})->group('health');

it('emits the client condition values directly', function (Condition $condition, string $value) {
    $snapshot = new HealthSnapshot(new DateTimeImmutable, [new Finding('x', 'X', new Observation($condition, 's'))]);

    expect((new SnapshotSerializer)->snapshotToPayload($snapshot)['probes'][0]['condition'])->toBe($value);
})->with([
    [Condition::Healthy, 'healthy'],
    [Condition::Degraded, 'degraded'],
    [Condition::Unhealthy, 'unhealthy'],
    [Condition::Errored, 'errored'],
    [Condition::NotApplicable, 'not_applicable'],
])->group('health');

it('reports the worst condition at the top level, ignoring not applicable', function (array $conditions, string $expected) {
    $findings = array_map(
        fn (Condition $condition): Finding => new Finding($condition->value, $condition->value, new Observation($condition, '')),
        $conditions,
    );

    expect((new SnapshotSerializer)->snapshotToPayload(new HealthSnapshot(new DateTimeImmutable, $findings))['condition'])->toBe($expected);
})->with([
    'no probes' => [[], 'healthy'],
    'only not applicable' => [[Condition::NotApplicable], 'healthy'],
    'degraded wins over healthy' => [[Condition::Healthy, Condition::Degraded, Condition::NotApplicable], 'degraded'],
    'errored is worst' => [[Condition::Unhealthy, Condition::Errored, Condition::Degraded], 'errored'],
])->group('health');

it('disambiguates colliding probe titles across nodes', function () {
    $web = new NodeSnapshot('web', 'Web', new HealthSnapshot(new DateTimeImmutable, [new Finding('database', 'Database', Observation::healthy('mysql'))]));
    $worker = new NodeSnapshot('worker', 'Worker', new HealthSnapshot(new DateTimeImmutable, [new Finding('database', 'Database', Observation::healthy('mysql'))]));

    $payload = (new SnapshotSerializer)->clusterToPayload(ClusterSnapshot::merge($web->snapshot, [$web, $worker]));

    expect(array_column($payload['probes'], 'title'))->toBe(['Database (Web)', 'Database (Worker)'])
        ->and(array_column($payload['probes'], 'node'))->toBe(['web', 'worker'])
        ->and($payload['nodes'][0]['probes'][0]['title'])->toBe('Database');
})->group('health');

it('omits nodes for a cluster without nodes', function () {
    $payload = (new SnapshotSerializer)->clusterToPayload(new ClusterSnapshot(wireFixtureSnapshot(), []));

    expect(array_keys($payload))->toBe(['generated_at', 'condition', 'probes']);
})->group('health');

it('round-trips a snapshot through encode and decode', function () {
    $serializer = new SnapshotSerializer;
    $original = wireFixtureSnapshot();

    $restored = $serializer->decode($serializer->encode($original));

    expect($restored)->not->toBeNull()
        ->and(json_encode($serializer->snapshotToPayload($restored)))->toBe(json_encode($serializer->snapshotToPayload($original)));
})->group('health');

it('refuses to decode malformed stored data', function (mixed $stored) {
    expect((new SnapshotSerializer)->decode($stored))->toBeNull();
})->with([null, '', 'not json', '"a string"', '{"probes": []}', '{"generated_at": "nonsense", "probes": []}'])->group('health');

it('treats unknown conditions as errored', function () {
    $snapshot = (new SnapshotSerializer)->snapshotFromPayload([
        'generated_at' => '2026-11-23T16:00:00.000000Z',
        'probes' => [['id' => 'a', 'condition' => 'ok']],
    ]);

    expect($snapshot->findings[0]->condition())->toBe(Condition::Errored);
})->group('health');

it('skips malformed probes and fills in missing fields when reading a payload', function () {
    $snapshot = (new SnapshotSerializer)->snapshotFromPayload([
        'probes' => ['nonsense', ['condition' => 'healthy', 'facts' => 'not a map']],
    ]);

    $finding = $snapshot->findings[0];

    expect($snapshot->findings)->toHaveCount(1)
        ->and($snapshot->takenAt->getTimestamp())->toBe(0)
        ->and($finding->probeId)->toBe('unknown')
        ->and($finding->probeTitle)->toBe('unknown')
        ->and($finding->observation->summary)->toBe('')
        ->and($finding->observation->facts)->toBe([]);
})->group('health');
