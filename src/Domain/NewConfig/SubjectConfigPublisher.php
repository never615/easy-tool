<?php

namespace Mallto\Tool\Domain\NewConfig;

use Illuminate\Support\Facades\Schema;
use Mallto\Admin\Data\SubjectConfig;

class SubjectConfigPublisher
{
    public function __construct(private ?SubjectConfigValuesFile $valuesFile = null)
    {
    }

    public function publish(?string $valuesFilePath = null): array
    {
        $values = $this->values();
        $writeResult = $this->valuesFile()->write($values, $valuesFilePath ?: $this->valuesFilePath());

        return [
            'values' => $values,
            'values_file' => $writeResult,
            'counts' => [
                'subjects' => count($values),
                'values' => array_sum(array_map('count', $values)),
            ],
        ];
    }

    public function values(): array
    {
        if (!Schema::hasTable('subject_configs')) {
            return [];
        }

        $query = SubjectConfig::query()
            ->orderBy('subject_id')
            ->orderBy('key')
            ->orderBy('id');

        if (Schema::hasColumn('subject_configs', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $values = [];
        foreach ($query->get(['id', 'subject_id', 'key', 'value']) as $row) {
            $subjectId = (int)$row->subject_id;
            $key = trim((string)$row->key);
            if ($subjectId <= 0 || $key === '') {
                continue;
            }

            if (array_key_exists($key, $values[$subjectId] ?? [])) {
                continue;
            }

            $values[$subjectId][$key] = $row->value === null ? null : (string)$row->value;
        }

        foreach ($values as &$configs) {
            ksort($configs);
        }
        unset($configs);
        ksort($values);

        return $values;
    }

    private function valuesFile(): SubjectConfigValuesFile
    {
        return $this->valuesFile ?: app(SubjectConfigValuesFile::class);
    }

    private function valuesFilePath(): string
    {
        return (string)config('subject_config_runtime.values_file', storage_path('framework/subject_configs_values.php'));
    }
}
