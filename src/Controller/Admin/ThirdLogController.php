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


    protected function formOption(Form $form)
    {
    }

}
