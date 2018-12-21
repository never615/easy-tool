<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\Statistics\DataService;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Mallto\Admin\Data\Subject;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\UserUv;
use Mallto\Tool\Exception\ResourceException;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/8/23
 * Time: 下午12:03
 */
class UserStatisticsController extends Controller
{
    /**
     * 用户uv
     *
     * @param Request $request
     * @return array
     */
    public function userUv(Request $request)
    {
        $started = $request->new_started_at;
        $ended = $request->new_ended_at;
        $dateType = $request->new_date_type;


        $subject = $this->getSubject($request);
        $subjectId = $subject->id;

        $results = [];

        //检查日期范围
        switch ($dateType) {
            case 'day':
                $startedCarbon = Carbon::createFromFormat("Y-m-d", $started);
                $endedCarbon = Carbon::createFromFormat("Y-m-d", $ended);

                if ($startedCarbon->copy()->addDay(31)->toDateString() < $endedCarbon->toDateString()) {
                    throw new ResourceException("按天查询,间隔不能超过31天");
                }

                $results = UserUv::where("uuid", $subject->uuid)
                    ->where("type", $dateType)
                    ->where("time", ">=", $startedCarbon->format('Ymd'))
                    ->where("time", "<=", $endedCarbon->format('Ymd'))
                    ->orderBy("time", 'asc')
                    //                    ->where("subject_id", $subjectId)
                    ->select("time", "count as uv_count")
                    ->get();
                break;
            case 'month':
                $startedCarbon = Carbon::createFromFormat("Y-m", $started);
                $endedCarbon = Carbon::createFromFormat("Y-m", $ended);
                if ($startedCarbon->copy()->addMonth(31)->toDateString() < $endedCarbon->toDateString()) {
                    throw new ResourceException("按月查询,间隔不能超过31个月");
                }


                $results = UserUv::where("uuid", $subject->uuid)
                    ->where("type", $dateType)
                    ->where("time", ">=", $startedCarbon->format('Y-m'))
                    ->where("time", "<=", $endedCarbon->format('Y-m'))
                    ->orderBy("time", 'asc')
                    //                    ->where("subject_id", $subjectId)
                    ->select("time", "count as uv_count")
                    ->get();
                break;
            case 'year':
                $startedCarbon = Carbon::createFromFormat("Y", $started);
                $endedCarbon = Carbon::createFromFormat("Y", $ended);

                if ($startedCarbon->copy()->addYear(31)->toDateString() < $endedCarbon->toDateString()) {
                    throw new ResourceException("按年查询,间隔不能超过31年");
                }

                $results = UserUv::where("uuid", $subject->uuid)
                    ->where("type", $dateType)
                    ->where("time", ">=", $startedCarbon->format("Y"))
                    ->where("time", "<=", $endedCarbon->format("Y"))
                    ->orderBy("time", 'asc')
                    //                    ->where("subject_id", $subjectId)
                    ->select("time", "count as uv_count")
                    ->get();

                break;
        }


        return $results;
    }


    private function getSubjectId($request)
    {
        if ($request->subject_uuid) {
            return Subject::where("uuid", $request->subject_uuid)->firstOrFail()->id;
        }

        return SubjectUtils::getSubjectId();
    }

    private function getSubject($request)
    {
        if ($request->subject_uuid) {
            return Subject::where("uuid", $request->subject_uuid)->firstOrFail();
        }

        return SubjectUtils::getSubject();
    }


}