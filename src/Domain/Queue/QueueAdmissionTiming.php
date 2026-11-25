<?php

namespace Mallto\Tool\Domain\Queue;

use Closure;
use Throwable;

/** Optional timing for a synchronous queue call; observers cannot affect admission. */
final class QueueAdmissionTiming
{
    private static ?Closure $observer = null;

    public static function exchange(?Closure $observer): ?Closure
    {
        $previous = self::$observer;
        self::$observer = $observer;
        return $previous;
    }

    public static function begin(string $stage): ?Closure
    {
        if (self::$observer === null) return null;
        try {
            return (self::$observer)($stage);
        } catch (Throwable) {
            return null;
        }
    }

    public static function finish(?Closure $end, bool $returned, ?int $payloadBytes = null): void
    {
        if ($end === null) return;
        try {
            if ($payloadBytes === null) {
                $end($returned);
            } else {
                $end($returned, $payloadBytes);
            }
        } catch (Throwable) {
            // Timing cannot mask the original result or exception.
        }
    }
}
