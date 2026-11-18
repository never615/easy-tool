<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTagsTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            //$table->integer('subject_id')->comment('主体id');
            //$table->foreign('subject_id')->references('id')->on('subjects')->onDelete('CASCADE');
            $table->string('name');
            $table->string('type')->comment('活动:acivity;店铺:shop;专题:special_topic;用户:user');
            $table->double('weight')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index([ 'type', 'weight' ]);
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tags');
    }
}
