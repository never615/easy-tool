<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Admin\Domain\SubjectConfig\SubjectConfigDefinitions;


/**
 * SubjectConfigPublisher：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class SubjectConfigPublisher
{
    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(private ?SubjectConfigValuesFile $valuesFile = null)
    {
    }

    /**
     * 处理「publish」相关的内部协作步骤。
     */
    public function publish(?string $valuesFilePath = null): array
    {
        $valuesFilePath = $valuesFilePath ?: $this->valuesFilePath();
        $oldValues = SubjectConfigRuntimeValues::load($valuesFilePath);
        $valuesResult = $this->buildValues();
        $values = $valuesResult['values'];
        $snapshotChangedKeys = $this->snapshotDiff($oldValues, $values);
        $writeResult = $this->valuesFile()->write($values, $valuesFilePath);

        return [
            'values' => $values,
            'values_file' => $writeResult,
            'snapshot_changed_keys' => $snapshotChangedKeys,
            'requires_reload' => false,
            'counts' => [
                'subjects' => count($values),
                'values' => array_sum(array_map('count', $values)),
                'excluded_live_values' => $valuesResult['excluded_live_values'],
            ],
        ];
    }

    /**
     * 处理「values」相关的内部协作步骤。
     */
    public function values(): array
    {
        return $this->buildValues()['values'];
    }

    /**
     * Subject 业务配置统一通过运行缓存生效，生成快照始终为空。
     */
    private function buildValues(): array
    {
        return [
            'values' => [],
            'excluded_live_values' => 0,
        ];
    }

    /**
     * 对旧、新快照进行稳定 diff；该结果只描述生成文件，不代表数据库 dirty rows。
     */
    private function snapshotDiff(array $oldValues, array $newValues): array
    {
        $oldMap = $this->flattenValues($oldValues);
        $newMap = $this->flattenValues($newValues);
        $identities = array_unique(array_merge(array_keys($oldMap), array_keys($newMap)));
        $changedKeys = [];

        foreach ($identities as $identity) {
            $oldExists = array_key_exists($identity, $oldMap);
            $newExists = array_key_exists($identity, $newMap);
            if ($oldExists && $newExists && $oldMap[$identity]['value'] === $newMap[$identity]['value']) {
                continue;
            }

            $item = $newExists ? $newMap[$identity] : $oldMap[$identity];
            $changedKeys[$identity] = [
                'subject_id' => $item['subject_id'],
                'key' => $item['key'],
                'change' => !$oldExists ? 'added' : (!$newExists ? 'removed' : 'updated'),
                'apply_mode' => SubjectConfigDefinitions::applyModeFor($item['key']),
            ];
        }

        $changedKeys = array_values($changedKeys);
        usort($changedKeys, static function (array $left, array $right): int {
            return [$left['subject_id'], $left['key']] <=> [$right['subject_id'], $right['key']];
        });

        return $changedKeys;
    }

    /**
     * 将二维 subject 配置转换为可比较的唯一身份映射。
     */
    private function flattenValues(array $values): array
    {
        $flattened = [];
        foreach ($values as $subjectId => $configs) {
            $subjectId = (int)$subjectId;
            if ($subjectId <= 0 || !is_array($configs)) {
                continue;
            }

            foreach ($configs as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }

                $identity = $subjectId . "\0" . $key;
                $flattened[$identity] = [
                    'subject_id' => $subjectId,
                    'key' => $key,
                    'value' => $value === null ? null : (string)$value,
                ];
            }
        }

        return $flattened;
    }

    /**
     * 处理「valuesFile」相关的内部协作步骤。
     */
    private function valuesFile(): SubjectConfigValuesFile
    {
        return $this->valuesFile ?: app(SubjectConfigValuesFile::class);
    }

    /**
     * 处理「valuesFilePath」相关的内部协作步骤。
     */
    private function valuesFilePath(): string
    {
        return (string)config('subject_config_runtime.values_file', storage_path('framework/subject_configs_values.php'));
    }
}
