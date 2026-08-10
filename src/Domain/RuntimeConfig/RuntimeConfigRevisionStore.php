<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use RuntimeException;

final class RuntimeConfigRevisionStore
{
    public function __construct(private readonly CacheFactory $cache)
    {
    }

    public function current(): int
    {
        return max(0, (int) $this->store()->get($this->key(), 0));
    }

    public function bump(): int
    {
        $revision = $this->store()->increment($this->key());
        if ($revision === false) {
            throw new RuntimeException('Unable to increment the runtime config revision.');
        }

        return max(0, (int) $revision);
    }

    private function store()
    {
        return $this->cache->store((string) config('runtime_config.shared_store', 'redis'));
    }

    private function key(): string
    {
        return (string) config('runtime_config.revision_key', 'runtime_config:revision');
    }
}
