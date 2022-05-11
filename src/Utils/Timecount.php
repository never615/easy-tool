<?php
/*
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * User: never615 <never615.com>
 * Date: 2022/5/11
 * Time: 4:22 下午
 */
class Timecount
{

    private static $time_start;

    private static $time_end;


    /**
     * 开始时间
     */
    static function start()
    {
        self::$time_start = microtime(true);
    }


    /**
     * 结束计算
     *
     * @return float
     */
    static function end()
    {
        self::$time_end = microtime(true);
        $time = self::$time_end - self::$time_start;

        return $time;
    }


    public static function formatTime($time)
    {
        $time = round($time, 3) . "s";

        return $time;
    }


    /**
     * 打印输出统计时间
     *
     * @param $time
     */
    static function outputTime($time)
    {
        $colorArr = [ "red", "blue", "yellow" ];
        $rand_key = mt_rand(0, count($colorArr) - 1);

        //对浮点数进行四舍五入
        $time = round($time, 3) . "s";

        var_dump("<font color='" . $colorArr[$rand_key] . "'>时间（秒）：</font>" . $time);
        echo '<br />';
    }
}
