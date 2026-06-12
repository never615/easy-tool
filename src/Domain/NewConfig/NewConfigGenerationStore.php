<?php

namespace Mallto\Tool\Domain\NewConfig;

use Illuminate\Support\Facades\Redis;

class NewConfigGenerationStore
{
    public function current(): int
    {
        if ($this->store() === 'disabled') {
            return 0;
        }

        $value = $this->redis()->get($this->key());

        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

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

    private function store(): string
    {
        return (string)config('new_config.generation.store', 'redis');
    }

    private function key(): string
    {
        return (string)config('new_config.generation.key', 'new_configs:apply_generation');
    }

    private function redis()
    {
        $connection = config('new_config.generation.redis_connection');

        return $connection ? Redis::connection($connection) : Redis::connection();
    }
}
