<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHeadImagesTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::create('head_images', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('subject_id')->comment('主体id');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('CASCADE');

            $table->string("type")->nullable()->comment("类型,如:seckill");
            $table->string("image")->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index([
                'subject_id',
            ]);

        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('head_images');
    }
}
