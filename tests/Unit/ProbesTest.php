<?php

use Cinderwatch\Health\Condition;
use Cinderwatch\Health\Probes\ComposerAdvisoriesProbe;
use Cinderwatch\Health\Probes\DiskUsageProbe;
use Cinderwatch\Health\Probes\PhpRuntimeProbe;
use Cinderwatch\Health\Probes\UrlReachableProbe;

function lockFileWith(string $contents): string
{
    $lockFile = (string) tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($lockFile, $contents);

    return $lockFile;
}

/**
 * Runs the callback with PHP warnings silenced, for code that expects a failing stream to warn.
 */
function withoutWarnings(Closure $callback): mixed
{
    set_error_handler(fn (): bool => true);

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

function urlProbeAnswering(?array $headers, bool $requireSuccessStatus = false): UrlReachableProbe
{
    return new class($headers, $requireSuccessStatus) extends UrlReachableProbe
    {
        public array $requested = [];

        public function __construct(private ?array $headers, bool $requireSuccessStatus)
        {
            parent::__construct('https://shop.test/up', 3, $requireSuccessStatus);
        }

        protected function fetchHeaders(string $url, int $timeoutSeconds): ?array
        {
            $this->requested = [$url, $timeoutSeconds];

            return $this->headers;
        }
    };
}

it('grades disk usage against constructor thresholds', function (int $freePercent, Condition $expected) {
    $probe = new DiskUsageProbe(
        path: '/data',
        warnAbovePercent: 70,
        failAbovePercent: 90,
        measure: fn (string $path): array => ['total' => 100.0, 'free' => (float) $freePercent],
    );

    $observation = $probe->examine();

    expect($observation->condition)->toBe($expected)
        ->and($observation->facts['path'])->toBe('/data')
        ->and($observation->facts['used_percent'])->toBe(100 - $freePercent);
})->with([
    'healthy' => [40, Condition::Healthy],
    'at warning threshold' => [30, Condition::Healthy],
    'degraded' => [25, Condition::Degraded],
    'unhealthy' => [5, Condition::Unhealthy],
])->group('health');

it('is unhealthy when disk capacity cannot be read', function () {
    $probe = new DiskUsageProbe(measure: fn (string $path): array => ['total' => false, 'free' => false]);

    expect($probe->examine()->condition)->toBe(Condition::Unhealthy);
})->group('health');

it('flags a PHP runtime below the minimum', function () {
    expect((new PhpRuntimeProbe(minimumVersion: '8.3.0', runningVersion: '8.2.10'))->examine()->condition)->toBe(Condition::Unhealthy)
        ->and((new PhpRuntimeProbe(minimumVersion: '8.3.0', runningVersion: '8.3.0'))->examine()->condition)->toBe(Condition::Healthy);
})->group('health');

it('reports packages whose installed version is inside an advisory range', function () {
    $lockFile = tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($lockFile, json_encode([
        'packages' => [
            ['name' => 'acme/vulnerable', 'version' => 'v1.2.0'],
            ['name' => 'acme/patched', 'version' => '2.5.0'],
        ],
        'packages-dev' => [['name' => 'acme/tooling', 'version' => '0.1.0']],
    ]));

    $probe = new ComposerAdvisoriesProbe(
        lockFile: $lockFile,
        lookup: fn (array $packages): array => [
            'acme/vulnerable' => [['affectedVersions' => '>=1.0.0,<1.2.5|>=2.0.0,<2.0.3']],
            'acme/patched' => [['affectedVersions' => '>=2.0.0,<2.4.0']],
        ],
    );

    $observation = $probe->examine();
    unlink($lockFile);

    expect($observation->condition)->toBe(Condition::Unhealthy)
        ->and($observation->facts['packages'])->toBe(['acme/vulnerable'])
        ->and($observation->facts['packages_scanned'])->toBe(3);
})->group('health');

it('degrades when the advisory lookup is unavailable and fails without a lock file', function () {
    $lockFile = tempnam(sys_get_temp_dir(), 'lock');
    file_put_contents($lockFile, json_encode(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]));

    $unavailable = (new ComposerAdvisoriesProbe(lockFile: $lockFile, lookup: fn (array $packages): ?array => null))->examine();
    unlink($lockFile);

    expect($unavailable->condition)->toBe(Condition::Degraded)
        ->and((new ComposerAdvisoriesProbe(lockFile: '/nonexistent/composer.lock'))->examine()->condition)->toBe(Condition::Unhealthy);
})->group('health');

