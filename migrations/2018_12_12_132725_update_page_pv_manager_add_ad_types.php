<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 前端页面管理,添加页面所支持的广告类型设置
 * UpdatePagePvManagerAddAdTypes 类
 */
class UpdatePagePvManagerAddAdTypes extends Migration
{

    /**
     * 执行迁移。
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
     * 回滚迁移。
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
