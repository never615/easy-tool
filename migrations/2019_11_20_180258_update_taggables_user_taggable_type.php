<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateTaggablesUserTaggableType extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \Illuminate\Support\Facades\DB::update("update taggables set taggable_type = 'user' where taggable_type = 'Mallto\User\Data\User'");
        \Illuminate\Support\Facades\DB::update("update taggables set taggable_type = 'user' where taggable_type = 'Mallto\Mall\Data\User'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    }
}
