<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微信模板消息id管理
 *
 * 添加自定义模板链接和备注字段
 *
 * CreateMemberUpdateRulesTable 类
 */
class UpdateWechatTemplateMsgsAddDiy extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wechat_template_msgs', function (Blueprint $table) {
            $table->string("template_remark")->nullable();
            $table->string("template_link")->nullable();
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
            $table->dropColumn("template_remark");
            $table->dropColumn("template_link");
        });
    }
}
