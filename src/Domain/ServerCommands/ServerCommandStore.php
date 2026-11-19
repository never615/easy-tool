<?php

namespace Mallto\Tool\Domain\ServerCommands;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable admission and single-flight ownership share one database transaction. */
class ServerCommandStore
{
    private ConnectionInterface $connection;

    public function __construct()
    {
        // Resolve once in the parent; a child --env never chooses the control database.
        $this->connection = DB::connection();
    }

    public function reserve(int $actorId, string $submissionId, array $payload): object
    {
        return $this->connection->transaction(function () use ($actorId, $submissionId, $payload) {
            $slot = $this->slot();
            $existing = $this->connection->table('server_command_runs')->where('actor_id', $actorId)
                ->where('submission_id', $submissionId)->first();
            if ($existing) {
                if (!hash_equals($existing->fingerprint, $payload['fingerprint'])) {
                    throw new ServerCommandException('此提交编号已用于另一条命令，请重新提交。');
                }
                $existing = $this->decode($existing); $existing->newly_created = false;
                return $existing;
            }
            if ($slot->run_id !== null) {
                throw new ServerCommandException('已有命令未结束，请先查看执行记录：' . $slot->run_id);
            }
            $id = (string)Str::uuid();
            $this->connection->table('server_command_runs')->insert([
                'id' => $id, 'actor_id' => $actorId, 'submission_id' => $submissionId,
                'fingerprint' => $payload['fingerprint'], 'command_name' => $payload['argv'][0],
                'status' => 'queued', 'payload' => $this->encrypt($payload),
                'timeout' => $payload['timeout'], 'created_at' => now(),
            ]);
            $this->connection->table('server_command_slots')->where('id', 1)->update(['run_id' => $id]);
            $record = $this->get($id); $record->newly_created = true;
            return $record;
        });
    }

    public function claim(string $id): ?object
    {
        return $this->connection->transaction(function () use ($id) {
            $slot = $this->slot();
            $record = $this->get($id);
            if ($slot->run_id !== $id || $record->status !== 'queued') { return null; }
            $this->connection->table('server_command_runs')->where('id', $id)->update([
                'status' => 'running', 'started_at' => now(),
                'executor' => json_encode(['hostname' => gethostname() ?: 'unknown',
                    'role' => 'horizon/server-commands', 'worker_pid' => getmypid(),
                    'environment' => config('app.env'), 'php' => PHP_VERSION], JSON_THROW_ON_ERROR),
            ]);
            return $this->get($id);
        });
    }

    public function started(string $id, array $executor): void
    {
        $record = $this->get($id);
        $this->connection->table('server_command_runs')->where('id', $id)->whereIn('status', ['running', 'unknown'])
            ->update(['executor' => json_encode(array_merge($record->executor, $executor), JSON_THROW_ON_ERROR)]);
    }

    public function complete(string $id, string $status, array $result): void
    {
        if (!in_array($status, ['succeeded', 'failed', 'timed_out', 'not_started', 'unknown'], true)) {
            throw new \InvalidArgumentException('Invalid command completion status');
        }
        $this->connection->transaction(function () use ($id, $status, $result) {
            $slot = $this->slot();
            $record = $this->get($id);
            if ($slot->run_id !== $id || !in_array($record->status, ['running', 'unknown'], true)) { return; }
            $this->connection->table('server_command_runs')->where('id', $id)->update([
                'status' => $status, 'exit_code' => $result['exit_code'] ?? null,
                'truncated' => $result['truncated'] ?? false,
                'summary' => $result['summary'] ?? null, 'finished_at' => now(),
                'output' => $this->encrypt(['stdout' => $result['stdout'] ?? '', 'stderr' => $result['stderr'] ?? '']),
                'executor' => json_encode(array_merge($record->executor, $result['executor'] ?? []), JSON_THROW_ON_ERROR),
            ]);
            // Timeout can leave descendants or side effects; explicit recovery retains evidence.
            if (!in_array($status, ['unknown', 'timed_out'], true)) {
                $this->connection->table('server_command_slots')->where('id', 1)->update(['run_id' => null]);
            }
        });
    }

