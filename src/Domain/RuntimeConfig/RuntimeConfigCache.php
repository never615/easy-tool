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
                $this->swoole->recordMetric('l1_hit');
                RuntimeConfigBranchTrace::record($identity->fingerprint(), 'l1_hit');
                return $this->result($payload);
            }
            $this->swoole->recordMetric('l1_miss');
            RuntimeConfigBranchTrace::record($identity->fingerprint(), 'l1_miss');
        }

        $payload = $this->shared->get($identity);
        if ($payload === null) {
            $this->swoole->recordMetric('shared_miss');
            RuntimeConfigBranchTrace::record($identity->fingerprint(), 'shared_miss');
            $payload = $this->shared->remember($identity, $loader);
        } else {
            $this->swoole->recordMetric('shared_hit');
            RuntimeConfigBranchTrace::record($identity->fingerprint(), 'shared_hit');
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
                $this->swoole->recordMetric('l1_hit');
                RuntimeConfigBranchTrace::record($entry['identity']->fingerprint(), 'l1_hit');
            } else {
                $identities[$key] = $entry['identity'];
                if (RuntimeConfigCacheMode::usesSwoole($modes[$key])) {
                    $this->swoole->recordMetric('l1_miss');
                    RuntimeConfigBranchTrace::record($entry['identity']->fingerprint(), 'l1_miss');
                }
            }
        }
        foreach ($this->shared->getMany($identities) as $key => $payload) {
            $this->swoole->recordMetric($payload === null ? 'shared_miss' : 'shared_hit');
            RuntimeConfigBranchTrace::record($entries[$key]['identity']->fingerprint(), $payload === null ? 'shared_miss' : 'shared_hit');
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

    /**
     * Read available payloads without refilling optional identities. Callers can
     * defer their ordinary remember() until a condition actually needs the value.
     * @param array<string, array{identity: RuntimeConfigIdentity, mode: string}> $entries
     * @return array<string, array{found: bool, value: mixed}|null>
     */
    public function getMany(array $entries): array
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
                $this->swoole->recordMetric('l1_hit');
                RuntimeConfigBranchTrace::record($entry['identity']->fingerprint(), 'l1_hit');
            } else {
                $identities[$key] = $entry['identity'];
                if (RuntimeConfigCacheMode::usesSwoole($modes[$key])) {
                    $this->swoole->recordMetric('l1_miss');
                    RuntimeConfigBranchTrace::record($entry['identity']->fingerprint(), 'l1_miss');
                }
            }
        }
        foreach ($this->shared->getMany($identities) as $key => $payload) {
            $this->swoole->recordMetric($payload === null ? 'shared_miss' : 'shared_hit');
            RuntimeConfigBranchTrace::record($entries[$key]['identity']->fingerprint(), $payload === null ? 'shared_miss' : 'shared_hit');
            if ($payload !== null && RuntimeConfigCacheMode::usesSwoole($modes[$key])) {
                $this->swoole->put($entries[$key]['identity'], $payload);
            }
            $payloads[$key] = $payload;
        }
        $results = [];
        foreach ($entries as $key => $entry) {
            $results[$key] = $payloads[$key] === null ? null : $this->result($payloads[$key]);
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
