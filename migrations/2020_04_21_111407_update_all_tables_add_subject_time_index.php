<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Mallto\Tool\Data\Traits\DatabasesTrait;

class UpdateAllTablesAddSubjectTimeIndex extends Migration
{

    use DatabasesTrait;


    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        $indexColumn = [ 'subject_id', 'create_at' ];

        $this->addIndexForAllTable($indexColumn);
    }


    /**
     * 回滚迁移。
     */
    public function down()
    {
    }
}
