<?php

namespace Mallto\Tool\Domain\QueueDiagnostic;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

class QueueDiagnosticRecorder
{
    public function __construct(
        private readonly QueueDiagnosticConfig $config,
        private readonly QueueDiagnosticRedisStore $store
    ) {
    }

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

    public function after(JobProcessed $event): void
    {
        if (!$this->config->enabled()) {
            return;
        }

        $this->safeRecord(function () use ($event): void {
            $this->store->recordFinished($this->jobMeta($event->connectionName, $event->job), 'processed');
        });
    }

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

    private function payload($job): array
    {
        try {
            $payload = method_exists($job, 'payload') ? $job->payload() : [];

            return is_array($payload) ? $payload : [];
        } catch (\Throwable) {
            return [];
        }
    }

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

    private function callJobMethod($job, string $method)
    {
        try {
            return method_exists($job, $method) ? $job->{$method}() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeString($value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return (string)$value;
    }

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

    private function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}
