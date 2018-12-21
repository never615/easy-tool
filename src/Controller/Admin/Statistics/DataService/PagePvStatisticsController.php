<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\Statistics\DataService;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mallto\Admin\Data\Subject;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\PagePv;
use Mallto\Tool\Data\PagePvManager;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/11/26
 * Time: 下午12:03
 */
class PagePvStatisticsController extends Controller
{


    /**
     * 返回主体下开放的page pv项
     *
     * @param Request $request
     * @return mixed
     */
    public function pagePaths(Request $request)
    {
        $subject = $this->getSubject($request);
        $subjectId = $subject->id;


        return PagePvManager::where("subject_id", $subjectId)
            ->where("switch", true)
            ->orderBy("weight")
            ->pluck("name", "path");
    }

    /**
     * 页面pv 排名数据
     *
     * @param Request $request
     * @return
     */
    public function pagePvRank(Request $request)
    {
        $date = $request->date;
        $dateType = $request->date_type;


        $this->validate($request, [
            "date"      => "required",
            "date_type" => "required",
        ]);

        $subject = $this->getSubject($request);
        $subjectId = $subject->id;

        return PagePv::select("page_pv_manager.name", "page_pv.count as pv_count")
            ->join("page_pv_manager", "page_pv_manager.path", "page_pv.path")
            ->where("page_pv_manager.switch", true)
            ->where("page_pv.uuid", $subject->uuid)
            ->where("page_pv.date_type", $dateType)
            ->where("page_pv.time", $date)
            ->orderBy("page_pv.count")
            ->get();
    }


    /**
     * 页面pv 趋势
     *
     * @param Request $request
     * @return
     */
    public function pagePvTrend(Request $request)
    {
        $dateType = $request->date_type;
        $startedAt = $request->started_at;
        $endedAt = $request->ended_at;

        $path = $request->path;

        $this->validate($request, [
            "date_type"  => "required",
            "started_at" => "required",
            "ended_at"   => "required",
        ]);

        $subject = $this->getSubject($request);
        $subjectId = $subject->id;

        return PagePv::select("time", "count as pv_count")
            ->where("uuid", $subject->uuid)
            ->where("date_type", $dateType)
            ->where("time", ">=", $startedAt)
            ->where("time", "<=", $endedAt)
            ->where("path", $path)
            ->orderBy("time")
            ->get();
    }


    private function getSubject($request)
    {
        if ($request->subject_uuid) {
            return Subject::where("uuid", $request->subject_uuid)->firstOrFail();
        }

        return SubjectUtils::getSubject();
    }


}