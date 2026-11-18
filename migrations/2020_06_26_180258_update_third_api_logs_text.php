<?php
/**
 * 版权所有 (c) 2020. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateThirdApiLogsText extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('third_api_logs', function (Blueprint $table) {
            $table->text('url')->change();
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
    }
}
