<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户每日uv
 * CreateMemberDayUvTable 类
 */
class CreateUserUvTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_uv', function (Blueprint $table) {
            $table->string("uuid");
            $table->string("time");
            $table->bigInteger("count");
            $table->string("type");
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::drop("user_uv");
    }
}
