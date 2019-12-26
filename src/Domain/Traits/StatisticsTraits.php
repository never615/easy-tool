<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Traits;

use Illuminate\Support\Carbon;
use Mallto\Tool\Data\ApiPv;
use Mallto\Tool\Exception\ResourceException;

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 06/07/2017
 * Time: 3:45 PM
 */
trait StatisticsTraits
{

    /**
     * 格式化返回开始结束时间
     * 并进行检查
     *
     * @param $startedAt
     * @param $endedAt
     * @param $type
     *
     * @return array
     */
    protected function formatDateAndCheck($startedAt, $endedAt, $type)
    {
        $formatStartedAt = null;
        $formatEndedAt = null;

        switch ($type) {
            case "day":
                $startedCarbon = Carbon::createFromFormat("Y-m-d", $startedAt);
                $endedCarbon = Carbon::createFromFormat("Y-m-d", $endedAt);

                if ($startedCarbon->copy()->addDay(31)->toDateString() < $endedCarbon->toDateString()) {
                    throw new ResourceException("按天查询,间隔不能超过31天");
                }
                $formatStartedAt = $startedCarbon->startOfDay()->toDateTimeString();
                $formatEndedAt = $endedCarbon->endOfDay()->toDateTimeString();
                break;
            case "month":
                $startedCarbon = Carbon::createFromFormat("Y-m", $startedAt);
                $endedCarbon = Carbon::createFromFormat("Y-m", $endedAt);

                $formatStartedAt = $startedCarbon->startOfMonth()->toDateTimeString();
                $formatEndedAt = $endedCarbon->endOfMonth()->toDateTimeString();
                break;
            case "year":
                $startedCarbon = Carbon::createFromFormat("Y", $startedAt);
                $endedCarbon = Carbon::createFromFormat("Y", $endedAt);

                $formatStartedAt = $startedCarbon->startOfYear()->startOfDay()->toDateTimeString();
                $formatEndedAt = $endedCarbon->endOfYear()->toDateTimeString();
                break;
            default:
                throw new ResourceException("无效的查询纬度");
                break;
        }

        return [ $formatStartedAt, $formatEndedAt ];
    }


    /**
     * 补全数据
     *
     * @param $apipvs
     * @param $date_type
     * @param $start_at
     * @param $end_at
     *
     * @return array
     */
    public function addDataIntoApipvs($apipvs, $date_type, $start_at, $end_at)
    {
        $data = [];
        if ($date_type == 'day') {
            $search_date = (strtotime($end_at) - strtotime($start_at)) / 86400;
            if ($search_date > 31) {
                Throw new ResourceException('查询天数不能超过31天');
            }
            $data = $this->getAddApipv($date_type, $apipvs, $start_at, $end_at, 'addDay');
        } else {
            if ($date_type == 'month') {
                $start_at1 = explode("-", $start_at);
                $end_at1 = explode("-", $end_at);
                $search_month = abs($end_at1[0] - $start_at1[0]) * 12 + abs($end_at1[1] - $start_at1[1]);
                if ($search_month > 31) {
                    Throw new ResourceException('查询月份不能超过31个月');
                }
                $data = $this->getAddApipv($date_type, $apipvs, $start_at, $end_at, 'addMonth');
            } else {
                if ($date_type == 'year') {
                    $search_year = $end_at - $start_at;
                    if ($search_year > 31) {
                        Throw new ResourceException('查询年数不能超过31年');
                    }
                    $data = $this->getAddApipv($date_type, $apipvs, $start_at, $end_at, 'addYear');
                }
            }
        }

        return $data;
    }


    private function getAddApipv($date_type, $apipvs, $start_at, $end_at, $method)
    {
        if ($date_type == 'year') {
            $type = "Y";
        } else {
            if ($date_type == 'month') {
                $type = 'Y-m';
            } else {
                $type = 'Y-m-d';
            }
        }
        [ $start_at, $end_at ] = $this->formatDateAndCheck($start_at, $end_at, $date_type);
        for ($date = $start_at; $date <= $end_at; $date = Carbon::createFromFormat($type,
            $date)->$method(1)) {
            $flag = 0;
            $date = date($type, strtotime($date));
            foreach ($apipvs as $apipv) {
                if ($date == date($type, strtotime($apipv->time))) {
                    $data[] = $apipv;
                    $flag = 1;
                    break;
                }
            }
            if ($flag == 0) {
                $apipv = new ApiPv();
                $apipv->time = $date;
                $apipv->ids = null;
                $apipv->count = 0;
                $data[] = $apipv;
            }
        }

        return $data;
    }
}
