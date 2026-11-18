<?php
/**
 * 版权所有 (c) 2019. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateLogs extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('logs', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable();
            $table->string('user_uuid')->nullable();
            $table->jsonb("data")->nullable();
            $table->text("remark")->nullable();

            $table->string("code")->nullable()->change();
            $table->text("content")->nullable()->change();
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::table('logs', function (Blueprint $table) {
            $table->dropColumn("user_id");
            $table->dropColumn("user_uuid");
            $table->dropColumn("data");
            $table->dropColumn("remark");
        });
    }
}
