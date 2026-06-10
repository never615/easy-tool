<?php

namespace Mallto\Tool\Domain\NewConfig;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Mallto\Tool\Data\NewConfig;
use Throwable;

class NewConfigCenter
{
    private const CACHE_KEY = 'new_configs_runtime_values';
    private const CACHE_SECONDS = 2;

    private static ?array $values = null;
    private static int $loadedAt = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->values();

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string)$value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        return (float)$this->get($key, (string)$default);
    }

    public function values(): array
    {
        $now = time();
        if (self::$values !== null && ($now - self::$loadedAt) < self::CACHE_SECONDS) {
            return self::$values;
        }

        $values = $this->loadCachedValues();
        self::$values = $values;
        self::$loadedAt = $now;

        return $values;
    }

    public function clearCache(): void
    {
        self::$values = null;
        self::$loadedAt = 0;

        try {
            Cache::store('local_redis')->forget(self::CACHE_KEY);
        } catch (Throwable) {
        }
    }

    private function loadCachedValues(): array
    {
        try {
            $values = Cache::store('local_redis')->get(self::CACHE_KEY);
            if (is_array($values)) {
                return $values;
            }
        } catch (Throwable) {
        }

        $values = $this->loadDatabaseValues();

        try {
            Cache::store('local_redis')->put(
                self::CACHE_KEY,
                $values,
                Carbon::now()->addSeconds(self::CACHE_SECONDS)
            );
        } catch (Throwable) {
        }

        return $values;
    }

    private function loadDatabaseValues(): array
    {
        try {
            $rows = NewConfig::query()
                ->where('is_enabled', true)
                ->get([ 'key', 'value', 'default_value' ]);
        } catch (Throwable) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            $value = $row->value;
            if ($value === null || $value === '') {
                $value = $row->default_value;
            }

            if ($value !== null) {
                $values[$row->key] = $value;
            }
        }

        return $values;
    }
}
