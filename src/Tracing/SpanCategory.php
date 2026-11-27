<?php

namespace Cinderwatch\Tracing;

use Cinderwatch\Support\TelemetryAttributeKeys;

/**
 * Sent as {@see TelemetryAttributeKeys::SpanKind}. Spans without a kind are treated as custom.
 */
enum SpanCategory: string
{
    case WebRequest = 'route';
    case ConsoleCommand = 'command';
    case QueuedJob = 'job';
    case DatabaseQuery = 'query';
    case OutboundHttp = 'http_client';
    case View = 'view';
    case Livewire = 'livewire';
    case Custom = 'custom';
}
