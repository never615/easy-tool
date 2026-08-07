<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

/**
 * AppStats 读取 task 临时文件清理控制面状态的窄接口。
 */
interface SwooleTaskTmpCleanupRequestStatusReader
{
    /** @return array<string, mixed>|null */
    public function currentStatus(int $now): ?array;
}
