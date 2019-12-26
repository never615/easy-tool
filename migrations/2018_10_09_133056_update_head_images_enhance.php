<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 模块广告/头图模块添加链接
 * 增加广告类型,支持单图/文字/多图广告
 * Class UpdateHeadImageAddLink
 */
class UpdateHeadImagesEnhance extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('head_images', function (Blueprint $table) {
            $table->string("link")->nullable();
            $table->string("ad_type")
                ->default("image")
                ->comment("广告类型,image:单图广告;images:多图广告;text:文字广告");

            $table->text("content")->nullable();
            $table->text("remark")->nullable();
        });

        Schema::table('head_images', function (Blueprint $table) {
            $table->rename("ads");
        });

        Schema::create('ad_images', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger("ad_id");
            $table->string("image");
            $table->text("remark")->nullable();
            $table->string("link")->nullable();
            $table->timestamps();

        });


    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ads', function (Blueprint $table) {
            $table->dropColumn("link");
            $table->dropColumn("ad_type");
            $table->dropColumn("remark");
            $table->dropColumn("content");
        });

        Schema::table('ads', function (Blueprint $table) {
            $table->rename("head_images");
        });

        Schema::drop("ad_images");
    }
}
