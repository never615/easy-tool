<?php

namespace Mallto\Tool\Domain\NewConfig;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Data\NewConfig;
use Throwable;

class NewConfigPublisher
{
    public function __construct(
        private NewConfigEnvFile $envFile,
        private LaravelConfigCacheService $configCacheService,
        private LaravelSReloadService $reloadService
    ) {
    }

    public function publish(bool $reload = false, bool $forceConfigCache = false, ?string $envFilePath = null): array
    {
        $rows = $this->publishableRows();

        try {
            $values = $this->envValues($rows);
            $requiresReload = $rows->contains(function (NewConfig $row) {
                return $row->requires_reload && $this->publishValue($row) !== null;
            });
            $writeResult = $this->envFile->write($values, $envFilePath);
            $configCacheResult = null;
            $shouldRefreshConfigCache = ($writeResult['changed'] ?? false) || $forceConfigCache;
            if ($shouldRefreshConfigCache && !$this->isTestRun()) {
                $configCacheResult = $this->configCacheService->refresh($values, $forceConfigCache);
            }

            $reloadResult = null;
            if ($reload && $requiresReload && $shouldRefreshConfigCache && !$this->isTestRun()) {
                $reloadResult = $this->reloadService->reload();
            }

            $this->markPublished($rows, null);

            return [
                'values' => $values,
                'write' => $writeResult,
                'config_cache' => $configCacheResult,
                'reload' => $reloadResult,
            ];
        } catch (Throwable $exception) {
            $this->markPublished($rows, $exception->getMessage());
            throw $exception;
        }
    }

    public function exportValues(): array
    {
        return $this->envValues($this->publishableRows());
    }

    private function publishableRows(): Collection
    {
        if (!Schema::hasTable('new_configs') || !Schema::hasColumn('new_configs', 'env_key')) {
            return collect();
        }

        return NewConfig::query()
            ->whereNotNull('env_key')
            ->where('env_key', '<>', '')
            ->orderBy('group_key')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    private function envValues(Collection $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $this->publishValue($row);
            if ($value === null) {
                continue;
            }

            $envKey = NewConfigBootstrapKeyGuard::normalize($row->env_key);
            NewConfigBootstrapKeyGuard::assertAllowed($envKey);
            if ($envKey === null) {
                continue;
            }

            $values[$envKey] = $this->normalizeValue($row, $value);
        }

        ksort($values);

        return $values;
    }

    private function publishValue(NewConfig $row): ?string
    {
        if (!$row->is_enabled) {
            return $row->default_value;
        }

        $value = $row->value;
        if ($value === null || $value === '') {
            $value = $row->default_value;
        }

        return $value;
    }

    private function normalizeValue(NewConfig $row, string $value): string
    {
        return match ($row->type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string)((int)$value),
            'float' => (string)((float)$value),
            default => $value,
        };
    }

    private function markPublished(Collection $rows, ?string $error): void
    {
        $ids = $rows->pluck('id')->filter()->values()->all();
        if ($ids === []) {
            return;
        }

        NewConfig::withoutEvents(function () use ($ids, $error) {
            NewConfig::query()
                ->whereIn('id', $ids)
                ->update([
                    'last_published_at' => Carbon::now(),
                    'last_publish_error' => $error,
                ]);
        });
    }

    private function isTestRun(): bool
    {
        return app()->runningUnitTests();
    }
}
