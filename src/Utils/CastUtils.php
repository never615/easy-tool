<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Utils;

/**
 * 工具类
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 05/11/2016
 * 时间： 4:22 下午
 */
class CastUtils
{

    /**
     * 转化布尔值为是否
     */
    public static function castBool2YesOrNo($value)
    {
        return $value == true ? "是" : "否";
    }


    /**
     * 转换是否为布尔值
     *
     * @param $value
     *
     * @return bool
     */
    public static function castYesOrNo2Bool($value)
    {
        return $value == "是" ? true : false;
    }


    /**
     * 处理「castOnOrOff2Bool」相关的内部协作步骤。
     */
    public static function castOnOrOff2Bool($value)
    {
        return $value == "on" ? true : false;
    }

}
