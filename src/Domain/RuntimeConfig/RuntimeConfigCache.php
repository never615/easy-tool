<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use InvalidArgumentException;

final class RuntimeConfigCache
{
    public function __construct(
        private readonly RuntimeConfigSharedStore $shared,
        private readonly RuntimeConfigSwooleStore $swoole
    ) {
    }

    /**
     * @param callable(): array{found: bool, value: mixed} $loader
     * @return array{found: bool, value: mixed}
     */
    public function remember(RuntimeConfigIdentity $identity, string $mode, callable $loader): array
    {
        $mode = $this->runtimeMode($mode);

        if (RuntimeConfigCacheMode::usesSwoole($mode)) {
            $payload = $this->swoole->get($identity);
            if ($payload !== null) {
                return $this->result($payload);
            }
        }

        $payload = $this->shared->get($identity);
        if ($payload === null) {
            $payload = $this->shared->remember($identity, $loader);
        }

        if (RuntimeConfigCacheMode::usesSwoole($mode)) {
            $this->swoole->put($identity, $payload);
        }

        return $this->result($payload);
    }

    /**
     * @param callable(): array{found: bool, value: mixed} $loader
     * @return array{found: bool, value: mixed}
     */
    public function fresh(RuntimeConfigIdentity $identity, string $mode, callable $loader): array
    {
        $mode = $this->runtimeMode($mode);
        $payload = $this->shared->fresh($identity, $loader);

        if (RuntimeConfigCacheMode::usesSwoole($mode)) {
            $this->swoole->put($identity, $payload);
        }

        return $this->result($payload);
    }

    private function runtimeMode(string $mode): string
    {
        $mode = RuntimeConfigCacheMode::normalize($mode);
        if (!RuntimeConfigCacheMode::isRuntime($mode)) {
            throw new InvalidArgumentException('bootstrap_reload is not a runtime cache mode');
        }

        return $mode;
    }

    /** @return array{found: bool, value: mixed} */
    private function result(array $payload): array
    {
        return ['found' => $payload['found'], 'value' => $payload['value']];
    }
}
