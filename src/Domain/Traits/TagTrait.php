<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Traits;

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 06/07/2017
 * Time: 3:45 PM
 */
trait TagTrait
{

    public function tags()
    {
        return $this->morphToMany(config('other.database.tags_model'), "taggable");
    }
}
