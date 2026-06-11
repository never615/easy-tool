<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPublishColumnsToNewConfigsTable extends Migration
{
    public function up()
    {
        Schema::table('new_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('new_configs', 'env_key')) {
                $table->string('env_key')->nullable()->index()->after('key');
            }

            if (!Schema::hasColumn('new_configs', 'requires_reload')) {
                $table->boolean('requires_reload')->default(true)->after('is_enabled');
            }

            if (!Schema::hasColumn('new_configs', 'last_published_at')) {
                $table->timestamp('last_published_at')->nullable()->after('requires_reload');
            }

            if (!Schema::hasColumn('new_configs', 'last_publish_error')) {
                $table->text('last_publish_error')->nullable()->after('last_published_at');
            }
        });
    }

    public function down()
    {
        Schema::table('new_configs', function (Blueprint $table) {
            if (Schema::hasColumn('new_configs', 'last_publish_error')) {
                $table->dropColumn('last_publish_error');
            }

            if (Schema::hasColumn('new_configs', 'last_published_at')) {
                $table->dropColumn('last_published_at');
            }

            if (Schema::hasColumn('new_configs', 'requires_reload')) {
                $table->dropColumn('requires_reload');
            }

            if (Schema::hasColumn('new_configs', 'env_key')) {
                $table->dropColumn('env_key');
            }
        });
    }
}
