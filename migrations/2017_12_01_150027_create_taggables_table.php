<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tag多对多多态关联表
 *
 * CreateCouponDistributionTicketsTable 类
 */
class CreateTaggablesTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::create('taggables', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger("taggable_id");
            $table->string("taggable_type");

            $table->timestamps();
            $table->softDeletes();

        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('taggables');
    }
}
