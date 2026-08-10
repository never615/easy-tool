<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class RuntimeConfigSwooleStore
{
    private const MAX_KEY_BYTES = 64;
    private const DEFAULT_VALUE_BYTES = 2048;

    public function __construct(
        private readonly Application $app,
        private readonly ConfigRepository $config
    ) {
    }

    /** @return array{identity: string, found: bool, value: mixed}|null */
    public function get(RuntimeConfigIdentity $identity): ?array
    {
        $key = $identity->swooleKey();
        if (!$this->enabled() || !$this->validKey($key)) {
            return null;
        }

        try {
            $table = $this->table();
            if ($table === null) {
                return null;
            }

            $row = $table->get($key);
            if ($row === false || !is_array($row)) {
                return null;
            }

            $expiresAt = (int)($row['expire'] ?? 0);
            if ($expiresAt > 0 && $expiresAt <= time()) {
                $table->del($key);

                return null;
            }

            $serialized = $row['value'] ?? null;
            if (!is_string($serialized) || $serialized === '' || strlen($serialized) > $this->valueBytes()) {
                return null;
            }

            $payload = @unserialize($serialized, ['allowed_classes' => false]);

            return $this->validPayload($identity, $payload);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array{identity: string, found: bool, value: mixed} $payload */
    public function put(RuntimeConfigIdentity $identity, array $payload): void
    {
        $key = $identity->swooleKey();
        if (!$this->enabled() || !$this->validKey($key)) {
            return;
        }

        $serialized = serialize($payload);
        if (strlen($serialized) > $this->valueBytes()) {
            return;
        }

        try {
            $table = $this->table();
            if ($table === null) {
                return;
            }

            $ttl = max(1, (int)$this->config->get('runtime_config.swoole_ttl_seconds', 60));
            $table->set($key, [
                'value' => $serialized,
                'expire' => time() + $ttl,
            ]);
        } catch (Throwable) {
            // Missing or full Swoole tables only disable L1 acceleration.
        }
    }

    public function delete(RuntimeConfigIdentity $identity): void
    {
        $this->deleteKey($identity->swooleKey());
    }

    public function deleteKey(string $key): void
    {
        if (!$this->enabled() || !$this->validKey($key)) {
            return;
        }

        try {
            $table = $this->table();
            if ($table !== null) {
                $table->del($key);
            }
        } catch (Throwable) {
            // Missing Swoole tables only disable L1 acceleration.
        }
    }

    public function flush(): void
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            $table = $this->table();
            if ($table === null) {
                return;
            }

            foreach ($table as $key => $row) {
                $table->del((string)$key);
            }
        } catch (Throwable) {
            // Missing Swoole tables only disable L1 acceleration.
        }
    }

    private function enabled(): bool
    {
        return (bool)$this->config->get('laravels.swoole_table_enable', false);
    }

    private function validKey(string $key): bool
    {
        return $key !== '' && strlen($key) < self::MAX_KEY_BYTES;
    }

    private function table()
    {
        if (!$this->app->bound('swoole')) {
            return null;
        }

        $tableName = (string)$this->config->get('runtime_config.swoole_table', 'runtime_config_cache');
        if ($tableName === '') {
            return null;
        }

        return $this->app->make('swoole')->{$tableName . 'Table'} ?? null;
    }

    private function valueBytes(): int
    {
        $tableName = (string)$this->config->get('runtime_config.swoole_table', 'runtime_config_cache');
        $columns = $this->config->get('laravels.swoole_tables.' . $tableName . '.column', []);
        foreach (is_array($columns) ? $columns : [] as $column) {
            if (($column['name'] ?? null) === 'value') {
                return max(1, (int)($column['size'] ?? self::DEFAULT_VALUE_BYTES));
            }
        }

        return self::DEFAULT_VALUE_BYTES;
    }

    /** @return array{identity: string, found: bool, value: mixed}|null */
    private function validPayload(RuntimeConfigIdentity $identity, $payload): ?array
    {
        if (!is_array($payload)
            || count($payload) !== 3
            || !array_key_exists('identity', $payload)
            || !array_key_exists('found', $payload)
            || !array_key_exists('value', $payload)
            || $payload['identity'] !== $identity->fingerprint()
            || !is_bool($payload['found'])) {
            return null;
        }

        return $payload;
    }
}
