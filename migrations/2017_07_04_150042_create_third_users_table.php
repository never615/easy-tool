<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * 第三方用户,oauth2.0授权使用
 * Class CreateLogsTable
 */
class CreateThirdUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('third_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string("name");
            $table->json("scope")->nullable()->comment("可以使用的令牌作用域");
            $table->string("mobile")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('third_users');
    }
}
