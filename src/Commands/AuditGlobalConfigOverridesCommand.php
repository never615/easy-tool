<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigBaselineResolver;
use Mallto\Tool\Domain\NewConfig\GlobalConfigDefinitions;

/**
 * 审计现有全局配置的覆盖来源，输出只包含哈希，不泄露原始配置值。
 */
class AuditGlobalConfigOverridesCommand extends Command
{
    protected $signature = 'tool:global_config_override_audit
        {--json : 输出机器可读 JSON}';

    protected $description = 'Audit global config override provenance without printing raw values';

    public function handle(GlobalConfigBaselineResolver $baselineResolver): int
    {
        $rows = NewConfig::query()
            ->where('group_key', NewConfig::GROUP_GLOBAL_CONFIG)
            ->orderBy('key')
            ->get()
            ->map(function (NewConfig $row) use ($baselineResolver): array {
                $definition = GlobalConfigDefinitions::find((string)$row->key);
                $baseline = $definition === null
                    ? null
                    : $baselineResolver->forDefinition($definition);
                $databaseHash = hash('sha256', (string)$row->value);
                $baselineHash = hash('sha256', (string)$baseline);

                return [
                    'key' => (string)$row->key,
                    'definition_registered' => $definition !== null,
                    'override_source' => $row->override_source,
                    'enabled' => (bool)$row->is_enabled,
                    'database_value_sha256' => $databaseHash,
                    'baseline_value_sha256' => $baselineHash,
                    'values_match' => hash_equals($databaseHash, $baselineHash),
                ];
            })
            ->values();

        $unclassified = $rows->filter(static function (array $row): bool {
            return $row['override_source'] === null
                && $row['enabled'];
        })->count();
        $result = [
            'status' => $unclassified === 0 ? 'classified' : 'unclassified',
            'unclassified_count' => $unclassified,
            'rows' => $rows->all(),
        ];

        if ($this->option('json')) {
            $this->line((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['key', 'definition', 'source', 'enabled', 'database sha256', 'baseline sha256', 'match'],
                $rows->map(static function (array $row): array {
                    return [
                        $row['key'],
                        $row['definition_registered'] ? 'yes' : 'no',
                        $row['override_source'] ?? 'UNCLASSIFIED',
                        $row['enabled'] ? 'yes' : 'no',
                        $row['database_value_sha256'],
                        $row['baseline_value_sha256'],
                        $row['values_match'] ? 'yes' : 'no',
                    ];
                })->all()
            );
        }

        return $unclassified === 0 ? self::SUCCESS : 2;
    }
}
