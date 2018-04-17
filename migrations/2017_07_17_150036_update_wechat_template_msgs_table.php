<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微信模板消息id管理
 * Class CreateMemberUpdateRulesTable
 */
class UpdateWechatTemplateMsgsTable extends Migration
{
    /**
     * Run the migrations.
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
     * Reverse the migrations.
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
