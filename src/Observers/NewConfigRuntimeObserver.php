<?php

namespace Mallto\Tool\Observers;

use Illuminate\Support\Facades\DB;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigRuntimeResolver;
use Mallto\Tool\Domain\NewConfig\NewConfigPublisher;
use Mallto\Tool\Domain\RuntimeConfig\Events\GlobalConfigChanged;

final class NewConfigRuntimeObserver
{
    public function saving(NewConfig $config): void
    {
        $config->runtimeConfigChangeContext = [
            'changed' => !$config->exists || $config->isDirty($this->effectiveFields()),
            'old_key' => trim((string)$config->getRawOriginal('key')),
            'old_group' => (string)$config->getRawOriginal('group_key'),
        ];
    }

    public function saved(NewConfig $config): void
    {
        $pending = $config->runtimeConfigChangeContext ?? [
            'changed' => $config->wasRecentlyCreated || $config->wasChanged($this->effectiveFields()),
            'old_key' => trim((string)$config->getRawOriginal('key')),
            'old_group' => (string)$config->getRawOriginal('group_key'),
        ];
        $config->runtimeConfigChangeContext = null;

        if (!$pending['changed']) {
            return;
        }

        $oldKey = $pending['old_key'];
        $oldGroup = $pending['old_group'];
        $newKey = trim((string)$config->key);
        $newGroup = (string)$config->group_key;

        $resolver = app(GlobalConfigRuntimeResolver::class);
        if ($oldGroup === NewConfig::GROUP_GLOBAL_CONFIG && $oldKey !== '' &&
            ($oldKey !== $newKey || $newGroup !== NewConfig::GROUP_GLOBAL_CONFIG)) {
            $resolver->forget($oldKey);
        }

        if ($newGroup === NewConfig::GROUP_GLOBAL_CONFIG && $newKey !== '') {
            $resolver->replace($config);
            $this->dispatchChangedAfterCommit(
                $config,
                $newKey,
                $oldKey !== '' && $oldKey !== $newKey ? $oldKey : null
            );
        } elseif ($oldGroup === NewConfig::GROUP_GLOBAL_CONFIG && $oldKey !== '') {
            $this->dispatchChangedAfterCommit($config, $oldKey, $oldKey);
        }

        if ($oldKey !== '' && $oldGroup !== $newGroup &&
            ($oldGroup !== NewConfig::GROUP_GLOBAL_CONFIG || $newGroup !== NewConfig::GROUP_GLOBAL_CONFIG)) {
            $this->publishBootstrapAfterCommit($config);
        } elseif ($newGroup !== NewConfig::GROUP_GLOBAL_CONFIG) {
            $this->publishBootstrapAfterCommit($config);
        }
    }

    public function deleted(NewConfig $config): void
    {
        $key = trim((string)$config->key);
        if ($config->group_key === NewConfig::GROUP_GLOBAL_CONFIG && $key !== '') {
            app(GlobalConfigRuntimeResolver::class)->forget($key);
            $this->dispatchChangedAfterCommit($config, $key, $key);

            return;
        }

        $this->publishBootstrapAfterCommit($config);
    }

    /** @return list<string> */
    private function effectiveFields(): array
    {
        return ['key', 'group_key', 'type', 'value', 'default_value', 'is_enabled'];
    }

    private function dispatchChangedAfterCommit(NewConfig $config, string $key, ?string $originalKey): void
    {
        DB::connection($config->getConnectionName())->afterCommit(
            static fn () => event(new GlobalConfigChanged($key, $originalKey))
        );
    }

    private function publishBootstrapAfterCommit(NewConfig $config): void
    {
        if (!NewConfig::shouldAutoPublish()) {
            return;
        }

        DB::connection($config->getConnectionName())->afterCommit(
            static fn () => app(NewConfigPublisher::class)->publish(false)
        );
    }
}
