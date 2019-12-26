<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微信模板消息id管理
 *
 * 添加自定义模板链接和备注字段
 *
 * Class CreateMemberUpdateRulesTable
 */
class UpdateWechatTemplateMsgsAddDiy extends Migration
{

    /**
     * Run the migrations.
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
     * Reverse the migrations.
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
