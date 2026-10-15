<?php

namespace Byzic\PterodactylClientApi\Support;

/**
 * Activity entries are written after the change is committed. A failing log write must not turn a
 * committed change into an error response (the caller would retry it), so it is only reported.
 */
class AuditLog
{
    public static function write(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
