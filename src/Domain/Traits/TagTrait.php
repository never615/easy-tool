<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\Traits;

use Mallto\Tool\Data\Tag;

/**
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 06/07/2017
 * 时间： 3:45 下午
 */
trait TagTrait
{

    /**
     * 处理「tags」相关的内部协作步骤。
     */
    public function tags()
    {
//        return $this->morphToMany(Tag::class, "taggable");
        return $this->morphToMany(config('other.database.tags_model'), "taggable");
    }
}
