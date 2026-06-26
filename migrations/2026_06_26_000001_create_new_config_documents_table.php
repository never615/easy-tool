<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNewConfigDocumentsTable extends Migration
{
    public function up()
    {
        Schema::create('new_config_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('content_type')->default('markdown');
            $table->longText('content');
            $table->text('remark')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('new_config_documents');
    }
}
