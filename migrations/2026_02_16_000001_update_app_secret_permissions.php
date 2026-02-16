<?php
/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('app_secrets_permissions', function (Blueprint $table) {
            $table->text("describe")->nullable();
            $table->boolean("common")->default(false)->comment("是否是所有主体都拥有的权限,必须设置到权限组上");
            $table->unsignedInteger('parent_id')->default(0);
            $table->string("path")->nullable();

            $table->integer("order")->default(0);

            $table->unsignedInteger("admin_user_id")->nullable();
            $table->foreign('admin_user_id')->references('id')->on('admin_users');
        });

    }

    public function down(): void
    {
    }
};
