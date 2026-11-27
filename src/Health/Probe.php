<?php

namespace Cinderwatch\Health;

interface Probe
{
    /** Stable identifier, unique within an inspector, e.g. "disk-usage". */
    public function id(): string;

    /** Title shown on dashboards, e.g. "Disk usage". */
    public function title(): string;

    public function examine(): Observation;
}
