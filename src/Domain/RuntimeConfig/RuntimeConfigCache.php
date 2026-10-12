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

    /**
     * Preserve each identity's L1 mode, then batch only shared-cache misses.
     * No process-local TTL is added; each call observes the same caches as remember().
     * @param array<string, array{identity: RuntimeConfigIdentity, mode: string, loader: callable}> $entries
     * @return array<string, array{found: bool, value: mixed}>
     */
    public function rememberMany(array $entries): array
    {
        $payloads = [];
        $identities = [];
        $modes = [];
        foreach ($entries as $key => $entry) {
            $modes[$key] = $this->runtimeMode($entry['mode']);
            $payload = RuntimeConfigCacheMode::usesSwoole($modes[$key])
                ? $this->swoole->get($entry['identity'])
                : null;
            if ($payload !== null) {
                $payloads[$key] = $payload;
            } else {
                $identities[$key] = $entry['identity'];
            }
        }
        foreach ($this->shared->getMany($identities) as $key => $payload) {
            $entry = $entries[$key];
            $payload ??= $this->shared->remember($entry['identity'], $entry['loader']);
            if (RuntimeConfigCacheMode::usesSwoole($modes[$key])) {
                $this->swoole->put($entry['identity'], $payload);
            }
            $payloads[$key] = $payload;
        }
        $results = [];
        foreach ($entries as $key => $entry) {
            $results[$key] = $this->result($payloads[$key]);
        }
        return $results;
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
