<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Domain\NewConfig\GlobalConfigOverrideSource;

class AddOverrideSourceToNewConfigsTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('new_configs', 'override_source')) {
            Schema::table('new_configs', function (Blueprint $table): void {
                $table->string('override_source', 32)->nullable()->index()->after('is_enabled');
            });
        }

        DB::table('new_configs')
            ->where('group_key', 'global_config')
            ->where('key', 'app_secret')
            ->whereNotNull('value')
            ->where('value', '<>', '')
            ->update(['override_source' => GlobalConfigOverrideSource::SYSTEM]);

        if (!Schema::hasTable('configs')) {
            return;
        }

        DB::table('configs')
            ->select(['id', 'key', 'value'])
            ->orderBy('id')
            ->chunkById(100, function ($configs): void {
                foreach ($configs as $config) {
                    DB::table('new_configs')
                        ->where('group_key', 'global_config')
                        ->where('key', (string)$config->key)
                        ->where('value', $config->value)
                        ->whereNull('override_source')
                        ->update(['override_source' => GlobalConfigOverrideSource::LEGACY_CONFIGS]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('new_configs', 'override_source')) {
            return;
        }

        Schema::table('new_configs', function (Blueprint $table): void {
            $table->dropIndex(['override_source']);
            $table->dropColumn('override_source');
        });
    }
}
