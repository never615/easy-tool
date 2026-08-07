<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

/**
 * 手动 task 临时文件清理请求的最小协调协议。
 */
interface SwooleTaskTmpCleanupRequestCoordinator
{
    /**
     * @return array{request_id: string, deadline_at: int}|null
     */
    public function claim(string $hostname, string $instanceId, int $now): ?array;

    /**
     * Renew the current Pod lease while a multi-batch cleanup is still running.
     */
    public function renew(string $requestId, string $hostname, string $instanceId, int $now): bool;

    /**
     * @param array<string, mixed> $progress
     */
    public function markRunning(string $requestId, string $hostname, array $progress): void;

    /**
     * @param array<string, mixed> $result
     */
    public function markFinished(string $requestId, string $hostname, string $status, array $result): void;
}
