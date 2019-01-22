<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateSmsNotifiesAddTemplateId extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sms_notifies', function (Blueprint $table) {
            $table->unsignedInteger("sms_template_id")->nullable();
            $table->string("sms_template_name")->nullable()->change();
            $table->string("sms_template_code")->nullable()->change();
            $table->string("content")->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sms_notifies', function (Blueprint $table) {
            $table->dropColumn("sms_template_id");
        });
    }
}
