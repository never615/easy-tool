<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Mallto\Tool\Domain\RedisMonitor\RedisDiagnosticAnalyzer;
use Mallto\Tool\Domain\RedisMonitor\RedisDiagnosticProbe;

/** 按需运行只读 Redis 网络与响应诊断，并生成脱敏的 JSON/Markdown 证据。 */
class RedisDiagnosticCommand extends Command
{
    protected $signature = 'tool:redis_diagnostic
                            {--duration=60 : 采样时长，10-300 秒}
                            {--interval-ms=1000 : 采样间隔，1000-10000 毫秒}
                            {--bandwidth-limit-mbps= : 甲方确认的链路带宽上限，单位 Mbps}
                            {--queues=high,pgsql-write,location-result-storage,mid,default : 固定队列名，最多 20 个}
                            {--output-dir= : 报告目录，默认 storage/app/redis-diagnostics}
                            {--json : 在终端输出完整 JSON}';

    protected $description = '只读采样 Redis 网络吞吐、响应延迟和队列积压，生成客户可用诊断报告';

    public function handle(
        RedisDiagnosticProbe $probe,
        RedisDiagnosticAnalyzer $analyzer,
        Filesystem $files
    ): int {
        $duration = filter_var($this->option('duration'), FILTER_VALIDATE_INT);
        $intervalMs = filter_var($this->option('interval-ms'), FILTER_VALIDATE_INT);
        $limit = $this->bandwidthLimit();
        $queues = $this->queues();

        if ($duration === false || $duration < 10 || $duration > 300) {
            $this->error('duration 必须是 10-300 之间的整数秒');

            return self::INVALID;
        }
        if ($intervalMs === false || $intervalMs < 1000 || $intervalMs > 10000) {
            $this->error('interval-ms 必须是 1000-10000 之间的整数毫秒');

            return self::INVALID;
        }
        if ($limit === false) {
            $this->error('bandwidth-limit-mbps 必须是大于 0 的数字');

            return self::INVALID;
        }
        if ($queues === null) {
            return self::INVALID;
        }

        $lockPath = storage_path('framework/redis-diagnostic.lock');
        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('当前 Pod 已有 Redis 诊断在运行');
            if (is_resource($lock)) {
                fclose($lock);
            }

            return self::FAILURE;
        }

        try {
            $startedAt = date(DATE_ATOM);
            $samples = $this->collectSamples($probe, $duration, $intervalMs, $queues);
            $finishedAt = date(DATE_ATOM);
            $report = $analyzer->analyze($samples, $limit, [
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_seconds' => $duration,
                'interval_ms' => $intervalMs,
                'strict_read_timeout_ms' => 100,
                'relaxed_read_timeout_ms' => 1000,
                'queue_sample_interval_seconds' => 5,
                'bandwidth_capacity_source' => $limit === null ? 'unknown' : 'externally_supplied',
            ]);

            [$jsonPath, $markdownPath] = $this->writeReports($report, $analyzer, $files);
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info($report['summary']['conclusion']);
                $this->line('JSON: '.$jsonPath);
                $this->line('Markdown: '.$markdownPath);
            }

            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<int, string> $queues */
    private function collectSamples(
        RedisDiagnosticProbe $probe,
        int $duration,
        int $intervalMs,
        array $queues
    ): array {
        $count = (int) floor(($duration * 1000) / $intervalMs) + 1;
        $started = hrtime(true);
        $samples = [];

        for ($index = 0; $index < $count; $index++) {
            $deadline = $started + ($index * $intervalMs * 1_000_000);
            $remainingNs = $deadline - hrtime(true);
            if ($remainingNs > 0) {
                usleep((int) ceil($remainingNs / 1000));
            }

            // 交替执行顺序，降低固定先后顺序对对照结果的偏差。
            if ($index % 2 === 0) {
                $strict = $probe->sample('strict');
                $relaxed = $probe->sample('relaxed');
            } else {
                $relaxed = $probe->sample('relaxed');
                $strict = $probe->sample('strict');
            }

            $queueEvery = max(1, (int) ceil(5000 / $intervalMs));
            $sampleQueues = $index === 0 || $index === ($count - 1) || $index % $queueEvery === 0;
            $samples[] = [
                'sequence' => $index + 1,
                'strict' => $strict,
                'relaxed' => $relaxed,
                'queues' => $sampleQueues ? $probe->queueSizes($queues) : null,
            ];
        }

        return $samples;
    }

    private function bandwidthLimit(): float|false|null
    {
        $value = trim((string) $this->option('bandwidth-limit-mbps'));
        if ($value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value <= 0) {
            return false;
        }

        return (float) $value;
    }

    /** @return array<int, string>|null */
    private function queues(): ?array
    {
        $queues = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $this->option('queues'))),
            static fn (string $queue): bool => $queue !== ''
        )));
        if ($queues === [] || count($queues) > 20) {
            $this->error('queues 必须包含 1-20 个队列名');

            return null;
        }
        foreach ($queues as $queue) {
            if (! preg_match('/^[A-Za-z0-9_.:-]+$/', $queue)) {
                $this->error('队列名只能包含字母、数字、点、下划线、冒号和连字符');

                return null;
            }
        }

        return $queues;
    }

    private function writeReports(
        array $report,
        RedisDiagnosticAnalyzer $analyzer,
        Filesystem $files
    ): array {
        $directory = trim((string) $this->option('output-dir'));
        $directory = $directory === '' ? storage_path('app/redis-diagnostics') : $directory;
        $files->ensureDirectoryExists($directory);
        $base = rtrim($directory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'redis-diagnostic-'.date('Ymd-His').'-'.getmypid();
        $jsonPath = $base.'.json';
        $markdownPath = $base.'.md';
        $files->put(
            $jsonPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
        );
        $files->put($markdownPath, $analyzer->toMarkdown($report));

        return [$jsonPath, $markdownPath];
    }
}
