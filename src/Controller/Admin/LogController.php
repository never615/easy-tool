<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\Log;

/**
 * User: never615 <never615.com>
 * Date: 2019-09-23
 * Time: 15:11
 */
class LogController extends AdminCommonController
{
    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return Log::class;
    }

    protected function getHeaderTitle()
    {
        return "自己上报的日志";
    }


    protected function gridOption(Grid $grid)
    {
        $grid->tag();
        $grid->user_uuid();
        $grid->user_id();
        $grid->data()->limit(100);
    }

    /**
     * 需要实现的form设置
     *
     * 如果需要使用tab,则需要复写defaultFormOption()方法,
     *
     * 需要判断当前环境是edit还是create可以通过$this->currentId是否存在来判断,$this->currentId存在即edit时期.
     *
     * 如果需要分开实现create和edit表单可以通过$this->currentId来区分
     *
     * @param Form $form
     * @return mixed
     */
    protected function formOption(Form $form)
    {
        $form->display("tag");
        $form->display("user_uuid");
        $form->display("user_id");
        $form->display("data");
    }
}