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
class UpdatePageBanners1Table extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('page_banners', function (Blueprint $table) {
            $table->double("weight")->default(0);
        });
    }


    /**7
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table("page_banners", function (Blueprint $table) {
            $table->dropColumn("weight");
        });
    }
}
