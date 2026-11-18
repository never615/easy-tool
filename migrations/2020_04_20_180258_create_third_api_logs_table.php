<?php
/**
 * 版权所有 (c) 2020. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateThirdApiLogsTable extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        Schema::create('third_api_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('tag')->nullable();
            $table->string('action')->nullable();
            $table->string('method')->nullable();
            $table->string('url')->nullable();
            $table->text('headers')->nullable();
            $table->text('body')->nullable();
            $table->text('status')->nullable();
            $table->text('request_time')->nullable();
            $table->text('request_id')->nullable();

            $table->timestamps();

            $table->integer('subject_id')->comment('主体id');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('CASCADE');

            $table->index('subject_id');
            $table->index('uuid');
            $table->index('tag');
            $table->index('action');
            $table->index('method');
            $table->index('url');
            $table->index('status');
            $table->index('request_id');
            $table->index('created_at');
        });
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('third_api_logs');
    }
}
