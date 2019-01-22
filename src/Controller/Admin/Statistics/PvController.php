<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\Statistics;


use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Mallto\Admin\Data\Subject;

class PvController extends Controller
{
    public function index(Request $request)
    {
        return Admin::content(function (Content $content) use ($request) {
            $content->header('访问统计');

            $user = Admin::user();
            $subject = $user->subject;
            $childrenSubjectIds = $subject->getChildrenSubject();
            //view 中select 选择器需要使用的主体数据
            $subjectSelectData = [];
            if (count($childrenSubjectIds) > 1) {
                $subjectSelectData = Subject::whereIn("id", $childrenSubjectIds)
                    ->whereNotNull('uuid')
                    ->pluck("name", "uuid")
                    ->toArray();
            }

            $this->tips($content, $subjectSelectData);

            $this->uv($content, $subjectSelectData);
            $this->pagePvRank($content, $subjectSelectData);
            $this->pagePvTrend($content, $subjectSelectData);
        });
    }

    /**
     * 提示信息
     *
     * @param $content
     * @param $subjectSelectData
     */
    protected function tips($content, $subjectSelectData)
    {
        $helps = [
            "统计数据截止到上一日",
        ];

        $baseBox = new Box('提示',
            view("tool::dashboard.chart_base")->with([
                "subjects" => $subjectSelectData,
                "helps"    => $helps,
            ]));


        $baseBox->collapsable();
        $baseBox->removable();
        $baseBox->style("info");
        $content->row($baseBox);
    }

    /**
     * uv
     *
     * @param $content
     * @param $subjectSelectData
     */
    protected function uv($content, $subjectSelectData)
    {
        //累计用户
        $box = new Box('活跃用户(uv)',
            view("tool::dashboard.uv")->with([
                "subjects" => $subjectSelectData,
            ]));
        $box->collapsable();
        $box->style("info");
        $content->row($box);
    }


    /**
     * 前端页面访问排名
     *
     * @param $content
     * @param $subjectSelectData
     */
    protected function pagePvRank($content, $subjectSelectData)
    {
        $box = new Box('页面访问排名',
            view("tool::statistics.page_pv_rank")->with([
                "subjects" => $subjectSelectData,
            ]));
        $box->collapsable();
        $box->style("info");
        $content->row($box);
    }


    /**
     * 前端页面访问变化趋势
     *
     * @param $content
     * @param $subjectSelectData
     */
    protected function pagePvTrend($content, $subjectSelectData)
    {
        $box = new Box('页面访问趋势',
            view("tool::statistics.page_pv_trend")->with([
                "subjects" => $subjectSelectData,
            ]));
        $box->collapsable();
        $box->style("info");
        $content->row($box);
    }


}
