<?php
/**
 * Copyright (c) 2020. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AppSecretsRoleAndPermissionsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        //开发者权限表
        Schema::create('app_secrets_permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 50)->unique()->comment('权限名');
            $table->string('slug', 50)->unique()->comment('权限标识');
            $table->timestamps();
        });

        //开发者角色表
        Schema::create('app_secrets_roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 50)->unique()->comment('角色名');
            $table->string('slug', 50)->unique()->comment('角色标识');
            $table->timestamps();
        });

        //开发者角色权限关联表
        Schema::create('app_secrets_role_has_permissions', function (Blueprint $table) {
            $table->integer('role_id');
            $table->integer('permission_id');
            $table->index([ 'role_id', 'permission_id' ]);
            $table->timestamps();
        });

        //开发者角色关联表
        Schema::create('app_secrets_has_roles', function (Blueprint $table) {
            $table->integer('app_secret_id');
            $table->integer('role_id');
            $table->index([ 'app_secret_id', 'role_id' ]);
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('app_secrets_permissions');
        Schema::dropIfExists('app_secrets_roles');
        Schema::dropIfExists('app_secrets_role_has_permissions');
        Schema::dropIfExists('app_secrets_has_roles');
    }
}
