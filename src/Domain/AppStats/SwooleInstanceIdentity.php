<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\AppStats;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * 为同一 LaravelS 实例的多个自定义进程生成稳定且一致的实例标识。
 *
 * 自定义进程中 Swoole Server 的 master_pid 属性可能为 0，getmypid() 又会得到各自不同的
 * 子进程 PID。因此只以 LaravelS 的共享 pid_file 为 Master PID 来源；文件暂不可读时统一使用
 * 0 作为确定性降级值，避免同一 Pod 的多个采集器生成不同实例 ID。
 */
final class SwooleInstanceIdentity
{
    /** @var Closure(string): string|false */
    private readonly Closure $readPidFile;

    /**
     * @param Closure(string): string|false|null $pidFileReader
     */
    public function __construct(
        private readonly ConfigRepository $config,
        ?Closure $pidFileReader = null
    ) {
        $this->readPidFile = $pidFileReader ?? static fn (string $path): string|false => @file_get_contents($path);
    }

    public function make(string $hostname, int $startTime): string
    {
        return sprintf('%s:%d:%d', $hostname, max(0, $startTime), $this->masterPid());
    }

    public function masterPid(): int
    {
        $pidFile = $this->config->get('laravels.swoole.pid_file');
        if (! is_string($pidFile) || trim($pidFile) === '') {
            return 0;
        }

        try {
            $contents = ($this->readPidFile)($pidFile);
        } catch (Throwable) {
            return 0;
        }

        if (! is_string($contents)) {
            return 0;
        }

        $pid = trim($contents);

        return $pid !== '' && ctype_digit($pid) ? max(0, (int) $pid) : 0;
    }
}
