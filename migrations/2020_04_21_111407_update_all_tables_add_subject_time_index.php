<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Illuminate\Database\Migrations\Migration;
use Mallto\Tool\Data\Traits\DatabasesTrait;

class UpdateAllTablesAddSubjectTimeIndex extends Migration
{

    use DatabasesTrait;


    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $indexColumn = [ 'subject_id', 'create_at' ];

        $this->addIndexForAllTable($indexColumn);
    }


    /**
     * Reverse the migrations.
     */
    public function down()
    {
    }
}
