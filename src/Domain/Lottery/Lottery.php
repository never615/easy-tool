<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Lottery;

use Mallto\Tool\Exception\InternalHttpException;

/**
 * 抽奖
 * Created by PhpStorm.
 * User: never615
 * Date: 06/05/2017
 * Time: 4:19 PM
 */
class Lottery
{

    /**
     * 抽奖
     *
     * 输入一组id和它的权重,返回一个id
     *
     * @param        $lotteries
     * @param string $weightColumn
     * @param string $idColumn
     * @return mixed
     */
    public function run($lotteries, $weightColumn = "weight", $idColumn = "id")
    {

        $length = count($lotteries);
        if ($length < 1) {
            return false;
        }

        if ($length == 1) {
            return $lotteries[0][$idColumn];
        }
        //1. 累加权重,分配区间
        $sumWeight = 0;
        foreach ($lotteries as $key => $lottery) {
            //区间包含头尾
            $lotteries[$key]["interval"] = [$sumWeight + 1, $sumWeight + $lottery[$weightColumn]];
            $sumWeight += $lottery[$weightColumn];
        }
        //2. 在权重和中随机一个数字,判断落在那个区间
        if ($sumWeight > 0) {
            $lotteryResult = mt_rand(1, $sumWeight);


            //3. 找到该区间对应的对象,返回
            foreach ($lotteries as $key => $lottery) {
                if ($lotteryResult >= $lottery["interval"][0] && $lotteryResult <= $lottery["interval"][1]) {
                    return $lottery[$idColumn];
                }
            }
            throw new InternalHttpException("抽奖失败");
        }

        $number = mt_rand(0, $length - 1);

        return $lotteries[$number][$idColumn];
    }
}
