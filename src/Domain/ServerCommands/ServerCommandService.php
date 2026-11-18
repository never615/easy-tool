<?php

namespace Mallto\Tool\Domain\ServerCommands;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mallto\Tool\Jobs\ServerCommandJob;

class ServerCommandService
{
    public function __construct(
        private CommandPresetRegistry $registry,
        private CommandTextParser $parser,
        private ServerCommandAuthorizer $auth,
        private ArtisanCommandValidator $validator,
        private ServerCommandStore $store
    ) {}

    public function presets($actor): array
    {
        $this->auth->require($actor, 'server_commands.index');
        return array_values(array_filter($this->registry->all(), fn ($preset) => $this->auth->allows($actor, $preset['permission'])));
    }

    public function preview($actor, array $input): array
    {
        $payload = $this->prepare($actor, $input);
        return [
            'command' => CommandOutput::command($payload['argv']),
            'preset_id' => $payload['preset_id'], 'timeout' => $payload['timeout'],
            'requested_env' => $payload['requested_env'],
            'requires_confirmation' => $payload['requires_confirmation'],
            'confirmation_token' => $payload['requires_confirmation'] ? Crypt::encryptString(json_encode([
                'actor_id' => (int)$actor->id, 'fingerprint' => $payload['fingerprint'], 'expires' => time() + 300,
            ], JSON_THROW_ON_ERROR)) : null,
        ];
    }

