<?php

namespace Cinderwatch\Support;

use Cinderwatch\Tracing\SpanCategory;

/**
 * Generic OpenTelemetry keys used in only one place (`http.request.method`, `url.full`) are written inline.
 */
class TelemetryAttributeKeys
{
    /* Resource */

    public const ServiceName = 'service.name';

    public const ServiceVersion = 'service.version';

    public const DeploymentEnvironment = 'deployment.environment.name';

    public const SdkName = 'telemetry.sdk.name';

    public const SdkVersion = 'telemetry.sdk.version';

    public const SdkLanguage = 'telemetry.sdk.language';

    public const RuntimeName = 'process.runtime.name';

    public const RuntimeVersion = 'process.runtime.version';

    public const FrameworkName = 'cinder.framework.name';

    public const FrameworkVersion = 'cinder.framework.version';

    public const VcsRevision = 'vcs.ref.head.revision';

    public const VcsBranch = 'vcs.ref.head.name';

    public const VcsMessage = 'cinder.vcs.message';

    /* Spans */

    /** One of the {@see SpanCategory} values. */
    public const SpanKind = 'cinder.span.kind';

    /* Entry point (set on entry spans, copied onto errors and logs raised inside them) */

    /** `web`, `cli`, `queue` or `cron`. */
    public const EntryKind = 'cinder.entry.kind';

    /** URL path, command line or job class. */
    public const EntryName = 'cinder.entry.name';

    /** Controller action or handler class. */
    public const EntryHandler = 'cinder.entry.handler';

    /** Route template (or name). */
    public const EntryRoute = 'cinder.entry.route';

    /* Database */

    public const DbConnection = 'cinder.db.connection';

    /* Queued jobs */

    public const JobClass = 'cinder.job.class';

    public const JobName = 'cinder.job.name';

    public const JobConnection = 'cinder.job.connection';

    public const JobAttempt = 'cinder.job.attempt';

    public const JobSucceeded = 'cinder.job.succeeded';

    public const MessagingSystem = 'messaging.system';

    public const MessagingDestination = 'messaging.destination.name';

    /* Logs */

    /** Structured log context, encoded as a `kvlistValue`. */
    public const LogContext = 'cinder.log.context';
}
