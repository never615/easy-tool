<?php
/**
 * 版权所有 (c) 2019. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;

class UpdateTaggablesUserTaggableType extends Migration
{

    /**
     * 执行迁移。
     *
     * @return void
     */
    public function up()
    {
        //\Illuminate\Support\Facades\DB::update("update taggables set taggable_type = 'user' where taggable_type = 'Mallto\User\Data\User'");
        //\Illuminate\Support\Facades\DB::update("update taggables set taggable_type = 'user' where taggable_type = 'Mallto\Mall\Data\User'");
    }


    /**
     * 回滚迁移。
     *
     * @return void
     */
    public function down()
    {
    }
}
