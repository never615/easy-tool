<?php

namespace Mallto\Tool\Domain\NewConfig;

use Carbon\Carbon;
use Illuminate\Support\Collection;
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

    public function publish(bool $reload = true): array
    {
        $rows = $this->publishableRows();
        $values = $this->envValues($rows);
        $requiresReload = $rows->contains(function (NewConfig $row) {
            return $row->requires_reload && $this->publishValue($row) !== null;
        });

        try {
            $writeResult = $this->envFile->write($values);
            $configCacheResult = null;
            if (($writeResult['changed'] ?? false) && !$this->isTestRun()) {
                $configCacheResult = $this->configCacheService->refreshIfCached();
            }

            $reloadResult = null;
            if ($reload && $requiresReload && ($writeResult['changed'] ?? false) && !$this->isTestRun()) {
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

    private function publishableRows(): Collection
    {
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

            $values[$row->env_key] = $this->normalizeValue($row, $value);
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
