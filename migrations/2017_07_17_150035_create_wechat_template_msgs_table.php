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
class CreateWechatTemplateMsgsTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wechat_template_msgs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('subject_id')->comment('主体id');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('CASCADE');

            $table->string("public_template_id")->nullable()->comment("微信的模板消息id");
            $table->string("template_id")->nullable()->comment("公众号对应的模板消息id");

            $table->softDeletes();
            $table->timestamps();

            $table->index([
                'subject_id',
            ]);

        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wechat_template_msgs');
    }
}
