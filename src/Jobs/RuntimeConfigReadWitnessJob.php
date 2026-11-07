<?php

namespace Mallto\Tool\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigBranchTrace;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigCache;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigCacheMode;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigIdentity;

/** Explicitly queued test/local witness; it never creates or modifies a configuration. */
final class RuntimeConfigReadWitnessJob implements ShouldQueue
{
    public int $tries = 1;
    public int $timeout = 20;

    public function __construct(
        public readonly int $subjectId,
        public readonly string $key,
        public readonly string $runId,
        public readonly string $stage,
        public readonly string $nonce
    ) {
    }

    public function handle(RuntimeConfigCache $cache): void
    {
        $control = RuntimeConfigBranchTrace::control();
        $identity = RuntimeConfigIdentity::subjectPrivate($this->subjectId, $this->key);
        if ($this->subjectId <= 0 || $control === null || $control['run_id'] !== $this->runId
            || $control['fingerprint'] !== $identity->fingerprint() || $control['stage'] !== $this->stage
            || preg_match('/\A[0-9a-f]{32}\z/D', $this->nonce) !== 1) {
            return;
        }
        $value = $cache->getMany(['target' => ['identity' => $identity,
            'mode' => RuntimeConfigCacheMode::SWOOLE_REDIS]])['target'];
        Cache::store((string)config('runtime_config.shared_store', 'redis'))->put(
            'runtime-config:witness-job:'.$this->runId.':'.$this->nonce,
            ['run_id' => $this->runId, 'stage' => $this->stage, 'nonce' => $this->nonce,
                'completed_at' => microtime(true), 'time_ns' => hrtime(true), 'fingerprint' => $identity->fingerprint(),
                'instance' => gethostname(), 'pid' => getmypid(),
                'swoole_bound' => app()->bound('swoole'), 'found_payload' => $value !== null,
                'payload_hash' => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR))],
            120
        );
    }
}
