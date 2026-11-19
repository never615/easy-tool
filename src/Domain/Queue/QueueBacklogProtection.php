<?php

namespace Mallto\Tool\Domain\Queue;

/** Deployment-time switch; reading it never queries Redis or the database. */
final class QueueBacklogProtection
{
    public static function enabled(): bool
    {
        // Performance acceptance is pending; enable only by explicit configuration.
        $setting = config('queue.backlog.enabled', false);
        if (is_string($setting)) $setting = trim($setting);
        if ($setting === null || $setting === '') return false;
        return filter_var($setting,
            FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
