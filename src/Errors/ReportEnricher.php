<?php

namespace Cinderwatch\Errors;

use Throwable;

/**
 * The returned flat attribute map is merged over the report context.
 */
interface ReportEnricher
{
    /**
     * @return array<string, mixed>
     */
    public function attributesFor(Throwable $throwable): array;
}
