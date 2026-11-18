<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 页面轮播图表
 * CreateMemberUpdateRulesTable 类
 */
class UpdatePageBannersTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('page_banners', function (Blueprint $table) {
            $table->dropColumn("page_config_id");
            $table->integer('subject_id')->comment('主体id');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('CASCADE');
        });

        Schema::dropIfExists('page_configs');


    }


    /**7
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::table("page_banners", function (Blueprint $table) {
            $table->dropColumn("subject_id");

            $table->integer('page_config_id');
            $table->foreign('page_config_id')->references('id')->on('page_configs')->onDelete('CASCADE');

        });

        Schema::create('page_configs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('subject_id')->comment('主体id');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('CASCADE');

            $table->softDeletes();
            $table->timestamps();

            $table->index([
                'subject_id',
            ]);
        });


    }
}
