<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 前端页面管理,添加页面所支持的广告类型设置
 * Class UpdatePagePvManagerAddAdTypes
 */
class UpdatePagePvManagerAddAdTypes extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('page_pv_manager', function (Blueprint $table) {
            $table->json("ad_types")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('page_pv_manager', function (Blueprint $table) {
            $table->dropColumn("ad_types");
        });
    }
}
