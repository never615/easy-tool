<?php

namespace Mallto\Tool\Controller;


use Encore\Admin\Controllers\Base\AdminCommonController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Tool\Data\Log;

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
        return "第三方接口日志";
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
        $grid->code();
        $grid->tag();
        $grid->content();
    }

    protected function formOption(Form $form)
    {
        $form->display("code");
        $form->display("tag");
        $form->display("content");
    }
}
