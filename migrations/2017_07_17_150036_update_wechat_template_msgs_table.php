<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微信模板消息id管理
 * CreateMemberUpdateRulesTable 类
 */
class UpdateWechatTemplateMsgsTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wechat_template_msgs', function (Blueprint $table) {
            $table->string("remark")->nullable()->comment("备注");
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
            $table->dropColumn("remark");
        });
    }
}