it('refuses a warning threshold above the failure threshold', function () {
    new DiskUsageProbe(warnAbovePercent: 95, failAbovePercent: 90);
})->throws(InvalidArgumentException::class, 'warnAbovePercent must not exceed failAbovePercent.')->group('health');

it('measures the real disk by default', function () {
    $probe = new DiskUsageProbe(sys_get_temp_dir());

    $observation = $probe->examine();

    expect($probe->id())->toBe('disk-usage')
        ->and($probe->title())->toBe('Disk usage')
        ->and($observation->summary)->toMatch('/^\d+% used$/')
        ->and($observation->facts['free_bytes'])->toBeGreaterThan(0)
        ->and(withoutWarnings(fn () => (new DiskUsageProbe('/does/not/exist'))->examine())->summary)->toBe('Unknown');
})->group('health');

it('reports the php runtime it checked', function () {
    $probe = new PhpRuntimeProbe;

    expect($probe->id())->toBe('php-runtime')
        ->and($probe->title())->toBe('PHP runtime')
        ->and($probe->examine()->facts)->toBe(['running' => PHP_VERSION, 'minimum' => '8.1.0']);
})->group('health');

it('reads composer.lock from the working directory by default', function () {
    $directory = sys_get_temp_dir().'/cinder-lock-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/composer.lock', json_encode(['packages' => []]));
    $previous = (string) getcwd();
    chdir($directory);

    try {
        $observation = (new ComposerAdvisoriesProbe)->examine();
    } finally {
        chdir($previous);
        unlink($directory.'/composer.lock');
        rmdir($directory);
    }

    expect($observation->condition)->toBe(Condition::Healthy)
        ->and($observation->summary)->toBe('No packages installed');
})->group('health');

it('is unhealthy when composer.lock is not valid json', function (string $contents) {
    $lockFile = lockFileWith($contents);

    $observation = (new ComposerAdvisoriesProbe($lockFile, fn (array $packages): array => []))->examine();
    unlink($lockFile);

    expect($observation->summary)->toBe('No lock file')
        ->and($observation->facts)->toBe(['lock_file' => $lockFile]);
})->with(['not json', '"a string"'])->group('health');

it('only flags advisories whose range covers the installed version', function () {
    $lockFile = lockFileWith((string) json_encode(['packages' => [
        ['name' => 'acme/exact', 'version' => '1.2.0'],
        ['name' => 'acme/unbounded', 'version' => '3.0.0'],
        ['name' => 'acme/safe', 'version' => '2.0.0'],
        ['name' => 'acme/broken-entry'],
    ]]));

    $probe = new ComposerAdvisoriesProbe($lockFile, fn (array $packages): array => [
        'acme/exact' => [['affectedVersions' => '| 1.2.0']],
        'acme/unbounded' => [['title' => 'No range published']],
        'acme/safe' => [['affectedVersions' => '<1.0 || >=3.0,<3.1']],
        'acme/not-installed' => [['affectedVersions' => '>=0']],
    ]);

    $observation = $probe->examine();
    unlink($lockFile);

    expect($probe->id())->toBe('composer-advisories')
        ->and($probe->title())->toBe('Composer security advisories')
        ->and($observation->summary)->toBe('2 vulnerable')
        ->and($observation->detail)->toBe('Installed versions with published advisories: acme/exact, acme/unbounded.')
        ->and($observation->facts)->toBe(['packages' => ['acme/exact', 'acme/unbounded'], 'packages_scanned' => 3]);
})->group('health');

it('is healthy when no advisory matches', function () {
    $lockFile = lockFileWith((string) json_encode(['packages' => [['name' => 'acme/safe', 'version' => '2.0.0']]]));

    $observation = (new ComposerAdvisoriesProbe($lockFile, fn (array $packages): array => []))->examine();
    unlink($lockFile);

    expect($observation->condition)->toBe(Condition::Healthy)
        ->and($observation->summary)->toBe('No known advisories')
        ->and($observation->facts)->toBe(['packages_scanned' => 1]);
})->group('health');

