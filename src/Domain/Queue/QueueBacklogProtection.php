<?php

namespace Mallto\Tool\Domain\Queue;

/** Deployment-time switch; reading it never queries Redis or the database. */
final class QueueBacklogProtection
{
    public static function enabled(): bool
    {
        // Only an explicit false disables protection; malformed settings keep it on.
        $setting = config('queue.backlog.enabled', true);
        if (is_string($setting)) $setting = trim($setting);
        if ($setting === null || $setting === '') return true;
        return filter_var($setting,
            FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
