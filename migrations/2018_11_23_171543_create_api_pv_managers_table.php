<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApiPvManagersTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('api_pv_managers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_user_id')->nullable();
            $table->string('path')->comment('页面路径');
            $table->string('name')->comment('起的名字');
            $table->string('slug')->comment('唯一标识');
            $table->boolean('switch');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->integer('subject_id')->comment('主体id');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('CASCADE');
            $table->index([ 'subject_id' ]);
        });
    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('api_pv_managers');
    }
}
