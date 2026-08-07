<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
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
