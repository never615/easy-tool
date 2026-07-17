<?php

namespace Mallto\Tool\Domain\RedisMonitor;

use UnexpectedValueException;

/**
 * 一次 Redis 内存采样的不可变值对象。
 *
 * 这里只保留固定的六个 INFO 字段，避免监控日志、命令输出和状态 Key 意外带入 Redis
 * 配置、业务 Key 或 payload。构造入口会严格校验数值；字段缺失或不可解析时由 Monitor
 * 统一记录 error 并安全返回，不把异常抛给 Laravel Scheduler。
 */
final class RedisMemorySnapshot
{
    public function __construct(
        public readonly int $usedMemory,
        public readonly int $usedMemoryRss,
        public readonly int $maxMemory,
        public readonly float $memFragmentationRatio,
        public readonly int $evictedKeys,
        public readonly int $rejectedConnections
    ) {}

    /** 从 INFO memory 与 INFO stats 的扁平字段创建快照。 */
    public static function fromInfo(array $memory, array $stats): self
    {
        return new self(
            self::integer($memory, 'used_memory'),
            self::integer($memory, 'used_memory_rss'),
            self::integer($memory, 'maxmemory'),
            self::decimal($memory, 'mem_fragmentation_ratio'),
            self::integer($stats, 'evicted_keys'),
            self::integer($stats, 'rejected_connections')
        );
    }

    /** maxmemory=0 表示 Redis 未设置内存上限，此时百分比没有可解释的分母。 */
    public function usedPercent(): ?float
    {
        if ($this->maxMemory <= 0) {
            return null;
        }

        return round(($this->usedMemory / $this->maxMemory) * 100, 2);
    }

    /** 返回只含数值的安全数组，可用于 Laravel 日志上下文与 `--json` 输出。 */
    public function toArray(): array
    {
        return [
            'used_memory' => $this->usedMemory,
            'used_memory_rss' => $this->usedMemoryRss,
            'maxmemory' => $this->maxMemory,
            'used_percent' => $this->usedPercent(),
            'mem_fragmentation_ratio' => $this->memFragmentationRatio,
            'evicted_keys' => $this->evictedKeys,
            'rejected_connections' => $this->rejectedConnections,
        ];
    }

    private static function integer(array $info, string $field): int
    {
        $value = self::numeric($info, $field);

        return max(0, (int) $value);
    }

    private static function decimal(array $info, string $field): float
    {
        return max(0.0, (float) self::numeric($info, $field));
    }

    private static function numeric(array $info, string $field)
    {
        if (! array_key_exists($field, $info) || ! is_numeric($info[$field])) {
            throw new UnexpectedValueException('Redis INFO field missing or non-numeric: '.$field);
        }

        return $info[$field];
    }
}
