<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('server_command_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->bigInteger('actor_id')->index();
            $table->uuid('submission_id');
            $table->string('fingerprint', 64);
            $table->string('command_name', 128);
            $table->string('status', 24)->index();
            $table->text('payload')->nullable(); // Authenticated encryption, never a queue payload.
            $table->text('output')->nullable();
            $table->text('executor')->nullable();
            $table->string('summary', 255)->nullable();
            $table->integer('timeout');
            $table->integer('exit_code')->nullable();
            $table->boolean('truncated')->default(false);
            $table->timestamp('created_at')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->bigInteger('released_by')->nullable();
            $table->unique(['actor_id', 'submission_id']);
        });
        Schema::create('server_command_slots', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->uuid('run_id')->nullable();
        });
        DB::table('server_command_slots')->insert(['id' => 1, 'run_id' => null]);
    }

    public function down(): void
    {
        if (DB::table('server_command_slots')->whereNotNull('run_id')->exists()) {
            throw new RuntimeException('服务器命令尚有占用，不能删除执行记录。');
        }
        Schema::dropIfExists('server_command_slots');
        Schema::dropIfExists('server_command_runs');
    }
};