    public function submit($actor, array $input): array
    {
        $payload = $this->prepare($actor, $input);
        $submissionId = $input['submission_id'] ?? '';
        if (!is_string($submissionId) || !Str::isUuid($submissionId)) { throw new \InvalidArgumentException('提交编号无效，请刷新页面。'); }
        if ($payload['requires_confirmation']) {
            try { $confirmed = json_decode(Crypt::decryptString((string)($input['confirmation_token'] ?? '')), true, 512, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { $confirmed = []; }
            if (($confirmed['actor_id'] ?? null) !== (int)$actor->id || ($confirmed['expires'] ?? 0) < time()
                || !hash_equals($payload['fingerprint'], (string)($confirmed['fingerprint'] ?? ''))) {
                throw new \InvalidArgumentException('确认已失效或命令已改变，请重新预览并确认。');
            }
        }
        // Reject a misconfigured sync driver before reserving: never execute on the HTTP worker.
        if (config('queue.connections.server-commands.driver') !== 'redis') {
            throw new ServerCommandException('专用 Redis 执行队列尚未配置。');
        }
        $record = $this->store->reserve((int)$actor->id, $submissionId, $payload);
        if ($record->newly_created) {
            try {
                $queued = app(Dispatcher::class)->dispatch(new ServerCommandJob($record->id));
                if ($queued === false || $queued === null) {
                    $this->store->markUnknown($record->id, '队列未返回投递确认，请核查执行端；未自动重投。');
                }
            }
            catch (\Throwable) { $this->store->markUnknown($record->id, '队列投递结果未知，请核查执行端；未自动重投。'); }
            $this->audit('submitted', $actor, $record);
        }
        return $this->show($actor, $record->id, false);
    }

    /** Rechecked in the runner against fresh account, preset version and installed command. */
    public function authorizeExecution(object $record): void
    {
        $actor = $this->auth->reload((int)$record->actor_id);
        $payload = $record->payload;
        $this->authorizePayload($actor, $payload);
        $this->validator->validate($payload['argv']);
    }

    public function history($actor, int $limit = 20): array
    {
        $this->auth->require($actor, 'server_commands.index');
        return array_map(fn ($record) => $this->serialize($actor, $this->refreshStale($record)),
            $this->store->history($this->auth->allows($actor, 'server_commands.view_all') ? null : (int)$actor->id, $limit));
    }

    public function show($actor, string $id, bool $output = true): array
    {
        $this->auth->require($actor, 'server_commands.index');
        $record = $this->store->get($id, false);
        if ((int)$record->actor_id !== (int)$actor->id) { $this->auth->require($actor, 'server_commands.view_all'); }
        $record = $this->refreshStale($record);
        $result = $this->serialize($actor, $record);
        if ($output) {
            $record = $this->store->get($id);
            // A removed execution grant must not leave privileged command output readable.
            // Auditors explicitly granted view_all can inspect historical versions as well.
            if ($record->payload && !$this->auth->allows($actor, 'server_commands.view_all')) {
                if ($record->payload['preset_id'] === null) {
                    $this->auth->require($actor, 'server_commands.execute_any');
                } else {
                    $preset = $this->registry->get($record->payload['preset_id']);
                    $this->auth->require($actor, $preset['permission']);
                    if (isset($preset['subject_option'])) {
                        $match = $this->registry->match($preset['id'], $record->payload['argv']);
                        if (!$match) { throw new ServerCommandException('历史预设定义已改变，请由全部记录查看人员核查正文。'); }
                        $this->auth->subject($actor, (int)($match['parameters'][$preset['subject_option']] ?? 0));
                    }
                }
            }
            $result += ['stdout' => $record->output['stdout'] ?? '', 'stderr' => $record->output['stderr'] ?? '',
                'command' => isset($record->payload['argv']) ? CommandOutput::command($record->payload['argv']) : null,
                'requested_env' => $record->payload['requested_env'] ?? null,
                'body_expired' => empty($record->payload)];
        }
        return $result;
    }

    public function release($actor, string $id, string $confirmation): array
    {
        $this->auth->require($actor, 'server_commands.index');
        $this->auth->require($actor, 'server_commands.recover');
        $this->show($actor, $id, false);
        if ($confirmation !== '已确认进程停止') { throw new \InvalidArgumentException('请输入：已确认进程停止'); }
        $this->store->release($id, (int)$actor->id);
        $this->audit('released', $actor, $this->store->get($id, false));
        return $this->show($actor, $id, false);
    }

    private function prepare($actor, array $input): array
    {
        $this->auth->require($actor, 'server_commands.index');
        if (!filter_var(config('server_commands.enabled', false), FILTER_VALIDATE_BOOLEAN)) { throw new ServerCommandException('服务器命令执行未启用；历史记录仍可查看。'); }
        $timeout = $input['timeout'] ?? config('server_commands.default_timeout', 60);
        if (filter_var($timeout, FILTER_VALIDATE_INT) === false || $timeout < 1 || $timeout > min(300, (int)config('server_commands.max_timeout', 300))) {
            throw new \InvalidArgumentException('超时必须为 1 至 300 秒内的整数。');
        }
        $presetId = $input['preset_id'] ?? null;
        $text = $input['text'] ?? '';
        if (!is_string($text) || ($presetId !== null && !is_string($presetId)) || !is_array($input['parameters'] ?? [])) {
            throw new \InvalidArgumentException('命令输入格式无效。');
        }
        $argv = trim($text) !== '' ? $this->parser->parse($text)
            : ($presetId ? $this->registry->build($presetId, $input['parameters'] ?? []) : $this->parser->parse(''));
        if (strlen($this->parser->format($argv)) > (int)config('server_commands.max_input_bytes', 16384)) {
            throw new \InvalidArgumentException('命令和参数合计不可超过 16 KiB。');
        }
        $match = $presetId ? $this->registry->match($presetId, $argv) : null;
        $payload = ['argv' => $match['argv'] ?? $argv, 'timeout' => (int)$timeout,
            'preset_id' => $match['preset']['id'] ?? null, 'preset_version' => $match['preset']['version'] ?? null,
            'requires_confirmation' => !($match['preset']['readonly'] ?? false)];
        $this->authorizePayload($actor, $payload);
        $payload['requested_env'] = $this->validator->validate($payload['argv']);
        $payload['fingerprint'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return $payload;
    }

    private function authorizePayload($actor, array $payload): void
    {
        $this->auth->require($actor, 'server_commands.index');
        if ($payload['preset_id'] === null) { $this->auth->require($actor, 'server_commands.execute_any'); return; }
        $match = $this->registry->match($payload['preset_id'], $payload['argv']);
        if (!$match || !hash_equals($match['preset']['version'], $payload['preset_version'])) {
            throw new \InvalidArgumentException('执行端预设版本已改变，请重新预览。');
        }
        $this->auth->require($actor, $match['preset']['permission']);
        if (isset($match['preset']['subject_option'])) {
            $this->auth->subject($actor, (int)($match['parameters'][$match['preset']['subject_option']] ?? 0));
        }
    }

    private function refreshStale(object $record): object
    {
        if ($record->status === 'running' && $record->started_at && strtotime($record->started_at) < time() - 360) {
            $this->store->markUnknown($record->id, '执行端超过回报期限，结果未知；请核查进程后解除占用。');
            $record = $this->store->get($record->id, false);
        }
        return $record;
    }

    private function serialize($actor, object $record): array
    {
        $result = [];
        foreach (['id','actor_id','status','command_name','created_at','started_at','finished_at','exit_code','executor','truncated','timeout','summary'] as $field) {
            $result[$field] = $record->$field ?? null;
        }
        $result['can_release'] = in_array($record->status, ['queued','unknown','timed_out'], true)
            && $this->auth->allows($actor, 'server_commands.recover');
        return $result;
    }

    private function audit(string $event, $actor, object $record): void
    {
        Log::info('server_command.' . $event, ['id' => $record->id, 'actor_id' => (int)$actor->id,
            'command_name' => $record->command_name, 'status' => $record->status]);
    }
}
