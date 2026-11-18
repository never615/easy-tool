<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Exception;

/**
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 28/11/2016
 * 时间： 5:20 下午
 */
interface MessageBagErrors
{

    /**
     * 获取错误消息集合。
     *
     * @return \Illuminate\Support\MessageBag
     */
    public function getErrors();


    /**
     * 判断消息集合是否包含错误。
     *
     * @return bool
     */
    public function hasErrors();
}