    public function markUnknown(string $id, string $reason): void
    {
        $this->connection->transaction(function () use ($id, $reason): void {
            // Serialize uncertainty with claim/release; a concurrent claim must not overwrite unknown.
            if ($this->slot()->run_id !== $id) { return; }
            $this->connection->table('server_command_runs')->where('id', $id)->whereIn('status', ['queued', 'running'])
                ->update(['status' => 'unknown', 'summary' => $reason]);
        });
    }

    public function release(string $id, int $operatorId): void
    {
        $this->connection->transaction(function () use ($id, $operatorId) {
            $slot = $this->slot();
            $record = $this->get($id);
            if ($slot->run_id !== $id || !in_array($record->status, ['queued', 'unknown', 'timed_out'], true)) {
                throw new ServerCommandException('该命令不可解除占用，请先刷新状态。');
            }
            $this->connection->table('server_command_runs')->where('id', $id)->update([
                'status' => 'released', 'finished_at' => now(), 'released_by' => $operatorId,
                'summary' => '人工确认后解除占用；不代表命令成功，也未自动重跑。',
            ]);
            $this->connection->table('server_command_slots')->where('id', 1)->update(['run_id' => null]);
        });
    }

    public function get(string $id, bool $body = true): object
    {
        $query = $this->connection->table('server_command_runs')->where('id', $id);
        if (!$body) {
            $query->select(['id','actor_id','command_name','status','timeout','created_at','started_at',
                'finished_at','exit_code','truncated','summary','executor']);
        }
        $record = $query->first();
        if (!$record) { throw new ServerCommandException('执行记录不存在。'); }
        return $this->decode($record);
    }

    public function history(?int $actorId, int $limit = 20): array
    {
        $query = $this->connection->table('server_command_runs')->orderByDesc('created_at')->orderByDesc('id');
        if ($actorId !== null) { $query->where('actor_id', $actorId); }
        // No command body/output decryption or large output transfer during polling.
        return $query->select(['id','actor_id','command_name','status','timeout','created_at','started_at',
            'finished_at','exit_code','truncated','summary','executor'])->limit(min(50, max(1, $limit)))
            ->get()->map(fn ($row) => $this->decode($row))->all();
    }

    public function owns(string $id): bool
    {
        return $this->connection->table('server_command_slots')->where('id', 1)->value('run_id') === $id;
    }

    public function prune(): int
    {
        $final = ['succeeded', 'failed', 'not_started', 'released'];
        $bodyIds = $this->connection->table('server_command_runs')->whereIn('status', $final)
            ->where('finished_at', '<', now()->subDays(max(1, (int)config('server_commands.body_retention_days', 7))))
            ->whereNotNull('payload')->orderBy('finished_at')->limit(500)->pluck('id');
        $this->connection->table('server_command_runs')->whereIn('id', $bodyIds)->update(['payload' => null, 'output' => null]);
        $ids = $this->connection->table('server_command_runs')->whereIn('status', $final)
            ->where('finished_at', '<', now()->subDays(max(7, (int)config('server_commands.record_retention_days', 90))))
            ->orderBy('finished_at')->limit(500)->pluck('id');
        return $this->connection->table('server_command_runs')->whereIn('id', $ids)->delete();
    }

    private function slot(): object
    {
        return $this->connection->table('server_command_slots')->where('id', 1)->lockForUpdate()->first()
            ?? throw new ServerCommandException('执行占用表未初始化，请先完成迁移。');
    }

    private function encrypt(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function decode(object $record): object
    {
        foreach (['payload', 'output'] as $field) {
            $record->$field = empty($record->$field) ? [] : json_decode(Crypt::decryptString($record->$field), true, 512, JSON_THROW_ON_ERROR);
        }
        $record->executor = empty($record->executor) ? [] : json_decode($record->executor, true, 512, JSON_THROW_ON_ERROR);
        return $record;
    }
}
