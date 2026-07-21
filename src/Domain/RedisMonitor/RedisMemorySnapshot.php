<?php

namespace Mallto\Tool\Domain\RedisMonitor;

use UnexpectedValueException;

/**
 * 一次 Redis 内存采样的不可变值对象。
 *
 * 这里只保留固定白名单内的 INFO 字段，避免监控日志、命令输出和状态 Key 意外带入 Redis
 * 配置、业务 Key 或 payload。构造入口会严格校验数值与内存淘汰策略；字段缺失或不可解析时
 * 由 Monitor 统一记录 error 并安全返回，不把异常抛给 Laravel Scheduler。
 */
final class RedisMemorySnapshot
{
    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        public readonly int $usedMemory,
        public readonly int $usedMemoryRss,
        public readonly int $maxMemory,
        public readonly string $maxMemoryPolicy,
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
            self::text($memory, 'maxmemory_policy'),
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

    /** 返回固定白名单的安全数组，可用于状态 Key、Laravel 日志上下文与 `--json` 输出。 */
    public function toArray(): array
    {
        return [
            'used_memory' => $this->usedMemory,
            'used_memory_rss' => $this->usedMemoryRss,
            'maxmemory' => $this->maxMemory,
            'maxmemory_policy' => $this->maxMemoryPolicy,
            'used_percent' => $this->usedPercent(),
            'mem_fragmentation_ratio' => $this->memFragmentationRatio,
            'evicted_keys' => $this->evictedKeys,
            'rejected_connections' => $this->rejectedConnections,
        ];
    }

    /**
     * 处理「integer」相关的内部协作步骤。
     */
    private static function integer(array $info, string $field): int
    {
        $value = self::numeric($info, $field);

        return max(0, (int) $value);
    }

    /**
     * 处理「decimal」相关的内部协作步骤。
     */
    private static function decimal(array $info, string $field): float
    {
        return max(0.0, (float) self::numeric($info, $field));
    }

    /**
     * INFO 中的淘汰策略是短字符串而不是数值。这里仍执行非空标量校验，避免畸形响应
     * 被静默转换为空字符串后让管理页误以为 Redis 没有配置策略。
     */
    private static function text(array $info, string $field): string
    {
        $value = $info[$field] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new UnexpectedValueException('Redis INFO field missing or empty: '.$field);
        }

        return trim((string) $value);
    }

    /**
     * 处理「numeric」相关的内部协作步骤。
     */
    private static function numeric(array $info, string $field)
    {
        if (! array_key_exists($field, $info) || ! is_numeric($info[$field])) {
            throw new UnexpectedValueException('Redis INFO field missing or non-numeric: '.$field);
        }

        return $info[$field];
    }
}
