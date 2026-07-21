<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/11/21
 * Time: 4:07 PM
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
