<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * web page pv
 * Class CreatePagePvTable
 */
class CreatePagePvTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('page_pv', function (Blueprint $table) {
            $table->string("uuid");
            $table->string("time");
            $table->string("path");
            $table->bigInteger("count");
            $table->string("date_type");
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop("page_pv");
    }
}
