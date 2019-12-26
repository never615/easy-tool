<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\AdminUtils;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\SmsCode;

class SmsCodeController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "短信验证码";
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return SmsCode::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->mobile();
        $grid->code();

        $grid->filter(function (Grid\Filter $filter) {
            $filter->ilike("mobile");
        });

        if ( ! AdminUtils::isOwner()) {
            $grid->disableActions();
        }
        $grid->disableCreateButton();

    }


    /**
     * 需要实现的form设置
     *
     * 如果需要使用tab,则需要复写defaultFormOption()方法,
     * 然后formOption留空即可
     *
     * @param Form $form
     *
     * @return mixed
     */
    protected function formOption(Form $form)
    {
        $form->text("mobile");
        $form->text("code");

    }
}
