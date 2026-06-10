<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNewConfigsTable extends Migration
{
    public function up()
    {
        Schema::create('new_configs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('group_key')->nullable()->index();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('type')->default('string');
            $table->text('value')->nullable();
            $table->text('default_value')->nullable();
            $table->text('options')->nullable();
            $table->text('remark')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('new_configs');
    }
}
