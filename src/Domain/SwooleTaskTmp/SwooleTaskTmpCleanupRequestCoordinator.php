<?php

/*
 * 版权所有 (c) 2026. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
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
     * 多批次清理仍在运行时，续期当前 Pod 租约。
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
