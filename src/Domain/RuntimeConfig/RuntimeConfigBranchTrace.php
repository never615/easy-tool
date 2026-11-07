<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Throwable;

/** Bounded local witness for one controlled identity; never participates in cache decisions. */
final class RuntimeConfigBranchTrace
{
    private const LIMIT = 512;
    private static int $pid = 0;
    private static int $count = 0;
    private static ?array $cached = null;
    private static int $checkedAt = 0;

    public static function record(string $fingerprint, string $event, ?int $revision = null, ?int $baseline = null): void
    {
        try {
            $control = self::control();
            if ($control === null || $control['fingerprint'] !== $fingerprint
                || !in_array($event, ['l1_hit', 'l1_miss', 'shared_hit', 'shared_miss',
                    'subscriber_delete_attempt', 'revision_flush'], true)) {
                return;
            }
            $pid = getmypid();
            if (self::$pid !== $pid) {
                self::$pid = $pid;
                self::$count = 0;
            }
            $run = $control['run_id'];
            // Changing a stage or run cannot reset this process's diagnostic budget.
            $sequence = self::$count + 1;
            if ($sequence > self::LIMIT) {
                return;
            }
            self::$count = $sequence;
            $host = gethostname();
            if (!is_string($host) || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $host) !== 1) {
                return;
            }
            $row = ['schema_version' => 1, 'run_id' => $run, 'stage' => $control['stage'],
                'fingerprint' => $fingerprint, 'event' => $event, 'instance' => $host,
                'pid' => $pid, 'sequence' => $sequence, 'limit_per_process' => self::LIMIT,
                'time_ns' => hrtime(true), 'epoch' => microtime(true),
                'swoole_bound' => app()->bound('swoole')];
            if ($event === 'revision_flush') {
                $row['revision'] = $revision;
                $row['baseline'] = $baseline;
            }
            $file = dirname(self::controlPath()).'/runtime-config-branch-'.$run.'-'.$host.'-'.$pid.'.jsonl';
            if (is_link($file)) {
                return;
            }
            $handle = @fopen($file, 'ab');
            if ($handle === false) {
                return;
            }
            try {
                @chmod($file, 0600);
                if (flock($handle, LOCK_EX)) {
                    // Also bound output across process reuse or a restarted PID.
                    if (fstat($handle)['size'] < 524288) {
                        fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR)."\n");
                    }
                    flock($handle, LOCK_UN);
                }
            } finally {
                fclose($handle);
            }
        } catch (Throwable) {
            // A witness must not retry work, change a value or mask an application exception.
        }
    }

    /** @return array{run_id:string,fingerprint:string,stage:string,expires_at:int}|null */
    public static function control(): ?array
    {
        try {
            if (!config('runtime_config.branch_trace_enabled', false)
                || !in_array(config('app.env'), ['test', 'local'], true)) {
                return null;
            }
            if (self::$pid !== getmypid()) {
                self::$pid = getmypid();
                self::$count = 0;
                self::$cached = null;
                self::$checkedAt = 0;
            }
            $now = time();
            $ns = hrtime(true);
            // Local file only, no diagnostic Redis round trip on a cache read.
            if (self::$pid === getmypid() && $ns - self::$checkedAt < 100_000_000) {
                return self::$cached !== null && self::$cached['expires_at'] > $now ? self::$cached : null;
            }
            self::$checkedAt = $ns;
            self::$cached = null;
            $path = self::controlPath();
            if (!str_starts_with($path, '/') || is_link($path) || !is_file($path)
                || filesize($path) > 2048) {
                return null;
            }
            $c = json_decode((string)@file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($c) || ($c['schema_version'] ?? null) !== 1
                || !is_string($c['run_id'] ?? null)
                || preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $c['run_id']) !== 1
                || !is_string($c['fingerprint'] ?? null)
                || preg_match('/\A[0-9a-f]{40}\z/D', $c['fingerprint']) !== 1
                || !is_string($c['stage'] ?? null)
                || preg_match('/\A[a-z0-9-]{1,64}\z/D', $c['stage']) !== 1
                || !is_int($c['started_at'] ?? null) || !is_int($c['expires_at'] ?? null)
                || $c['started_at'] > $now || $c['expires_at'] <= $now
                || $c['expires_at'] - $c['started_at'] > 900 || $c['expires_at'] <= $c['started_at']) {
                return null;
            }
            return self::$cached = $c;
        } catch (Throwable) {
            return null;
        }
    }

    public static function revisionFlushed(?int $baseline, int $revision): void
    {
        $control = self::control();
        if ($control !== null) {
            self::record($control['fingerprint'], 'revision_flush', $revision, $baseline);
        }
    }

    private static function controlPath(): string
    {
        return (string)config('runtime_config.branch_trace_control_path',
            storage_path('framework/runtime-config-branch-control.json'));
    }
}
