<?php

use Cinderwatch\Health\AliasedProbe;
use Cinderwatch\Health\Cluster\ClusterSnapshot;
use Cinderwatch\Health\Cluster\NodeSnapshot;
use Cinderwatch\Health\Condition;
use Cinderwatch\Health\Finding;
use Cinderwatch\Health\GuardedProbe;
use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Cinderwatch\Health\Probes\PhpRuntimeProbe;

function fixedProbe(string $id, Observation|Throwable $outcome): Probe
{
    return new class($id, $outcome) implements Probe
    {
        public function __construct(private string $probeId, private Observation|Throwable $outcome) {}

        public function id(): string
        {
            return $this->probeId;
        }

        public function title(): string
        {
            return ucfirst($this->probeId);
        }

        public function examine(): Observation
        {
            if ($this->outcome instanceof Throwable) {
                throw $this->outcome;
            }

            return $this->outcome;
        }
    };
}

function snapshotWith(Observation ...$observations): HealthSnapshot
{
    return HealthSnapshot::at(1, array_map(
        fn (Observation $observation): Finding => new Finding('p', 'P', $observation),
        array_values($observations),
    ));
}

it('produces one finding per registered probe stamped by the clock', function () {
    $inspector = new Inspector(
        [new PhpRuntimeProbe(minimumVersion: '8.0.0')],
        fn (): DateTimeImmutable => new DateTimeImmutable('@1700000000'),
    );

    $snapshot = $inspector->inspect();

    expect($snapshot->takenAt->getTimestamp())->toBe(1700000000)
        ->and($snapshot->findings)->toHaveCount(1)
        ->and($snapshot->findings[0]->probeId)->toBe('php-runtime')
        ->and($snapshot->findings[0]->condition())->toBe(Condition::Healthy);
})->group('health');

it('records a throwing probe as errored and keeps inspecting', function () {
    $snapshot = (new Inspector)
        ->register(
            fixedProbe('broken', new RuntimeException('Connection refused')),
            fixedProbe('fine', Observation::healthy('Ok')),
        )
        ->inspect();

    expect($snapshot->findings)->toHaveCount(2)
        ->and($snapshot->findings[0]->condition())->toBe(Condition::Errored)
        ->and($snapshot->findings[0]->observation->detail)->toBe('Connection refused')
        ->and($snapshot->findings[1]->condition())->toBe(Condition::Healthy);
})->group('health');

it('replaces probes registered under the same id and supports aliases', function () {
    $inspector = new Inspector([
        fixedProbe('db', Observation::healthy('first')),
        fixedProbe('db', Observation::healthy('second')),
        new AliasedProbe(fixedProbe('db', Observation::healthy('replica')), 'db-replica', 'Replica DB'),
    ]);

    $findings = $inspector->inspect()->findings;

    expect(array_map(fn (Finding $finding): string => $finding->observation->summary, $findings))->toBe(['second', 'replica'])
        ->and($findings[1]->probeTitle)->toBe('Replica DB');

    $inspector->replace([fixedProbe('only', Observation::healthy('x'))]);

    expect($inspector->probes())->toHaveCount(1);
})->group('health');

it('marks guarded probes as not applicable when the guard declines', function () {
    $snapshot = (new Inspector([
        new GuardedProbe(fixedProbe('prod-only', Observation::unhealthy('bad', 'bad')), fn (): bool => false, 'Only runs in production.'),
        new GuardedProbe(fixedProbe('always', Observation::healthy('ok')), fn (): bool => true),
    ]))->inspect();

    expect($snapshot->findings[0]->condition())->toBe(Condition::NotApplicable)
        ->and($snapshot->findings[0]->observation->detail)->toBe('Only runs in production.')
        ->and($snapshot->findings[1]->condition())->toBe(Condition::Healthy);
})->group('health');

it('only counts unhealthy and errored findings as problems', function () {
    expect(snapshotWith(Observation::degraded('w', 'w'))->hasProblems())->toBeFalse()
        ->and(snapshotWith(Observation::notApplicable('n/a'))->hasProblems())->toBeFalse()
        ->and(snapshotWith(Observation::unhealthy('f', 'f'))->hasProblems())->toBeTrue()
        ->and(snapshotWith(Observation::fromThrowable(new LogicException('x')))->hasProblems())->toBeTrue();
})->group('health');

it('counts degraded findings as needing attention but not skipped ones', function () {
    expect(snapshotWith(Observation::healthy('ok'))->needsAttention())->toBeFalse()
        ->and(snapshotWith(Observation::notApplicable('n/a'))->needsAttention())->toBeFalse()
        ->and(snapshotWith(Observation::degraded('w', 'w'))->needsAttention())->toBeTrue()
        ->and(snapshotWith(Observation::unhealthy('f', 'f'))->needsAttention())->toBeTrue()
        ->and(snapshotWith(Observation::healthy('ok'), Observation::degraded('w', 'w'))->worstCondition())->toBe(Condition::Degraded);
})->group('health');

it('describes each condition in plain words', function (Condition $condition, string $description) {
    expect($condition->describe())->toBe($description);
})->with([
    [Condition::Healthy, 'healthy'],
    [Condition::Degraded, 'degraded'],
    [Condition::Unhealthy, 'unhealthy'],
    [Condition::Errored, 'errored'],
    [Condition::NotApplicable, 'not applicable'],
])->group('health');

it('falls back to the class name when a throwing probe has no message', function () {
    $observation = Observation::fromThrowable(new LogicException);

    expect($observation->detail)->toBe(LogicException::class)
        ->and($observation->facts)->toBe(['exception' => LogicException::class]);
})->group('health');

it('copies findings and observations when adding facts, titles or nodes', function () {
    $finding = new Finding('disk-usage', 'Disk usage', Observation::healthy('40% used', ['path' => '/']));

    $changed = $finding->withFacts(['free_bytes' => 10])->retitled('Disk (web)')->onNode('web');

    expect($changed->observation->facts)->toBe(['path' => '/', 'free_bytes' => 10])
        ->and($changed->probeTitle)->toBe('Disk (web)')
        ->and($changed->node)->toBe('web')
        ->and($finding->observation->facts)->toBe(['path' => '/'])
        ->and($finding->node)->toBeNull();
})->group('health');

it('measures the age of a snapshot', function () {
    $snapshot = HealthSnapshot::at(1700000000, []);

    expect($snapshot->ageInSeconds(new DateTimeImmutable('@1700000090')))->toBe(90)
        ->and($snapshot->ageInSeconds())->toBeGreaterThan(0)
        ->and($snapshot->worstCondition())->toBeNull();
})->group('health');

it('exposes the merged findings of a cluster', function () {
    $web = new NodeSnapshot('web', 'Web', snapshotWith(Observation::healthy('ok')));

    $cluster = ClusterSnapshot::merge($web->snapshot, [$web]);

    expect($cluster->findings())->toHaveCount(1)
        ->and($cluster->findings()[0]->node)->toBe('web')
        ->and($cluster->findings()[0]->probeTitle)->toBe('P')
        ->and($cluster->combined->takenAt)->toBe($web->snapshot->takenAt);
})->group('health');
