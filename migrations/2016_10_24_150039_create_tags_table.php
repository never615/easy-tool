<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTagsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            //$table->integer('subject_id')->comment('主体id');
            //$table->foreign('subject_id')->references('id')->on('subjects')->onDelete('CASCADE');
            $table->string('name');
            $table->string('type')->comment('活动:acivity;店铺:shop;专题:special_topic;用户:user');
            $table->double('weight')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index([ 'type', 'weight' ]);
        });
    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tags');
    }
}
