<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBacklogSampleToQueueDiagnosticSnapshotsTable extends Migration
{
    public function up()
    {
        Schema::table('queue_diagnostic_snapshots', function (Blueprint $table) {
            $table->json('backlog_sample')->nullable();
        });
    }

    public function down()
    {
        Schema::table('queue_diagnostic_snapshots', function (Blueprint $table) {
            $table->dropColumn('backlog_sample');
        });
    }
}
