<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * alert_time 是该表唯一的 timestamptz（created_at / updated_at 都是 naive timestamp）。
 * 混用导致：管理端回读的是 PostgreSQL 按会话时区渲染的带偏移串，且为此加的
 * date_format 校验补不上 PG 自己输出的短偏移格式（'2026-09-02 10:00:00+08'），
 * 使已有规则无法原样保存。
 *
 * 统一为 app 时区 naive timestamp 后，读写完全不经过 PostgreSQL 会话时区。
 *
 * 必须使用显式 USING ... AT TIME ZONE：隐式转换按执行迁移时的会话时区解释，
 * 在 UTC 会话下会把存量行整体退 8 小时。Laravel 的 ->change() 不支持 USING。
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = $this->resolveTable();
        if ($table === null) {
            return;
        }

        $timezone = $this->applicationTimezone();
        DB::statement(
            "ALTER TABLE {$table} "
            . 'ALTER COLUMN alert_time TYPE timestamp(0) without time zone '
            . "USING (alert_time AT TIME ZONE '{$timezone}')"
        );
    }

    public function down(): void
    {
        $table = $this->resolveTable();
        if ($table === null) {
            return;
        }

        $timezone = $this->applicationTimezone();
        DB::statement(
            "ALTER TABLE {$table} "
            . 'ALTER COLUMN alert_time TYPE timestamptz(0) '
            . "USING (alert_time AT TIME ZONE '{$timezone}')"
        );
    }

    /**
     * 正常迁移顺序下 2026_09_03_000000 已把 alert_ruls 重命名为 alert_rules，
     * 但本迁移被单独用 --path 执行时表名可能仍是旧的，故两种都认。
     */
    private function resolveTable(): ?string
    {
        foreach (['alert_rules', 'alert_ruls'] as $candidate) {
            if ((DB::selectOne('SELECT to_regclass(?) AS name', [$candidate])->name ?? null) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * PostgreSQL 不接受 DDL 语句里的绑定参数（AT TIME ZONE ? 会报
     * "could not determine data type of parameter $1"，加 ::text 也一样），
     * 所以时区必须内插进 SQL。值来自应用配置而非请求输入，再对着 PHP 的时区
     * 标识符白名单校验一次，确保内插内容不可能携带引号。
     */
    private function applicationTimezone(): string
    {
        $timezone = (string)config('app.timezone', 'Asia/Shanghai');

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException(
                "config('app.timezone') 不是合法的时区标识符：{$timezone}"
            );
        }

        return $timezone;
    }
};
