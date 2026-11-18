<?php
/**
 * 版权所有 (c) 2019. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateWechatTemplateMsgsAddSwitch extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wechat_template_msgs', function (Blueprint $table) {
            $table->boolean('switch')->default(true)->comment("是否启用该模板消息");
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::table('wechat_template_msgs', function (Blueprint $table) {
            $table->dropColumn("switch");
        });
    }
}
