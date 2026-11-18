<?php

/*
 * 版权所有 (c) 2026. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

/**
 * 由管理端创建 task 临时文件清理请求的窄接口。
 */
interface SwooleTaskTmpCleanupRequestCreator
{
    /**
     * @param list<array{hostname: string, instance_id: string}> $targets
     * @return array{created: bool, request_id: string}
     */
    public function create(array $targets, int|string $requestedBy, int $now): array;
}
