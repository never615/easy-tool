<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\SmsTemplate;

/**
 * SmsTemplateController：处理 sms template controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
class SmsTemplateController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "群发短信模板管理";
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return SmsTemplate::class;
    }


    /**
     * 处理「gridOption」相关的内部协作步骤。
     */
    protected function gridOption(Grid $grid)
    {
        $grid->model()->where('type',1);
        $grid->code("模版CODE");
        $grid->name("模版名称");
        $grid->content("模版内容")->limit(50);
        $grid->switch()->switch();
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
        $form->hidden('type')->default(1);
        $form->text("code", "模版CODE")
            ->required()
            ->help('在<a target="_blank" href="https://dysms.console.aliyun.com/dysms.htm#/domestic/text/template">阿里云短信管理</a>创建好群发短信模板后,填写到此处
<br>模板类型需要时推广短信');
        $form->text("name", "模版名称")
            ->required();
        $form->textarea("content", "模版内容")
            ->required();
        $form->switch("switch")
            ->help("是否启用该模板,启用后再短信群发中可以选择使用改模板");
//        $form->textarea("remark");
    }
}
