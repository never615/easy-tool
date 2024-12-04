<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAlertRulsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('alert_ruls', function (Blueprint $table) {
            $table->increments('id');
            $table->string('alert_type')->comment('告警类型');
            $table->string('rule_name')->comment('监控项名称。');
            $table->string('level')->comment('报警级别 Critical（严重）、Warn（警告）或 Info（信息')->nullable();
            $table->string('source')->comment('事件关联告警来源产品')->nullable();
            $table->string('alert_name')->comment('告警名称')->nullable();
            $table->string('asset_name')->comment('告警资产的名称')->nullable();
            $table->unsignedInteger('asset_id')->comment('告警资产的 id')->nullable();
            $table->unsignedInteger('contact_id')->comment('联系人id')->nullable();
            $table->json('email')->comment('告警邮件接收人')->nullable();
            $table->json('mobile')->comment('告警短信接收人')->nullable();
            $table->timestampTz('alert_time');
            $table->text('alert_desc')->comment('告警描述。')->nullable();
            $table->string('alert_name_en')->comment('告警名称 英文')->nullable();
            $table->string('webhook')->comment('报警发生回调时的 URL 地址。')->nullable();
            $table->string('silence_time');
            $table->boolean('enable')->default('false')->comment('是否启用');
            $table->integer('threshold')->comment('触发阈值')->nullable();
            $table->timestamps();

            $table->integer('subject_id')->comment('主体id');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('CASCADE');
            $table->index(['subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('alert_ruls');
    }
}
