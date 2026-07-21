<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\AdminUtils;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\SmsCode;

/**
 * SmsCodeController：处理 sms code controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
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


    /**
     * 处理「gridOption」相关的内部协作步骤。
     */
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
