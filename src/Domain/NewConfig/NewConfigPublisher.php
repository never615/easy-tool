<?php

namespace Mallto\Tool\Domain\NewConfig;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Data\NewConfig;
use Throwable;

/**
 * NewConfigPublisher：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class NewConfigPublisher
{
    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        private NewConfigEnvFile $envFile,
        private LaravelConfigCacheService $configCacheService,
        private LaravelSRestartService $restartService,
        private ?NewConfigGenerationStore $generationStore = null,
        private ?NewConfigValuesFile $valuesFile = null,
        private ?SubjectConfigPublisher $subjectConfigPublisher = null
    ) {
    }

    /**
     * 发布当前全部可用配置。
     *
     * 该流程依次生成环境变量和运行值快照、刷新配置缓存；仅在配置确有变化且调用方要求时，
     * 才递增版本并请求 LaravelS/Horizon 重载。
     */
    public function publish(bool $restart = false, bool $forceConfigCache = false, ?string $envFilePath = null): array
    {
        $rows = $this->publishableRows();

        try {
            $values = $this->envValues($rows);
            $runtimeValues = $this->runtimeValues($rows);
            $requiresRestart = $rows->contains(function (NewConfig $row) {
                return $row->requires_reload && $this->publishValue($row) !== null;
            });
            $writeResult = $this->envFile->write($values, $envFilePath);
            $valuesWriteResult = $this->valuesFile()->write($runtimeValues);
            $subjectConfigResult = $this->subjectConfigPublisher()->publish();
            $subjectConfigChanged = (bool)($subjectConfigResult['values_file']['changed'] ?? false);
            $subjectConfigRequiresReload = (bool)($subjectConfigResult['requires_reload'] ?? false);
            $requiresRestart = $requiresRestart || $subjectConfigRequiresReload;
            $configCacheResult = null;
            $shouldRefreshConfigCache = ($writeResult['changed'] ?? false)
                || ($valuesWriteResult['changed'] ?? false)
                || ($subjectConfigChanged && $subjectConfigRequiresReload)
                || $forceConfigCache;
            if ($shouldRefreshConfigCache && !$this->isTestRun()) {
                $configCacheResult = $this->configCacheService->refresh($values, $forceConfigCache);
            }

            $restartResult = null;
            $generationResult = null;
            if ($restart && ($requiresRestart || $forceConfigCache) && $shouldRefreshConfigCache && !$this->isTestRun()) {
                $generationResult = $this->generationStore()->bump();
                $restartResult = $this->restartService->restart();
            }

            $this->markPublished($rows, null);

            return [
                'values' => $values,
                'runtime_values' => $runtimeValues,
                'write' => $writeResult,
                'values_file' => $valuesWriteResult,
                'subject_config' => $subjectConfigResult,
                'config_cache' => $configCacheResult,
                'generation' => $generationResult,
                'restart' => $restartResult,
            ];
        } catch (Throwable $exception) {
            $this->markPublished($rows, $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * 导出将要写入环境变量文件的已规范化键值。
     */
    public function exportValues(): array
    {
        return $this->envValues($this->publishableRows());
    }

    /**
     * 读取按分组和排序号稳定排列的可发布配置。
     *
     * 安装或迁移尚未创建配置表/字段时返回空集合，使启动和升级流程可安全调用。
     */
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

    /**
     * 将配置记录转换为环境变量快照所需的键值对。
     *
     * 运行态覆写组使用更严格的 bootstrap key 白名单，防止配置中心破坏连接和启动基础设施。
     */
    private function envValues(Collection $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $this->publishValue($row);
            if ($value === null) {
                continue;
            }

            $envKey = NewConfigBootstrapKeyGuard::normalize($row->env_key);
            if ($row->group_key === NewConfig::GROUP_RUNTIME_ENV_OVERRIDE) {
                NewConfigBootstrapKeyGuard::assertAllowedForRuntimeOverride($envKey);
            } else {
                NewConfigBootstrapKeyGuard::assertAllowed($envKey);
            }
            if ($envKey === null) {
                continue;
            }

            $values[$envKey] = $this->normalizeValue($row, $value);
        }

        ksort($values);

        return $values;
    }

    /**
     * 将配置记录转换为运行值文件所需的业务键值对。
     */
    private function runtimeValues(Collection $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $this->publishValue($row);
            if ($value === null) {
                continue;
            }

            $key = trim((string)$row->key);
            if ($key === '') {
                continue;
            }

            $values[$key] = $this->normalizeValue($row, $value);
        }

        ksort($values);

        return $values;
    }

    /**
     * 计算一条配置此次应发布的值。
     *
     * 禁用配置始终回落到默认值；启用配置在空值时也使用默认值，避免发布空字符串覆盖安全默认项。
     */
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

    /**
     * 按定义类型将值规范为可持久化的字符串表示。
     */
    private function normalizeValue(NewConfig $row, string $value): string
    {
        return match ($row->type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string)((int)$value),
            'float' => (string)((float)$value),
            default => $value,
        };
    }

    /**
     * 回写本批记录的发布时间或发布错误。
     *
     * 通过 withoutEvents 避免状态回写再次触发模型保存事件而形成递归发布。
     */
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

    /**
     * 判断当前是否为单元测试；测试中禁止刷新缓存或重启常驻进程。
     */
    private function isTestRun(): bool
    {
        return app()->runningUnitTests();
    }

    /**
     * 延迟解析并返回跨进程配置版本存储。
     */
    private function generationStore(): NewConfigGenerationStore
    {
        return $this->generationStore ?: app(NewConfigGenerationStore::class);
    }

    /**
     * 延迟解析并返回运行值快照文件写入器。
     */
    private function valuesFile(): NewConfigValuesFile
    {
        return $this->valuesFile ?: app(NewConfigValuesFile::class);
    }

    /**
     * 延迟解析并返回 subject 配置快照发布器。
     */
    private function subjectConfigPublisher(): SubjectConfigPublisher
    {
        return $this->subjectConfigPublisher ?: app(SubjectConfigPublisher::class);
    }
}
