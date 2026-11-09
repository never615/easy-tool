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
        $localHit = false;
        $localPublicationAttempted = false;

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
            $payload = $this->shared->remember($identity, $loader,
                RuntimeConfigCacheMode::usesSwoole($mode) ? function () use ($identity, &$localHit): ?array {
                    $value = $this->swoole->get($identity);
                    $localHit = $value !== null;
                    return $value;
                } : null,
                RuntimeConfigCacheMode::usesSwoole($mode)
                    ? function (array $value) use ($identity, &$localPublicationAttempted): void {
                        // Mark the attempt even if L1 is unavailable: an invalidation
                        // during unlock must never be undone by a second outer write.
                        $localPublicationAttempted = true;
                        $this->swoole->put($identity, $value);
                    } : null);
        } else {
            $this->swoole->recordMetric('shared_hit');
            RuntimeConfigBranchTrace::record($identity->fingerprint(), 'shared_hit');
        }

        // An L1 hit or lock-owned publication must not renew its TTL or republish across a concurrent invalidation.
        if (RuntimeConfigCacheMode::usesSwoole($mode) && !$localHit && !$localPublicationAttempted) {
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
            $localHit = false;
            $localPublicationAttempted = false;
            $payload ??= $this->shared->remember($entry['identity'], $entry['loader'],
                RuntimeConfigCacheMode::usesSwoole($modes[$key])
                    ? function () use ($entry, &$localHit): ?array {
                        $value = $this->swoole->get($entry['identity']);
                        $localHit = $value !== null;
                        return $value;
                    } : null,
                RuntimeConfigCacheMode::usesSwoole($modes[$key])
                    ? function (array $value) use ($entry, &$localPublicationAttempted): void {
                        $localPublicationAttempted = true;
                        $this->swoole->put($entry['identity'], $value);
                    } : null);
            if (RuntimeConfigCacheMode::usesSwoole($modes[$key]) && !$localHit && !$localPublicationAttempted) {
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

    /** @return ?array{config: array{found: bool, value: mixed}, companion: array} */
    public function getWithArray(RuntimeConfigIdentity $identity, string $mode, object $companion, string $key): ?array
    {
        $mode = $this->runtimeMode($mode);
        // A valid L1 always wins. Its ordinary caller keeps metrics/TTL behavior;
        // do not replace it with a shared observation just to combine a read.
        if (RuntimeConfigCacheMode::usesSwoole($mode) && $this->swoole->get($identity) !== null) {
            return null;
        }
        $observed = $this->shared->getWithArray($identity, $companion, $key);
        if ($observed === null) {
            return null;
        }
        if (RuntimeConfigCacheMode::usesSwoole($mode)) {
            $this->swoole->recordMetric('l1_miss');
            RuntimeConfigBranchTrace::record($identity->fingerprint(), 'l1_miss');
            $this->swoole->put($identity, $observed['config']);
        }
        $this->swoole->recordMetric('shared_hit');
        RuntimeConfigBranchTrace::record($identity->fingerprint(), 'shared_hit');
        return ['config' => $this->result($observed['config']), 'companion' => $observed['companion']];
    }

    /** @return array{found: bool, value: mixed} */
    private function result(array $payload): array
    {
        return ['found' => $payload['found'], 'value' => $payload['value']];
    }
}
