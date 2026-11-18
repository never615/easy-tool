<?php
/**
 * 版权所有 (c) 2020. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateAppSecretsAddIsCheckThirdPermission extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('app_secrets', function (Blueprint $table) {
            $table->boolean('is_check_third_permission')->default(false)->comment('是否开启第三方接口权限校验');
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::table('app_secrets', function (Blueprint $table) {
            $table->dropColumn('is_check_third_permission');
        });
    }
}
