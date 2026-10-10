<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\AppStats\Contracts;

/**
 * 提供一个只读的集群级监控分区。
 *
 * Provider 不参与 Pod 采集，也不得在 read() 中启动采样任务。聚合服务会逐个隔离异常，
 * 因此一个可选业务包不可用时不会遮蔽 Pod 或其他分区的监控结果。
 */
interface AppStatsSectionProvider
{
    /**
     * 返回稳定的 JSON 字段名，仅允许小写字母开头的 snake_case 标识符。
     */
    public function key(): string;

    /**
     * @return array{
     *   title: string,
     *   status: string,
     *   sampled_at: ?string,
     *   metrics: array<string, int|float|string|bool|null>,
     *   message: ?string,
     *   instances?: array<int, array<string, mixed>>
     * }
     */
    public function read(): array;
}
