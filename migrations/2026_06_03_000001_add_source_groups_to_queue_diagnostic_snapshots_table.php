<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSourceGroupsToQueueDiagnosticSnapshotsTable extends Migration
{
    public function up()
    {
        Schema::table('queue_diagnostic_snapshots', function (Blueprint $table) {
            $table->json('source_groups')->nullable();
        });
    }

    public function down()
    {
        Schema::table('queue_diagnostic_snapshots', function (Blueprint $table) {
            $table->dropColumn('source_groups');
        });
    }
}
