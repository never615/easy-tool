<?php

namespace Mallto\Tool\Controller;


use Encore\Admin\Controllers\Base\AdminCommonController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Data\Log;
use Mallto\Tool\Utils\AppUtils;

/**
 * 第三方接口请求日志记录
 * Class LogController
 *
 * @package Mallto\Mall\Controller
 */
class ThirdLogController extends AdminCommonController
{
    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "第三方接口通讯日志";
    }

    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return Log::class;
    }

    protected function gridOption(Grid $grid)
    {
        $grid->code("通讯对象");
        $grid->tag();
        $grid->content();

        $grid->disableCreation();
        $grid->filter(function (Grid\Filter $filter) {
            $filter->useModal();
            $filter->ilike("code", "对象");
            $filter->ilike("tag");
            $filter->ilike("content");
            $filter->between("created_at");
        });


    }

    protected function formOption(Form $form)
    {
        $form->display("code", "通讯对象");
        $form->display("tag");
        $form->display("content")->with(function ($value) {
            $value=AppUtils::dateTransform($value);

            return json_encode(json_decode($value), JSON_PRETTY_PRINT);
        });
    }

    protected function defaultGridOption(Grid $grid)
    {
        $tableName = $grid->model()->getTable();

        if (!Admin::user()->isOwner()) {
            if (method_exists($this->getModel(), "scopeDynamicData")) {
                $grid->model()->dynamicData();
            }
        }
        $grid->model()->orderBy('id',"desc");

        $grid->disableExport();

        $grid->id('ID')->sortable();


        $this->gridOption($grid);

        if (Schema::hasColumn($tableName, "subject_id")) {
            //拥有子主体的主体,在table中增加该字段
            if (Admin::user()->subject->hasChildrenSubject()) {
                $grid->subject()->name("所属主体");
            }
        }
        $grid->created_at(trans('admin::lang.created_at'));

//        $grid->updated_at(trans('admin::lang.updated_at'))->sortable();

        $grid->filter(function ($filter) {
            // 禁用id查询框
            $filter->disableIdFilter();
        });

        $grid->tools(function (Grid\Tools $tools) {
            $tools->batch(function (Grid\Tools\BatchActions $actions) {
            });
        });
    }

}
