<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 网页访问量
 * CreatePagePvTable 类
 */
class CreatePagePvTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::create('page_pv', function (Blueprint $table) {
            $table->string("uuid");
            $table->string("time");
            $table->string("path");
            $table->bigInteger("count");
            $table->string("date_type");
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::drop("page_pv");
    }
}
