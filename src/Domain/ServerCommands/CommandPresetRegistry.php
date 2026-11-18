<?php

namespace Mallto\Tool\Domain\ServerCommands;

use InvalidArgumentException;

class CommandPresetRegistry
{
    private array $presets = [];

    public function register(array $preset): void
    {
        if (empty($preset['id']) || empty($preset['command'])) { throw new InvalidArgumentException('Invalid preset definition'); }
        $preset += ['title' => $preset['id'], 'description' => '', 'options' => [], 'fixed_arguments' => [],
            'readonly' => true, 'permission' => 'server_commands.execute_presets'];
        $preset['version'] = hash('sha256', json_encode($preset, JSON_THROW_ON_ERROR));
        $this->presets[$preset['id']] = $preset;
    }

    public function all(): array { return array_values($this->presets); }

    public function get(string $id): array
    {
        return $this->presets[$id] ?? throw new InvalidArgumentException('预设不存在，请刷新页面。');
    }

    public function build(string $id, array $parameters): array
    {
        $preset = $this->get($id);
        if (array_diff(array_keys($parameters), array_keys($preset['options']))) {
            throw new InvalidArgumentException('预设包含未登记参数；请使用自定义命令。');
        }
        $argv = [$preset['command'], ...$preset['fixed_arguments']];
        foreach ($preset['options'] as $name => $spec) {
            $value = $parameters[$name] ?? $spec['default'] ?? null;
            if ($value === null || $value === '') {
                if ($spec['required'] ?? false) { throw new InvalidArgumentException(($spec['label'] ?? $name) . '不能为空。'); }
                continue;
            }
            if (!is_scalar($value)) { throw new InvalidArgumentException('预设参数格式错误。'); }
            if (($spec['type'] ?? 'string') === 'integer') {
                if (filter_var($value, FILTER_VALIDATE_INT) === false
                    || (int)$value < ($spec['min'] ?? PHP_INT_MIN) || (int)$value > ($spec['max'] ?? PHP_INT_MAX)) {
                    throw new InvalidArgumentException(($spec['label'] ?? $name) . '必须是范围内的整数。');
                }
                $value = (int)$value;
            }
            if (isset($spec['choices']) && !in_array((string)$value, array_map('strval', $spec['choices']), true)) {
                throw new InvalidArgumentException(($spec['label'] ?? $name) . '不在允许范围内。');
            }
            $argv[] = '--' . $name . '=' . $value;
        }
        return $argv;
    }

    /** Unknown options/name elevate to custom; malformed known parameters remain errors. */
    public function match(string $id, array $argv): ?array
    {
        $preset = $this->get($id);
        $prefix = [$preset['command'], ...$preset['fixed_arguments']];
        if (array_slice($argv, 0, count($prefix)) !== $prefix) { return null; }
        $parameters = [];
        for ($i = count($prefix); $i < count($argv); $i++) {
            if (!preg_match('/^--([a-zA-Z0-9_-]+)(?:=(.*))?$/sD', $argv[$i], $match)
                || !array_key_exists($match[1], $preset['options']) || array_key_exists($match[1], $parameters)) { return null; }
            $value = $match[2] ?? ($argv[++$i] ?? null);
            if ($value === null || str_starts_with((string)$value, '--')) { throw new InvalidArgumentException('预设参数缺少值。'); }
            $parameters[$match[1]] = $value;
        }
        $canonical = $this->build($id, $parameters);
        // Defaults become explicit so reviewed and executed arguments are identical.
        $parameters = [];
        foreach (array_slice($canonical, count($prefix)) as $option) {
            [$name, $value] = explode('=', substr($option, 2), 2);
            $parameters[$name] = $value;
        }
        return ['preset' => $preset, 'parameters' => $parameters, 'argv' => $canonical];
    }

    public function registerDefaults(): void
    {
        foreach ([
            ['system.migrations', '数据库迁移状态', 'migrate:status', [], '只查看已执行/待执行迁移，不执行迁移。'],
            ['system.horizon', 'Horizon 运行状态', 'horizon:status', [], '查看当前环境 Horizon 状态。'],
            ['system.supervisors', 'Horizon 进程列表', 'horizon:supervisors', [], '查看已登记的 supervisor。'],
            ['system.environment', '应用环境摘要', 'about', ['--only=environment'], '查看 CLI 执行端环境，不代表所有 Web 节点。'],
            ['system.commands', '可用 Artisan 命令', 'list', ['--raw'], '列出当前安装版本的命令。'],
            ['system.routes', '应用路由概览', 'route:list', ['--except-vendor'], '只列应用路由；输出较长时会截断。'],
        ] as [$id, $title, $command, $fixed, $description]) {
            $this->register(compact('id', 'title', 'command', 'description') + ['fixed_arguments' => $fixed]);
        }
    }
}
