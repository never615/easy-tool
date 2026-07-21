<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\ThirdApiLog;

/**
 * 第三方接口请求日志记录
 *
 * Class ThirdLogController
 *
 * @package Mallto\Tool\Controller\Admin
 */
class ThirdLogController extends AdminCommonController
{

    protected $isDisableDelete = true;


    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return '第三方接口通讯日志';
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return ThirdApiLog::class;
    }

    /**
     * 处理「gridOption」相关的内部协作步骤。
     */
    protected function gridOption(Grid $grid)
    {
        $grid->uuid();
        $grid->tag();
        $grid->action();
        $grid->method();
        $grid->status();
        $grid->url()->limit(100);
        $grid->headers()->limit(100);
        $grid->body()->limit(100);
        $grid->request_time();

        $grid->disableCreation();
        $grid->filter(function (Grid\Filter $filter) {
            $filter->useModal();
            $filter->ilike('code');
            $filter->ilike('tag');
            $filter->ilike('content');
        });
    }

    /**
     * 处理「formOption」相关的内部协作步骤。
     */
    protected function formOption(Form $form)
    {
    }
}
