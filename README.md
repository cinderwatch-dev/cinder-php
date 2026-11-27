<img src="https://banners.beyondco.de/Cinder%20PHP.png?theme=light&packageManager=composer+require&packageName=cinderwatch%2Fcinder-php&pattern=circuitBoard&style=style_2&description=Framework-agnostic+PHP+client+for+Cinder.&md=1&showWatermark=0&fontSize=150px&images=fire&widths=500&heights=500">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cinderwatch/cinder-php.svg?style=flat-square)](https://packagist.org/packages/cinderwatch/cinder-php)
[![Total Downloads](https://img.shields.io/packagist/dt/cinderwatch/cinder-php.svg?style=flat-square)](https://packagist.org/packages/cinderwatch/cinder-php)
[![GitHub-Tests](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/run-tests.yml)
[![GitHub Code Style](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/fix-php-code-style-issues.yml/badge.svg?branch=main)](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/fix-php-code-style-issues.yml)
[![PHPStan](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/phpstan.yml/badge.svg)](https://github.com/cinderwatch-dev/cinder-clients/actions/workflows/phpstan.yml)

> **Develop in the [cinder-clients](https://github.com/cinderwatch-dev/cinder-clients) monorepo** (`packages/cinder-php`). This GitHub repository is a read-only split mirror, so please do not open pull requests here.

This package was developed to give you a quick start to sending errors, logs, traces, health checks and scheduled task heartbeats from any PHP application to Cinder.

It is the core that [cinder-laravel](https://github.com/cinderwatch-dev/cinder-laravel) and [cinder-wordpress](https://github.com/cinderwatch-dev/cinder-wordpress) are built on. If you use Laravel or WordPress, install one of those instead. They wire everything below up for you.

## 📑 Table of Contents

- [What is Cinder?](#-what-is-cinder)
- [Requirements](#-requirements)
- [Installation](#️-installation)
- [Configuration](#-configuration)
- [Usage](#-usage)
  - [Creating a client](#creating-a-client)
  - [Errors](#errors)
    - [Context and redaction](#context-and-redaction)
    - [Fingerprints](#fingerprints)
    - [Filtering reports](#filtering-reports)
    - [Changing the payload before it is sent](#changing-the-payload-before-it-is-sent)
    - [Enrichers](#enrichers)
    - [Deployed commit](#deployed-commit)
  - [Logs](#logs)
  - [Tracing](#tracing)
  - [Flushing](#flushing)
  - [Health](#health)
    - [Built-in probes](#built-in-probes)
    - [Writing a probe](#writing-a-probe)
    - [Aliased and guarded probes](#aliased-and-guarded-probes)
    - [Running the inspector](#running-the-inspector)
    - [Serving the health endpoint](#serving-the-health-endpoint)
    - [Multiple servers](#multiple-servers)
  - [Schedule monitoring](#schedule-monitoring)
  - [Transports](#transports)
- [Testing](#-testing)
- [Changelog](#-changelog)
- [Contributing](#️-contributing)
  - [Code Style](#code-style)
- [Security Vulnerabilities](#-security-vulnerabilities)
- [Credits](#-credits)
- [License](#-license)

## 💡 What is Cinder?

[Cinder](https://cinderwatch.dev) monitors your applications in one place: error tracking, logs, performance traces, uptime, health checks and scheduled task monitoring.

## 🛠 Requirements

| Package release | PHP    | Framework                          |
|-----------------|--------|------------------------------------|
| **v1.x**        | `^8.1` | Any (framework-agnostic)           |

You also need a [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and [PSR-17](https://www.php-fig.org/psr/psr-17/) factories, for example `guzzlehttp/guzzle` or `symfony/http-client` with `nyholm/psr7`. They are found automatically through [php-http/discovery](https://github.com/php-http/discovery), or you can pass your own.

## ⚙️ Installation

You can install the package via composer:

```bash
composer require cinderwatch/cinder-php
```

If you don't have an HTTP client yet:

```bash
composer require guzzlehttp/guzzle
```

Composer may ask you to allow the `php-http/discovery` plugin. Allowing it lets discovery install a missing client for you, but it is not required.

## 🔧 Configuration

Everything is configured through `Cinderwatch\Config\CinderConfig`. The only required value is your project's API key:

```php
use Cinderwatch\Config\CinderConfig;

$config = CinderConfig::make((string) getenv('CINDER_KEY'));
```

All other options are constructor arguments, so named arguments are the easiest way to set them:

```php
$config = new CinderConfig(
    key: (string) getenv('CINDER_KEY'),
    baseUrl: 'https://cinderwatch.dev',
    serviceName: 'Shop',
    serviceVersion: '1.4.0',
    stage: 'production',
    applicationPath: '/var/www/shop',
    traceSampleRate: 0.25,
);
```

| Option | Default | Description |
|--------|---------|-------------|
| `key` | | Project API key, sent as `Authorization: Bearer <key>`. |
| `baseUrl` | `https://cinderwatch.dev` | Your Cinder host. A trailing slash is removed. |
| `serviceName` | `PHP application` | Reported as `service.name`. |
| `serviceVersion` | `null` | Reported as `service.version` and on error reports. |
| `stage` | `null` | Environment name, reported as `deployment.environment.name`. |
| `reportErrors` | `true` | Turn error reporting off. |
| `reportTraces` | `true` | Turn tracing off. Traces still hand out ids so logs and errors can be correlated. |
| `reportLogs` | `true` | Turn log collection off. |
| `traceSampleRate` | `1.0` | Share of traces to export, from `0.0` to `1.0`. |
| `timeoutSeconds` | `5` | Request timeout for adapters that build their own HTTP client. `SyncTransport` uses the PSR-18 client you give it, so set timeouts on that client. |
| `connectTimeoutSeconds` | `2` | Connect timeout, same as above. |
| `maxRetries` | `1` | Extra attempts after a network failure, a `429` or a `5xx` response. |
| `gzip` | `true` | Compress request bodies when the `zlib` extension is loaded. |
| `sensitiveKeys` | `password`, `password_confirmation`, `current_password`, `secret`, `token`, `api_key`, `authorization`, `cookie`, `credit_card` | Context keys replaced with `[redacted]`. |
| `applicationPath` | `null` | Your project root. Frames outside it, and all `vendor` frames, are marked as not in the app. |
| `sdkName` / `sdkVersion` | `cinderwatch/cinder-php` / current version | Reported as `telemetry.sdk.*`. Adapters set their own. |
| `frameworkName` / `frameworkVersion` | `null` | Reported as `cinder.framework.*`. |
| `vcsRevision` / `vcsBranch` / `vcsMessage` | `null` | The deployed commit hash, branch and subject, see [Deployed commit](#deployed-commit). |

A few options have immutable `with` methods that return a changed copy:

```php
$config = CinderConfig::make((string) getenv('CINDER_KEY'))
    ->withBaseUrl('https://cinder.example.com')
    ->withStage('staging')
    ->withServiceName('Shop')
    ->withApplicationPath(__DIR__)
    ->withSensitiveKeys(['password', 'iban']);
```

The config also builds the endpoint URLs used by the client:

```php
$config->apiPrefix(); // https://cinderwatch.dev/api/v1
$config->errorsUrl(); // https://cinderwatch.dev/api/v1/errors
$config->logsUrl();   // https://cinderwatch.dev/api/v1/logs
$config->tracesUrl(); // https://cinderwatch.dev/api/v1/traces
```

## 🚀 Usage

### Creating a client

`CinderClient::make()` accepts a key or a `CinderConfig`. The client queues everything in memory and sends it when the script shuts down:

```php
use Cinderwatch\CinderClient;
use Cinderwatch\Config\CinderConfig;

$cinder = CinderClient::make(
    CinderConfig::make((string) getenv('CINDER_KEY'))->withStage('production')
);
```

You can pass a different transport as the second argument, see [Transports](#transports). The client gives you access to everything it was built with:

```php
$cinder->config();    // CinderConfig
$cinder->transport(); // Transport
$cinder->tracer();    // Cinderwatch\Tracing\Tracer
$cinder->logs();      // Cinderwatch\Logs\LogBuffer
```

### Errors

Report any throwable. `report()` returns the payload that was queued, or `null` when the throwable was filtered out:

```php
try {
    $order->store();
} catch (Throwable $exception) {
    $cinder->report($exception);
}
```

To report every uncaught exception, register the client as your exception handler:

```php
set_exception_handler(function (Throwable $exception) use ($cinder): void {
    $cinder->report($exception, handled: false);
});
```

Each report contains the exception type, message and code, every stack frame with five lines of source either side, up to five wrapped `getPrevious()` causes, the active trace and span ids, and your runtime details. Use `buildReport()` to get the same payload without sending it:

```php
$payload = $cinder->buildReport($exception);
```

#### Context and redaction

Pass extra context as a flat map. Dotted keys are the convention:

```php
$cinder->report($exception, [
    'user.id' => $user->id,
    'order.id' => $order->id,
]);
```

The client adds `cinder.entry.kind` (`web` or `cli`), `cinder.entry.name` (the URL path or script), `process.runtime.*`, and the framework and VCS values from your config. Inside a trace, the root span's `cinder.entry.*` attributes are added too.

Any key listed in `sensitiveKeys`, at any depth and in any case, has its value replaced with `[redacted]`.

#### Fingerprints

Cinder groups reports by exception type and message. To group differently, pass a fingerprint for one report:

```php
$cinder->report($exception, fingerprint: ['checkout', 'payment-declined']);
```

Or set a resolver for every report. Return `null` or an empty list to keep the default grouping:

```php
$cinder->fingerprintUsing(function (Throwable $exception): ?array {
    return $exception instanceof PaymentFailed ? ['payments', $exception->gateway] : null;
});
```

A fingerprint passed to `report()` wins over the resolver. Empty strings and non-scalar parts are dropped.

#### Filtering reports

Only report throwables for which the predicate returns `true`:

```php
$cinder->reportOnlyWhen(fn (Throwable $exception): bool => ! $exception instanceof NotFoundException);

$cinder->shouldReport($exception); // false for NotFoundException
```

Setting `reportErrors: false` in the config turns reporting off completely.

#### Changing the payload before it is sent

`beforeSend()` filters run in the order they were added and receive the finished payload:

```php
$cinder->beforeSend(function (array $payload, Throwable $exception): array {
    unset($payload['context']['user.email']);

    return $payload;
});
```

#### Enrichers

An enricher adds attributes to every report. Implement `Cinderwatch\Errors\ReportEnricher`:

```php
use Cinderwatch\Errors\ReportEnricher;

class TenantEnricher implements ReportEnricher
{
    public function attributesFor(Throwable $throwable): array
    {
        return ['tenant.id' => Tenant::current()?->id];
    }
}

$cinder->addEnricher(new TenantEnricher);
```

Enricher attributes are merged over your context, then redacted.

#### Deployed commit

`vcsRevision`, `vcsBranch` and `vcsMessage` tag every error, trace and log with the commit that is running.
`Cinderwatch\Support\DeployedRevision` fills them in for you. It reads the `.git` folder directly (searching up to
three parent folders, and following worktrees), so it needs neither the `git` binary nor `shell_exec`. Without a
`.git` folder it falls back to `GITHUB_SHA`, `VERCEL_GIT_COMMIT_SHA`, `RENDER_GIT_COMMIT` or `SOURCE_VERSION`.

```php
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Support\DeployedRevision;

$revision = DeployedRevision::resolve(
    '/var/www/shop',
    revision: getenv('DEPLOYED_SHA') ?: null, // a revision you pass always wins
);

$config = new CinderConfig(
    key: (string) getenv('CINDER_KEY'),
    vcsRevision: $revision->revision,
    vcsBranch: $revision->branch,
    vcsMessage: $revision->message,
);
```

The message is only read while the commit is a loose object, so it is `null` once git has packed it (after `git gc`).
Pass `detect: false` to skip detection, and use `DeployedRevision::fromGit()` or `fromEnvironment()` to check one
source on its own. Each folder is read once per process.

### Logs

Write log lines to the buffer. They are exported in one request to `/api/v1/logs`:

```php
$cinder->logs()->write('Order shipped', 'info', ['order.id' => $order->id]);
$cinder->logs()->write('Payment gateway timed out', 'warning');
```

Levels use the PSR-3 names (`debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`, plus `trace` and `warn`) and are mapped to OpenTelemetry severity numbers. Unknown levels are sent with the `info` severity number.

Each line is linked to the span that is active when it is written, so logs show up next to the request or job that produced them. The context is redacted the same way as error context. The buffer exports early once 500 lines are waiting:

```php
$cinder->logs()->pending(); // lines waiting to be sent
$cinder->logs()->flush();
```

To send Monolog records to Cinder, write a small handler that calls `write()` with the record's message, level name and context.

### Tracing

Traces are exported as OTLP/JSON to `/api/v1/traces`. Open a span for each unit of work and close it when it is done. The first span opened is the root of the trace:

```php
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Tracing\SpanCategory;

$tracer = $cinder->tracer();

$request = $tracer->open('GET /orders', [
    TelemetryAttributeKeys::SpanKind => SpanCategory::WebRequest->value,
    TelemetryAttributeKeys::EntryKind => 'web',
    TelemetryAttributeKeys::EntryName => '/orders',
    'http.request.method' => 'GET',
]);

$render = $tracer->open('Render orders');
// ...
$tracer->close($render);

$request->annotate(['http.response.status_code' => 200]);
$tracer->close($request);
```

Spans nest in the order they are opened. Closing the last open span ends the trace and exports its spans. Long traces export early once 1000 spans are waiting.

Work that already happened, such as a database query you timed yourself, can be recorded as a finished child of the innermost open span. Times are Unix nanoseconds, and nothing is recorded outside a trace:

```php
use Cinderwatch\Support\Time;

$startedAt = Time::unixNano();
$rows = $pdo->query($sql)->fetchAll();

$tracer->recordCompleted('select orders', [
    TelemetryAttributeKeys::SpanKind => SpanCategory::DatabaseQuery->value,
    'db.query.text' => $sql,
], $startedAt, Time::unixNano());
```

`SpanCategory` decides how Cinder shows a span: `WebRequest` (`route`), `ConsoleCommand` (`command`), `QueuedJob` (`job`), `DatabaseQuery` (`query`), `OutboundHttp` (`http_client`), `View` (`view`), `Livewire` (`livewire`) and `Custom` (`custom`, the default). `TelemetryAttributeKeys` holds every attribute key the Cinder clients share.

The `cinder.entry.*` attributes of the root span (`EntryKind`, `EntryName`, `EntryHandler`, `EntryRoute`) are copied onto errors and logs raised inside the trace.

`traceSampleRate` is applied once per trace, when the root span opens. Unsampled traces still hand out trace and span ids, so logs and errors stay correlated:

```php
$tracer->hasActiveTrace();      // a trace is open
$tracer->isRecording();         // the open trace is sampled and will be exported
$tracer->activeTraceId();
$tracer->activeSpanId();
$tracer->rootEntryType();       // web, cli, queue or cron
$tracer->rootEntryAttributes();
```

A `Span` exposes `traceId`, `spanId`, `parentSpanId` and `name`, plus `attributes()`, `attribute($key)`, `startedAt()`, `endedAt()` and `isOpen()`. Attributes can be changed with `annotate()` until the span is closed.

### Flushing

With the default buffered transport everything is sent when the script shuts down. In long running processes such as queue workers, flush after each job:

```php
$cinder->flush();
```

`flush()` exports finished spans and pending logs, then sends everything the transport has queued.

### Health

Cinder polls your application's health endpoint (`GET /cinder/v1/health`) and alerts you when something needs attention. This package gives you the probes, the inspector that runs them, and the serialiser for the endpoint's response. Serving the endpoint is up to you, see [Serving the health endpoint](#serving-the-health-endpoint).

Each probe returns an `Observation` with a `Condition`:

| Condition | Wire value | Meaning |
|-----------|------------|---------|
| `Healthy` | `healthy` | Everything is fine. |
| `Degraded` | `degraded` | Working, but needs attention soon. |
| `Unhealthy` | `unhealthy` | Broken. |
| `Errored` | `errored` | The probe itself threw. |
| `NotApplicable` | `not_applicable` | The check does not apply here. |

#### Built-in probes

```php
use Cinderwatch\Health\Probes\ComposerAdvisoriesProbe;
use Cinderwatch\Health\Probes\DiskUsageProbe;
use Cinderwatch\Health\Probes\PhpRuntimeProbe;
use Cinderwatch\Health\Probes\UrlReachableProbe;

// Degraded above 70% used, unhealthy above 90%.
new DiskUsageProbe(path: '/', warnAbovePercent: 70, failAbovePercent: 90);

// Unhealthy when the running PHP version is older than the minimum.
new PhpRuntimeProbe(minimumVersion: '8.3.0');

// Healthy when any HTTP response comes back, or only below 400 with requireSuccessStatus.
new UrlReachableProbe('https://api.example.com/up', timeoutSeconds: 5, requireSuccessStatus: true);

// Unhealthy when an installed package version has a published security advisory.
new ComposerAdvisoriesProbe(lockFile: __DIR__.'/composer.lock', timeoutSeconds: 15);
```

| Probe | Id | Notes |
|-------|----|-------|
| `DiskUsageProbe` | `disk-usage` | Throws `InvalidArgumentException` when `warnAbovePercent` is above `failAbovePercent`. Unhealthy when the capacity cannot be read. |
| `PhpRuntimeProbe` | `php-runtime` | Defaults to a minimum of `8.1.0`. |
| `UrlReachableProbe` | `url-reachable` | Unhealthy when the URL is empty or nothing answers in time. |
| `ComposerAdvisoriesProbe` | `composer-advisories` | Reads `composer.lock` from the working directory by default and checks `packages` and `packages-dev` against the [Packagist security advisories API](https://packagist.org/apidoc). Unhealthy when the lock file can't be read, degraded when the lookup fails. |

`ComposerAdvisoriesProbe` also takes a `lookup` closure that receives the package names and returns their advisories, if you want to use your own source or cache the result:

```php
new ComposerAdvisoriesProbe(lookup: fn (array $packages): ?array => $cache->get('advisories'));
```

#### Writing a probe

Implement `Cinderwatch\Health\Probe` and return an `Observation`:

```php
use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;

class QueueBacklogProbe implements Probe
{
    public function __construct(private Queue $queue, private int $limit = 500) {}

    public function id(): string
    {
        return 'queue-backlog';
    }

    public function title(): string
    {
        return 'Queue backlog';
    }

    public function examine(): Observation
    {
        $pending = $this->queue->size();
        $facts = ['pending_jobs' => $pending, 'limit' => $this->limit];

        if ($pending > $this->limit) {
            return Observation::degraded("{$pending} waiting", "{$pending} jobs are waiting (limit {$this->limit}).", $facts);
        }

        return Observation::healthy("{$pending} waiting", $facts);
    }
}
```

`Observation` has `healthy($summary, $facts)`, `degraded($summary, $detail, $facts)`, `unhealthy($summary, $detail, $facts)`, `notApplicable($reason)` and `withFacts($facts)`. The summary is a short phrase for the dashboard, the detail is the explanation used in alerts, and facts are JSON-safe values that describe what was measured.

#### Aliased and guarded probes

Probe ids must be unique, and registering a second probe with the same id replaces the first. Use `AliasedProbe` to register the same probe type more than once:

```php
use Cinderwatch\Health\AliasedProbe;

new AliasedProbe(new UrlReachableProbe('https://payments.example.com'), 'payments-reachable', 'Payments API');
new AliasedProbe(new DiskUsageProbe('/var/lib/mysql'), 'database-disk', 'Database disk');
```

Use `GuardedProbe` to only run a probe when a condition holds. Otherwise it reports `not_applicable` with your reason:

```php
use Cinderwatch\Health\GuardedProbe;

new GuardedProbe(new DiskUsageProbe, fn (): bool => PHP_OS_FAMILY !== 'Windows', 'Not supported on Windows.');
```

#### Running the inspector

The `Inspector` holds your probes and runs them. A probe that throws is recorded as `errored`, and the other probes still run:

```php
use Cinderwatch\Health\Inspector;

$inspector = new Inspector([
    new DiskUsageProbe,
    new PhpRuntimeProbe('8.3.0'),
]);

$inspector->register(new QueueBacklogProbe($queue));

$snapshot = $inspector->inspect();
```

`replace($probes)` swaps every registered probe and `probes()` lists them. The second constructor argument is an optional clock closure that returns the `DateTimeImmutable` to stamp snapshots with.

A `HealthSnapshot` holds `takenAt` and the list of `findings`:

```php
$snapshot->hasProblems();      // any unhealthy or errored finding
$snapshot->needsAttention();   // any degraded, unhealthy or errored finding
$snapshot->worstCondition();   // worst Condition, or null without findings
$snapshot->overallCondition(); // worst Condition, ignoring not applicable, healthy when empty
$snapshot->ageInSeconds();

foreach ($snapshot->findings as $finding) {
    $finding->probeId;
    $finding->probeTitle;
    $finding->condition();
    $finding->observation->summary;
}
```

#### Serving the health endpoint

`Cinderwatch\Health\Wire\SnapshotSerializer` turns a snapshot into the response body Cinder expects. The Laravel and WordPress adapters answer with `404` unless the request sends `Authorization: Bearer <health check secret>`, and you should protect your endpoint the same way:

```php
use Cinderwatch\Health\Wire\SnapshotSerializer;

$secret = (string) getenv('CINDER_HEALTH_CHECK_SECRET');

if ($secret === '' || ! hash_equals('Bearer '.$secret, $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json');

echo json_encode((new SnapshotSerializer)->snapshotToPayload($inspector->inspect()));
```

The response looks like this. Empty `facts` are sent as `{}`:

```json
{
    "generated_at": "2026-11-23T16:00:00.123456Z",
    "condition": "unhealthy",
    "probes": [
        {
            "id": "disk-usage",
            "title": "Disk usage",
            "condition": "unhealthy",
            "summary": "95% used",
            "detail": "/ is 95% full (limit 90%).",
            "facts": {
                "path": "/",
                "used_percent": 95
            }
        }
    ]
}
```

Probes can be slow, so you may want to run the inspector on a schedule and store the result. `encode()` and `decode()` use the same format, so a stored snapshot can be served as it is. `decode()` returns `null` for anything that is not a valid snapshot, and unknown conditions are read back as `errored`:

```php
$serializer = new SnapshotSerializer;

file_put_contents('/tmp/health.json', $serializer->encode($inspector->inspect()));

$snapshot = $serializer->decode(file_get_contents('/tmp/health.json'));
```

`findingToPayload()` and `snapshotFromPayload()` are available if you need to work with single probes or arrays.

#### Multiple servers

When the application runs on several servers, each one can store its own snapshot. Combine them with `ClusterSnapshot::merge()`, passing the snapshot whose time should stamp the result and a `NodeSnapshot` per server. Findings are tagged with their node, and titles that appear on more than one node get the node label appended, such as `Disk usage (Worker)`:

```php
use Cinderwatch\Health\Cluster\ClusterSnapshot;
use Cinderwatch\Health\Cluster\NodeSnapshot;

$web = new NodeSnapshot('web-1', 'Web', $webSnapshot);
$worker = new NodeSnapshot('worker-1', 'Worker', $workerSnapshot, stale: true);

$cluster = ClusterSnapshot::merge($webSnapshot, [$web, $worker]);

echo json_encode((new SnapshotSerializer)->clusterToPayload($cluster));
```

Mark a node as `stale` when its stored snapshot was missing or too old, and give it a stand-in snapshot with a finding that says so. The cluster payload adds a `node` to each probe and a `nodes` list with each server's own findings. A cluster without nodes is sent in the single-server shape.

### Schedule monitoring

`Cinderwatch\ScheduleMonitor\ScheduleClient` registers your scheduled tasks with Cinder and reports each run, so Cinder can alert you when a task fails or doesn't run on time:

```php
use Cinderwatch\ScheduleMonitor\ScheduleClient;
use Cinderwatch\ScheduleMonitor\ScheduledTask;

$schedule = new ScheduleClient((string) getenv('CINDER_KEY'), $config->apiPrefix());

$tasks = $schedule->sync([
    ScheduledTask::cron('reports:send', '0 8 * * *', 'Europe/London', graceMinutes: 10, summary: 'Send the daily reports'),
    ScheduledTask::interval('import-feeds', minutes: 15, timezone: 'UTC', graceMinutes: 5),
]);
```

`sync()` replaces every registered task (`PUT /api/v1/schedule`) and returns the registered tasks:

```php
[
    ['key' => 'reports:send', 'id' => '9b2f6c1e-5d4a-4f3b-8e7d-2a1c0b9f8e7d', 'heartbeat_url' => 'https://cinderwatch.dev/api/v1/heartbeats/9b2f6c1e-...'],
    // ...
]
```

`register($task)` adds or updates one task (`POST /api/v1/schedule/tasks`) and returns it, or `null`. `tasks()` lists the tasks Cinder monitors (`GET /api/v1/schedule`). These three methods throw a `RuntimeException` when Cinder answers with anything other than a `2xx`.

Keys longer than 255 characters are cut off, intervals are at least one minute, and the grace period is never negative.

Report each run with `heartbeat()`, using the id returned by `sync()`:

```php
use Cinderwatch\ScheduleMonitor\HeartbeatEvent;

$schedule->heartbeat($taskId, HeartbeatEvent::Started);

$startedAt = hrtime(true);
$exitCode = runReports();

$schedule->heartbeat($taskId, $exitCode === 0 ? HeartbeatEvent::Succeeded : HeartbeatEvent::Failed, [
    'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
    'memory_bytes' => memory_get_peak_usage(true),
    'exit_code' => $exitCode,
    'error' => $exitCode === 0 ? null : 'Reports could not be sent',
]);
```

Heartbeats are posted to `/api/v1/heartbeats/{id}` without your API key, null metrics are left out, and `heartbeat()` never throws, so a monitoring problem can't break the task itself.

Like the transport, `ScheduleClient` finds a PSR-18 client and PSR-17 factories automatically. Pass your own as the last three constructor arguments.

### Transports

A transport sends each payload to Cinder. Every transport implements `Cinderwatch\Transport\Transport`:

```php
public function send(string $url, array $payload, ?string $apiToken = null): void;
```

| Transport | Description |
|-----------|-------------|
| `SyncTransport` | Posts the payload as JSON straight away. |
| `BufferedTransport` | Queues payloads in memory and passes them to another transport on `flush()` or at shutdown. This is what `CinderClient::make()` uses, wrapped around a `SyncTransport`. |
| `ArrayTransport` | Keeps every payload in its public `$sent` array. Useful in tests. |

`SyncTransport` sends `Authorization: Bearer <key>` (or the per-call `$apiToken`), a `User-Agent` of `sdkName/sdkVersion`, and gzips the body when `gzip` is enabled. Invalid UTF-8 is replaced rather than dropping the payload. Network failures, `429` and `5xx` responses are retried `maxRetries` times. It never throws, so reporting can't crash your application.

To send synchronously, or with your own HTTP client:

```php
use Cinderwatch\CinderClient;
use Cinderwatch\Transport\SyncTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory;

$cinder = CinderClient::make($config, new SyncTransport(
    $config,
    new Client(['timeout' => 5, 'connect_timeout' => 2]),
    $factory,
    $factory,
));
```

In your own tests, use the `ArrayTransport` to check what would have been sent:

```php
use Cinderwatch\Transport\ArrayTransport;

$transport = new ArrayTransport;
$cinder = CinderClient::make('test-key', $transport);

$cinder->report(new RuntimeException('boom'));

$transport->sent[0]['url'];                             // https://cinderwatch.dev/api/v1/errors
$transport->sent[0]['payload']['exception']['message']; // boom
```

## 🚧 Testing

Copy your own phpunit.xml-file.

```bash
cp phpunit.xml.dist phpunit.xml
```

Run the tests:

```bash
./vendor/bin/pest
```

Run them with coverage (needs PCOV or Xdebug):

```bash
composer test-coverage
```

In the monorepo, run the package's suite from the repository root:

```bash
vendor/bin/pest --testsuite="Cinder PHP"
```

## 📝 Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## ✏️ Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

```bash
composer test
```

### Code Style

```bash
./vendor/bin/pint
```

## 🧑‍💻 Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on reporting security vulnerabilities.

## 🙏 Credits

- [Rhys Lees](https://github.com/RhysLees)
- [All Contributors](../../contributors)
- [Skeleton Repository from Spatie](https://github.com/spatie/package-skeleton-php)
- [PHP-HTTP](https://github.com/php-http) for HTTP client discovery

## 🎭 License

The MIT License (MIT). Please have a look at [License File](LICENSE.md) for more information.
