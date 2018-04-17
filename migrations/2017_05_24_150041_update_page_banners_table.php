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
class UpdatePageBannersTable extends Migration
{
    /**
     * Run the migrations.
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
     * Reverse the migrations.
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
