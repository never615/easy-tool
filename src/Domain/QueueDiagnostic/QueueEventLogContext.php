<?php

namespace Mallto\Tool\Domain\QueueDiagnostic;

use Throwable;

/**
 * 为全局队列事件日志生成固定、受限且不包含原始载荷或异常消息的上下文。
 */
final class QueueEventLogContext
{
    /** @return array<string, int|string> */
    public function make(string $connectionName, string $status, $job, ?Throwable $throwable = null): array
    {
        $context = [
            'connection_name' => $this->limited($connectionName),
            'status' => $this->limited($status),
            'queue' => $this->jobString($job, 'getQueue', 'unknown'),
            'name' => $this->jobString($job, 'resolveName', 'unknown'),
            'uuid' => $this->jobString($job, 'uuid', ''),
            'attempts' => (int)($this->callJobMethod($job, 'attempts') ?: 0),
            'payload_bytes' => $this->payloadBytes($job),
        ];

        if (!$throwable) {
            return $context;
        }

        return $context + [
            'exception_class' => get_class($throwable),
            'exception_code' => (int)$throwable->getCode(),
            'exception_location' => basename($throwable->getFile()) . ':' . $throwable->getLine(),
            'exception_fingerprint' => substr(hash(
                'sha256',
                get_class($throwable) . "\0" . $throwable->getMessage()
            ), 0, 16),
        ];
    }

    private function jobString($job, string $method, string $fallback): string
    {
        $value = $this->callJobMethod($job, $method);
        if ($value === null || $value === '') {
            return $fallback;
        }

        return $this->limited((string)$value);
    }

    private function callJobMethod($job, string $method)
    {
        try {
            return is_object($job) && method_exists($job, $method) ? $job->{$method}() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function payloadBytes($job): int
    {
        $rawBody = $this->callJobMethod($job, 'getRawBody');

        return $rawBody === null ? 0 : strlen((string)$rawBody);
    }

    private function limited(string $value): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '', 0, 160);
    }
}
