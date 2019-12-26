<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

class PagePvManager extends BaseModel
{

    protected $table = 'page_pv_manager';

    protected $casts = [
        "ad_types" => "array",
    ];

    public $selectName = "name";

    public $selectId = "path";

}
