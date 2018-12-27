<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\Statistics;


use App\Http\Controllers\Controller;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\InfoBox;
use Illuminate\Http\Request;
use Mallto\Admin\Data\Subject;
use Mallto\User\Data\User;
use Mallto\User\Data\WechatUserCumulate;

class DashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        return Admin::content(function (Content $content) use ($request) {
            $content->header('Dashboard');
            $content->description(" ");

            $user = Admin::user();
            if (!$user->can("dashboard")) {
                return;
            }

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

            $this->topInfoBox($content, $childrenSubjectIds);
            $this->tips($content, $subjectSelectData);


            $this->uv($content, $subjectSelectData);
            $this->newUser($content, $subjectSelectData);
            $this->cumulateUser($content, $subjectSelectData);
        });
    }

    /**
     * 顶部的info box
     *
     * @param $content
     * @param $subSubjectIds
     */
    private function topInfoBox($content, $subSubjectIds)
    {
        $content->row(function ($row) use ($subSubjectIds) {

            //累计用户数
            $userCount = User::whereIn("subject_id", $subSubjectIds)->count();

            $wechatCumulateUser = WechatUserCumulate::where("subject_id", Admin::user()->subject->id)
                ->orderBy('ref_date', 'desc')
                ->first();
            $wechatCount = 0;
            if ($wechatCumulateUser) {
                $wechatCount = $wechatCumulateUser->cumulate_user;
            }


            $row->column(3, new InfoBox('微信订阅用户', 'users', 'olive', 'admin/users',
                $wechatCount));
            $row->column(3, new InfoBox('微信系统累计用户', 'users', 'yellow', 'admin/users',
                $userCount));


            if ($wechatCount > 0) {
                $row->column(3, new InfoBox('微信订阅用户转化率', 'arrow-right', 'gray', 'admin/users',
                    number_format(($userCount / $wechatCount * 100), 2)."%"));
            } else {
                $row->column(3, new InfoBox('微信订阅用户转化率', 'arrow-right', 'gray', 'admin/users',
                    0));
            }
        });
    }


    /**
     * 提示信息
     *
     * @param $content
     * @param $subjectSelectData
     */
    private function tips($content, $subjectSelectData)
    {
        $helps = [
            "统计数据截止到上一日",
            "微信订阅用户:关注了公众号的用户",
            "微信系统累计用户:在微信平台使用过本系统服务的用户",
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
    private function uv($content, $subjectSelectData)
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
     * 累计用户
     *
     * @param $content
     * @param $subjectSelectData
     */
    private function cumulateUser($content, $subjectSelectData)
    {
        //累计用户
        $cumulateBox = new Box('累计用户',
            view("tool::dashboard.user_cumulate")->with([
                "subjects" => $subjectSelectData,
            ]));
        $cumulateBox->collapsable();
        $cumulateBox->style("info");
        $content->row($cumulateBox);
    }


    /**
     * 新增用户
     *
     * @param $content
     * @param $subjectSelectData
     */
    private function newUser($content, $subjectSelectData)
    {
        $newUserBox = new Box('新增用户',
            view("tool::dashboard.user_new")->with([
                "subjects" => $subjectSelectData,
            ]));
        $newUserBox->collapsable();
        $newUserBox->style("info");
        $content->row($newUserBox);
    }


}
