<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * 工具类
 * Created by PhpStorm.
 * User: never615
 * Date: 05/11/2016
 * Time: 4:22 PM
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


    public static function castOnOrOff2Bool($value)
    {
        return $value == "on" ? true : false;
    }

}
