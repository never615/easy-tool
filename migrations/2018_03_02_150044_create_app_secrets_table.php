<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppSecretsTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::create('app_secrets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string("app_id");
            $table->string("app_secret");
            $table->timestamps();
            $table->softDeletes();
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('app_secrets');
    }
}
