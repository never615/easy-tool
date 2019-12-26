<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 页面轮播图表
 * Class CreateMemberUpdateRulesTable
 */
class CreatePageBannersTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('page_banners', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('page_config_id');
            $table->foreign('page_config_id')->references('id')->on('page_configs')->onDelete('CASCADE');

            $table->text("image")->nullable();
            $table->text("link")->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index([
                'page_config_id',
            ]);
        });

        Schema::table("page_configs", function (Blueprint $table) {
            $table->dropColumn("banner");
        });

    }


    /**7
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('page_banners');
        Schema::table("page_configs", function (Blueprint $table) {
            $table->json("banner")->nullable()->comment("轮播图配置");
        });
    }
}
