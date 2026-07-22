<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RenameMotuVendorNewConfigDisplayMetadata extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('new_configs')) {
            return;
        }

        DB::table('new_configs')
            ->where('key', 'hytera_debug')
            ->update([
                'name' => '墨兔海 DMR UDP 调试日志',
                'remark' => '墨兔海 DMR UDP 数据收发调试日志。',
                'updated_at' => now(),
            ]);

        DB::table('new_configs')
            ->where('key', 'hytera_dmr_mock_locator_non_error_log')
            ->update([
                'name' => '墨兔海模拟日志开关',
                'remark' => '控制墨兔海模拟定位进程的非错误日志。',
                'updated_at' => now(),
            ]);
    }

    public function down()
    {
    }
}
