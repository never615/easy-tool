<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Cache\Store;

/** Internal console-worker adapter: keep exception budgets on their original store. */
final class QueueWorkerControlStore implements Store
{
    public const RESTART_KEY = 'illuminate:queue:restart';

    public function __construct(private readonly Store $original, private readonly Store $control)
    {
    }

    private function target($key): Store
    {
        return $key === self::RESTART_KEY ? $this->control : $this->original;
    }

    public function get($key) { return $this->target($key)->get($key); }

    public function many(array $keys)
    {
        if (!in_array(self::RESTART_KEY, $keys, true)) return $this->original->many($keys);
        $other = array_values(array_filter($keys, static fn ($key) => $key !== self::RESTART_KEY));
        $values = $other === [] ? [] : $this->original->many($other);
        $values[self::RESTART_KEY] = $this->control->get(self::RESTART_KEY);
        $ordered = [];
        foreach ($keys as $key) $ordered[$key] = $values[$key] ?? null;
        return $ordered;
    }

    public function put($key, $value, $seconds) { return $this->target($key)->put($key, $value, $seconds); }

    public function putMany(array $values, $seconds)
    {
        if (!array_key_exists(self::RESTART_KEY, $values)) return $this->original->putMany($values, $seconds);
        $restart = $values[self::RESTART_KEY];
        unset($values[self::RESTART_KEY]);
        $original = $values === [] || $this->original->putMany($values, $seconds);
        $control = $this->control->put(self::RESTART_KEY, $restart, $seconds);
        return $original && $control;
    }

    public function increment($key, $value = 1) { return $this->target($key)->increment($key, $value); }
    public function decrement($key, $value = 1) { return $this->target($key)->decrement($key, $value); }

    public function forever($key, $value)
    {
        if ($key !== self::RESTART_KEY) return $this->original->forever($key, $value);
        // New CLI commands still signal old workers during deployment/rollback.
        // Do not replay either write on an error; the command must surface it.
        $original = $this->original->forever($key, $value);
        $control = $this->control->forever($key, $value);
        if (!$original || !$control) {
            // RestartCommand ignores false, so a partial write must throw to fail the CLI.
            throw new \RuntimeException('Failed to write queue restart signal to both cache stores');
        }
        return true;
    }

    public function forget($key) { return $this->target($key)->forget($key); }

    public function flush()
    {
        // This repository belongs only to the three control commands, never cache:clear.
        throw new \LogicException('Worker control cache does not expose shared-store flush');
    }

    public function getPrefix() { return $this->original->getPrefix(); }

    public function add($key, $value, $seconds)
    {
        $store = $this->target($key);
        if (method_exists($store, 'add')) return $store->add($key, $value, $seconds);
        return $store->get($key) === null && $store->put($key, $value, $seconds);
    }

    public function __call($method, $arguments) { return $this->original->{$method}(...$arguments); }
}
