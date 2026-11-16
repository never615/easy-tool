<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('owner_api_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('request_id', 64);
            $table->string('uuid')->nullable();
            $table->string('action', 16);
            $table->string('method', 16);
            $table->text('url');
            $table->text('request_ip')->nullable();
            $table->bigInteger('user_id')->default(0);
            $table->text('header')->nullable();
            $table->text('input')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->timestamps();
            $table->index('request_id');
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_api_logs');
    }
};
