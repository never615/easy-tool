<?php
/**
 * Created by PhpStorm.
 * User: never615
 * Date: 5/5/16
 * Time: 3:31 PM
 */

namespace Mallto\Tool\Utils;


use Carbon\Carbon;

class TimeUtils
{

    /**
     * 判断当前时间是否在两个时间之中
     *
     * @param $time1
     * @param $time2
     * @return bool
     */
    public static function inTimes($time1, $time2)
    {
        $now = self::getNowTime();
        if ($now >= $time1 && $now <= $time2) {
            return true;
        } else {
            return false;
        }
    }


    /**
     * 获取当前时间
     *
     * @return bool|string
     */
    public static function getNowTime()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * 获取当前时间
     *
     * @return bool|string
     */
    public static function getNowTime2()
    {
        return date('Y-m-d');
    }

    /**
     * 判断当前时间和oldTime是否在day间隔内,是返回 true
     *
     * @param $intervalDay
     * @param $oldTime
     * @return bool
     */
    public static function inTimeIntervalByDay($intervalDay, $oldTime)
    {
        if (Carbon::now()->subDays($intervalDay) < $oldTime) {
            //表示不到day天
            return true;
        } else {
            return false;
        }
    }

    /**
     * 计算两个日期相隔多少年，多少月，多少天
     *
     * @param $date1 $date1[格式如：2011-11-5]
     * @param $date2 $date2[格式如：2012-12-01]
     * @return mixed
     */
    public static function diffDate($date1, $date2)
    {
//        \Log::info($date1);
//        \Log::info($date2);

        if (strtotime($date1) > strtotime($date2)) {
            $tmp = $date2;
            $date2 = $date1;
            $date1 = $tmp;
        }
        list($Y1, $m1, $d1) = explode('-', $date1);
        list($Y2, $m2, $d2) = explode('-', $date2);
        $Y = $Y2 - $Y1;
        $m = $m2 - $m1;
        $d = $d2 - $d1;
        if ($d < 0) {
            $d += (int) date('t', strtotime("-1 month $date2"));
            $m--;
        }
        if ($m < 0) {
            $m += 12;
            $Y--;
        }
//        echo $Y;
//        return array('year' => $Y, 'month' => $m, 'day' => $d);
        return $Y;
    }


}
