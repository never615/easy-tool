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
        return $this->rememberObserved($identity, $mode, $loader, false);
    }

    /**
     * 复用此前共享缓存的未命中结果，不重复执行未加锁的 GET。
     * 保留普通 L1 检查、回填锁、锁内复查及错误策略。
     * 即使竞争或过期导致未命中，也不能覆盖新缓存的值重新加载。
     * @param callable(): array{found: bool, value: mixed} $loader
     * @return array{found: bool, value: mixed}
     */
    public function rememberAfterSharedMiss(RuntimeConfigIdentity $identity, string $mode, callable $loader): array
    {
        return $this->rememberObserved($identity, $mode, $loader, true);
    }

    private function rememberObserved(RuntimeConfigIdentity $identity, string $mode, callable $loader, bool $sharedMiss): array
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

        $payload = $sharedMiss ? null : $this->shared->get($identity);
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
                        // 即使 L1 不可用也记录本次尝试：解锁期间发生的失效操作
                        // 不能被外层第二次写入撤销。
                        $localPublicationAttempted = true;
                        $this->swoole->put($identity, $value);
                    } : null);
        } else {
            $this->swoole->recordMetric('shared_hit');
            RuntimeConfigBranchTrace::record($identity->fingerprint(), 'shared_hit');
        }

        // L1 命中或锁持有者发布时，不得续期 TTL，也不得跨越并发失效操作重新发布。
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
     * 保留每个身份的 L1 模式，再仅对共享缓存未命中项批量处理。
     * 不增加进程本地 TTL；每次调用观测的缓存与 remember() 相同。
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
     * 读取可用负载，不回填可选身份。调用方可以
     * 延后常规 remember() 调用，直到某个条件实际需要该值。
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
        // 有效的 L1 始终优先。其常规调用方保留指标和 TTL 行为；
        // 不要仅为合并读取就用共享观测替代它。
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
