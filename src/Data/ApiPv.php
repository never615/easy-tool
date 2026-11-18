<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2018/11/21
 * 时间： 4:07 下午
 */
class ApiPv extends BaseModel
{

    protected $table = "api_pv";

    public $selectName = "path";

    public $selectId = "path";


    /**
     * 处理「apiPvManager」相关的内部协作步骤。
     */
    public function apiPvManager()
    {
        return $this->belongsTo(ApiPvManager::class, "path", "path");
    }

}
