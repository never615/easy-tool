<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

class AdImage extends BaseModel
{

    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }

}
