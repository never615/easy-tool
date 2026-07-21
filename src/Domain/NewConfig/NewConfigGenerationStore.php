<?php

namespace Mallto\Tool\Domain\NewConfig;

use Illuminate\Support\Facades\Redis;

/**
 * NewConfigGenerationStore：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class NewConfigGenerationStore
{
    /**
     * 处理「current」相关的内部协作步骤。
     */
    public function current(): int
    {
        if ($this->store() === 'disabled') {
            return 0;
        }

        $value = $this->redis()->get($this->key());

        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    /**
     * 处理「bump」相关的内部协作步骤。
     */
    public function bump(): array
    {
        if ($this->store() === 'disabled') {
            return [
                'skipped' => true,
                'reason' => 'new_config.generation.store is disabled',
                'generation' => $this->current(),
                'key' => $this->key(),
                'store' => $this->store(),
            ];
        }

        $generation = (int)$this->redis()->incr($this->key());

        return [
            'skipped' => false,
            'generation' => $generation,
            'key' => $this->key(),
            'store' => $this->store(),
        ];
    }

    /**
     * 处理「store」相关的内部协作步骤。
     */
    private function store(): string
    {
        return (string)config('new_config.generation.store', 'redis');
    }

    /**
     * 处理「key」相关的内部协作步骤。
     */
    private function key(): string
    {
        return (string)config('new_config.generation.key', 'new_configs:apply_generation');
    }

    /**
     * 处理「redis」相关的内部协作步骤。
     */
    private function redis()
    {
        $connection = config('new_config.generation.redis_connection');

        return $connection ? Redis::connection($connection) : Redis::connection();
    }
}
