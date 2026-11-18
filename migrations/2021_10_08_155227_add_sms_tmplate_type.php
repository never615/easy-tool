<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSmsTmplateType extends Migration
{
    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sms_templates', function (Blueprint $table) {
            $table->unsignedInteger('type')->default(1)->comment('1:群发模板2:卡券模板');
            $table->index('type');
        });
    }

    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
