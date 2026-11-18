<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateFeedbacksTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->json('images')->nullable();
            $table->string('mobile')->nullable();
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->dropColumn("images");
            $table->dropColumn("mobile");
        });
    }
}