it('queries the advisories database when no lookup is given', function () {
    $lockFile = lockFileWith((string) json_encode(['packages' => [['name' => 'acme/vulnerable', 'version' => '1.0.0']]]));

    $probe = new class($lockFile) extends ComposerAdvisoriesProbe
    {
        protected const ADVISORIES_URL = 'data:application/json,{"advisories":{"acme/vulnerable":[{"affectedVersions":"<2.0"}]}}';
    };

    $observation = $probe->examine();
    unlink($lockFile);

    expect($observation->facts['packages'])->toBe(['acme/vulnerable']);
})->group('health');

it('degrades when the advisories database gives no usable answer', function (ComposerAdvisoriesProbe $probe) {
    expect(withoutWarnings($probe->examine(...))->summary)->toBe('Lookup failed');
})->with([
    'no response' => fn () => new class(advisoriesLockFile()) extends ComposerAdvisoriesProbe
    {
        protected const ADVISORIES_URL = '/does/not/exist';
    },
    'invalid json' => fn () => new class(advisoriesLockFile()) extends ComposerAdvisoriesProbe
    {
        protected const ADVISORIES_URL = 'data:application/json,nope';
    },
    'no advisories key' => fn () => new class(advisoriesLockFile()) extends ComposerAdvisoriesProbe
    {
        protected const ADVISORIES_URL = 'data:application/json,{"errors":[]}';
    },
])->group('health');

function advisoriesLockFile(): string
{
    return __DIR__.'/../Fixtures/composer.lock';
}

it('names the probed host in its title', function () {
    expect((new UrlReachableProbe('https://shop.test/up'))->title())->toBe('Reachability of shop.test')
        ->and((new UrlReachableProbe('/local/path'))->title())->toBe('Reachability of /local/path')
        ->and((new UrlReachableProbe('https://shop.test'))->id())->toBe('url-reachable');
})->group('health');

it('is unhealthy without a url or a response', function () {
    $missing = (new UrlReachableProbe(''))->examine();
    $unreachable = withoutWarnings((new UrlReachableProbe('/does/not/exist', timeoutSeconds: 2))->examine(...));

    expect($missing->summary)->toBe('Not configured')
        ->and($unreachable->summary)->toBe('Unreachable')
        ->and($unreachable->detail)->toBe('No response from /does/not/exist within 2s.')
        ->and($unreachable->facts)->toBe(['url' => '/does/not/exist']);
})->group('health');

it('opens the url and accepts any answer by default', function () {
    $observation = (new UrlReachableProbe('data:text/plain,ok'))->examine();

    expect($observation->condition)->toBe(Condition::Healthy)
        ->and($observation->facts)->toBe(['url' => 'data:text/plain,ok', 'status_code' => null]);
})->group('health');

it('requires a status line when success is required', function () {
    $observation = (new UrlReachableProbe('data:text/plain,ok', requireSuccessStatus: true))->examine();

    expect($observation->condition)->toBe(Condition::Unhealthy)
        ->and($observation->summary)->toBe('HTTP ?')
        ->and($observation->detail)->toBe('data:text/plain,ok answered with HTTP unknown.');
})->group('health');

it('grades the final status after redirects', function (bool $requireSuccessStatus, Condition $expected) {
    $probe = urlProbeAnswering(['HTTP/1.1 301 Moved Permanently', 'Location: /new', 'HTTP/1.1 503 Service Unavailable'], $requireSuccessStatus);

    $observation = $probe->examine();

    expect($probe->requested)->toBe(['https://shop.test/up', 3])
        ->and($observation->condition)->toBe($expected)
        ->and($observation->facts['status_code'])->toBe(503);
})->with([
    'any answer' => [false, Condition::Healthy],
    'success required' => [true, Condition::Unhealthy],
])->group('health');

it('accepts a successful status when success is required', function () {
    $observation = urlProbeAnswering(['HTTP/2 204'], requireSuccessStatus: true)->examine();

    expect($observation->summary)->toBe('Reachable')
        ->and($observation->facts['status_code'])->toBe(204);
})->group('health');
