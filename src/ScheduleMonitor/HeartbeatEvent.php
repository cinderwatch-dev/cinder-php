<?php

namespace Cinderwatch\ScheduleMonitor;

enum HeartbeatEvent: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
