<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePagePvManagerTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('page_pv_manager', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_user_id')->nullable();
            $table->string('path');
            $table->string('name');
            $table->string('slug');
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
        Schema::dropIfExists('page_pv_manager');
    }
}
