<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateQueueDiagnosticSnapshotsTable extends Migration
{
    public function up()
    {
        Schema::create('queue_diagnostic_snapshots', function (Blueprint $table) {
            $table->increments('id');
            $table->string('env')->index();
            $table->string('app_unique')->nullable()->index();
            $table->timestamp('window_started_at')->index();
            $table->integer('window_seconds')->default(60);
            $table->bigInteger('redis_used_memory')->default(0);
            $table->bigInteger('redis_used_memory_peak')->default(0);
            $table->string('redis_mem_fragmentation_ratio')->nullable();
            $table->json('queue_sizes')->nullable();
            $table->json('top_jobs')->nullable();
            $table->json('sources')->nullable();
            $table->json('slow_jobs')->nullable();
            $table->json('large_payload_jobs')->nullable();
            $table->json('failed_jobs')->nullable();
            $table->json('keyspace')->nullable();
            $table->json('scan_patterns')->nullable();
            $table->string('anomaly_level')->nullable()->index();
            $table->json('anomaly_reasons')->nullable();
            $table->timestamps();

            $table->unique([ 'env', 'app_unique', 'window_started_at' ], 'queue_diag_snapshot_window_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('queue_diagnostic_snapshots');
    }
}
