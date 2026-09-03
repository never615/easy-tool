<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修正建表迁移 2024_12_04_165440 里的表名拼写错误。
 *
 * 该表在全仓的引用只有建表迁移写作 alert_ruls，其余全部是 alert_rules：
 *   routes/web.php:169                          Route::resource('alert_rules', ...)
 *   src/Seeder/Menu/AlertRuleMenuSeeder.php:34  'alert_rules.index'
 *   src/Seeder/Permission/...:31                "alert_rules"
 *   src/Data/AlertRule.php                      无 $table，按约定解析为 alert_rules
 *
 * 因此凡是照迁移原样建出 alert_ruls 的环境，报警配置页面都会 500
 * （relation "alert_rules" does not exist）。开发服务器早已手工重命名，
 * 本地库仍是错的 —— 这里把修正固化进迁移，使所有环境收敛。
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('alert_rules')) {
            return;
        }

        if (!$this->tableExists('alert_ruls')) {
            return;
        }

        DB::statement('ALTER TABLE alert_ruls RENAME TO alert_rules');
    }

    /**
     * 刻意留空：本迁移修正的是拼写错误，回滚会让报警配置页面重新 500。
     * 且各环境迁移前的状态并不一致（开发服务器早已是 alert_rules），
     * 对称回滚反而会制造出该环境从未有过的状态。
     */
    public function down(): void
    {
    }

    private function tableExists(string $table): bool
    {
        return (DB::selectOne('SELECT to_regclass(?) AS name', [$table])->name ?? null) !== null;
    }
};
