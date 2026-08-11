<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigCache;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigIdentity;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigInvalidator;

final class GlobalConfigRuntimeResolver
{
    public function __construct(
        private readonly RuntimeConfigCache $cache,
        private readonly RuntimeConfigInvalidator $invalidator
    ) {
    }

    public function get(string $key, mixed $default = null, bool $cacheNullValue = true): mixed
    {
        return $this->resolve($key, $default, $cacheNullValue, false);
    }

    public function fresh(string $key, mixed $default = null, bool $cacheNullValue = true): mixed
    {
        return $this->resolve($key, $default, $cacheNullValue, true);
    }

    public function replace(NewConfig $config): void
    {
        $key = trim((string)$config->key);
        if ($key === '' || $config->group_key !== NewConfig::GROUP_GLOBAL_CONFIG) {
            return;
        }

        $this->invalidator->replaceAfterCommit(
            RuntimeConfigIdentity::global($key),
            GlobalConfigApplyMode::forKey($key),
            self::payloadFor($config)
        );
    }

    public function forget(string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }

        $this->invalidator->replaceAfterCommit(
            RuntimeConfigIdentity::global($key),
            GlobalConfigApplyMode::forKey($key),
            ['found' => false, 'value' => null]
        );
    }

    /** @return array{found: bool, value: mixed} */
    public static function payloadFor(NewConfig $config): array
    {
        if ($config->group_key !== NewConfig::GROUP_GLOBAL_CONFIG) {
            return ['found' => false, 'value' => null];
        }

        if ($config->override_source === null || !$config->is_enabled) {
            return ['found' => false, 'value' => null];
        }

        $value = $config->value;
        if ($value !== null) {
            $value = match ((string)$config->type) {
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
                'integer' => (string)((int)$value),
                'float' => (string)((float)$value),
                default => (string)$value,
            };
        }

        return ['found' => $value !== null, 'value' => $value];
    }

    private function resolve(string $key, mixed $default, bool $cacheNullValue, bool $fresh): mixed
    {
        $identity = RuntimeConfigIdentity::global($key);
        $loader = fn (): array => $this->load($key);
        $payload = $fresh
            ? $this->cache->fresh($identity, GlobalConfigApplyMode::forKey($key), $loader)
            : $this->cache->remember($identity, GlobalConfigApplyMode::forKey($key), $loader);

        if (!$payload['found']) {
            return $default ?? ($cacheNullValue ? '' : null);
        }

        return $payload['value'];
    }

    /** @return array{found: bool, value: mixed} */
    private function load(string $key): array
    {
        $config = NewConfig::query()
            ->where('group_key', NewConfig::GROUP_GLOBAL_CONFIG)
            ->where('key', $key)
            ->first();

        return $config === null
            ? ['found' => false, 'value' => null]
            : self::payloadFor($config);
    }
}
