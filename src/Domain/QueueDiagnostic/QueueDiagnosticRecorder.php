<?php

namespace Mallto\Tool\Domain\QueueDiagnostic;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

/**
 * QueueDiagnosticRecorder：队列诊断模块的 queue diagnostic recorder 组件。
 * 诊断记录必须保持为旁路，不能影响实际队列任务的执行。
 */
class QueueDiagnosticRecorder
{
    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        private readonly QueueDiagnosticConfig $config,
        private readonly QueueDiagnosticRedisStore $store
    ) {
    }

    /**
     * 处理「before」相关的内部协作步骤。
     */
    public function before(JobProcessing $event): void
    {
        if (!$this->config->enabled()) {
            return;
        }

        $this->safeRecord(function () use ($event): void {
            $meta = $this->jobMeta($event->connectionName, $event->job);
            $meta['started_at_ms'] = $this->nowMs();

            $this->store->recordStarted($meta);
        });
    }

    /**
     * 处理「after」相关的内部协作步骤。
     */
    public function after(JobProcessed $event): void
    {
        if (!$this->config->enabled()) {
            return;
        }

        $this->safeRecord(function () use ($event): void {
            $this->store->recordFinished($this->jobMeta($event->connectionName, $event->job), 'processed');
        });
    }

    /**
     * 处理「failed」相关的内部协作步骤。
     */
    public function failed(JobFailed $event): void
    {
        if (!$this->config->enabled()) {
            return;
        }

        $this->safeRecord(function () use ($event): void {
            $this->store->recordFinished(
                $this->jobMeta($event->connectionName, $event->job),
                'failed',
                $this->exceptionMeta($event->exception)
            );
        });
    }

    /**
     * 处理「exceptionOccurred」相关的内部协作步骤。
     */
    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        if (!$this->config->enabled()) {
            return;
        }

        $this->safeRecord(function () use ($event): void {
            $this->store->recordFinished(
                $this->jobMeta($event->connectionName, $event->job),
                'exception',
                $this->exceptionMeta($event->exception)
            );
        });
    }

    /**
     * 处理「jobMeta」相关的内部协作步骤。
     */
    private function jobMeta(string $connectionName, $job): array
    {
        $payload = $this->payload($job);

        return [
            'connection' => $connectionName,
            'queue' => $this->safeString($this->callJobMethod($job, 'getQueue'), 'unknown'),
            'name' => $this->safeString($this->callJobMethod($job, 'resolveName'), $payload['displayName'] ?? 'unknown'),
            'uuid' => $this->safeString($payload['uuid'] ?? $this->callJobMethod($job, 'uuid'), ''),
            'attempts' => (int)($this->callJobMethod($job, 'attempts') ?: 0),
            'payload_bytes' => $this->payloadBytes($job, $payload),
            'payload_job' => $this->safeString($payload['job'] ?? null, ''),
            'payload_command_name' => $this->safeString($payload['data']['commandName'] ?? null, ''),
            'diagnostic_context' => $this->normalizeContext($payload['queue_diagnostic_context'] ?? []),
        ];
    }

    /**
     * 处理「payload」相关的内部协作步骤。
     */
    private function payload($job): array
    {
        try {
            $payload = method_exists($job, 'payload') ? $job->payload() : [];

            return is_array($payload) ? $payload : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 处理「payloadBytes」相关的内部协作步骤。
     */
    private function payloadBytes($job, array $payload): int
    {
        try {
            if (method_exists($job, 'getRawBody')) {
                return strlen((string)$job->getRawBody());
            }
        } catch (\Throwable) {
            return 0;
        }

        return strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * 处理「exceptionMeta」相关的内部协作步骤。
     */
    private function exceptionMeta(?\Throwable $throwable): ?array
    {
        if (!$throwable) {
            return null;
        }

        return [
            'class' => get_class($throwable),
            'message' => mb_substr($throwable->getMessage(), 0, 300),
        ];
    }

    /**
     * 处理「callJobMethod」相关的内部协作步骤。
     */
    private function callJobMethod($job, string $method)
    {
        try {
            return method_exists($job, $method) ? $job->{$method}() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 处理「safeString」相关的内部协作步骤。
     */
    private function safeString($value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return (string)$value;
    }

    /**
     * 处理「normalizeContext」相关的内部协作步骤。
     */
    private function normalizeContext($context): array
    {
        if (!is_array($context)) {
            return [];
        }

        $normalized = [];
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $normalized[(string)$key] = mb_substr((string)$value, 0, 120);
        }

        return array_slice($normalized, 0, 20, true);
    }

    /**
     * 处理「safeRecord」相关的内部协作步骤。
     */
    private function safeRecord(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $throwable) {
            Log::warning('Queue diagnostic recorder failed', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * 处理「nowMs」相关的内部协作步骤。
     */
    private function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}
