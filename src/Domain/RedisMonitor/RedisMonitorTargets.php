<?php

namespace Mallto\Tool\Domain\RedisMonitor;

final class RedisMonitorTargets
{
    /** Resolve aliases by endpoint; INFO describes an instance, not a logical DB. */
    public static function resolve(array $targets, array $redis): array
    {
        $resolved = [];
        foreach ($targets as $role => $name) {
            if (!is_string($name) || !is_array($redis[$name] ?? null)) {
                $id = substr(hash('sha256', 'missing:'.(string)$role), 0, 16);
                $resolved[$id] = ['id' => $id, 'connection' => is_string($name) ? $name : 'invalid',
                    'roles' => [(string)$role], 'profile' => []];
                continue;
            }
            // Match Laravel's effective options before enforcing the probe budget.
            $profile = array_merge($redis[$name], (array)($redis['options'] ?? []),
                (array)($redis[$name]['options'] ?? []));
            unset($profile['options']);
            if (isset($profile['url']) || !isset($profile['host'])) {
                $id = substr(hash('sha256', 'unsupported:'.(string)$role), 0, 16);
                $resolved[$id] = ['id' => $id, 'connection' => $name, 'roles' => [(string)$role], 'profile' => []];
                continue;
            }
            $identity = hash('sha256', json_encode([
                strtolower((string)($profile['scheme'] ?? 'tcp')),
                strtolower((string)$profile['host']),
                (int)($profile['port'] ?? 6379),
            ], JSON_THROW_ON_ERROR));
            if (isset($resolved[$identity])) {
                $resolved[$identity]['roles'][] = (string)$role;
                continue;
            }
            unset($profile['prefix'], $profile['persistent_id'], $profile['backoff_algorithm'],
                $profile['backoff_base'], $profile['backoff_cap'], $profile['max_retries']);
            $profile['options'] = [];
            // phpredis 5.3.x rejects OPT_MAX_RETRIES before connect(); set it on the live probe instead.
            $profile = array_merge($profile, [
                'persistent' => false, 'timeout' => 0.2, 'read_timeout' => 0.1,
                'read_write_timeout' => 0.1, 'retry_interval' => 0,
            ]);
            $resolved[$identity] = [
                'id' => substr($identity, 0, 16),
                'connection' => $name,
                'roles' => [(string)$role],
                'profile' => $profile,
            ];
        }
        return array_values($resolved);
    }
}
