<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\SmsNotify;
use Mallto\Tool\Data\SmsTemplate;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Jobs\BatchSmsJob;

/**
 * todo 废弃优化
 * Class SmsNotifyController
 *
 * @package Mallto\Tool\Controller\Admin
 */
class SmsNotifyController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "短信群发";
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return SmsNotify::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->template()->name("模板名")->display(function ($value) {
            return $value ?? "关联模板已删除";
        });
        $grid->remark();
        $grid->status()->display(function ($value) {
            return SmsNotify::STATUS[$value] ?? $value;
        });


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
        $templates = [];
        if ($this->currentId) {

            $form->displayE("template.name", "模板名")
                ->with(function ($value) {
                    return $value ?? "关联模板已删除";
                });

            $form->displayE("status")->with(function ($value) {
                return SmsNotify::STATUS[$value] ?? $value;
            });

            $form->choice("selects", "范围")
                ->selects([
                    "member_levels" => "会员等级",
                    "users"         => "会员",
                ])->dataUrls([
                    "users"         => data_source_url("users"),
                    "member_levels" => data_source_url("member_levels"),
                ]);
            //$form->displayE("failure_lists","发送失败用户");
        } else {
            $form->select("sms_template_code", "短信模板")
                ->options(SmsTemplate::selectSourceDates())
                ->help('<a target="_blank" href="admin/sms_templates">短信模板管理</a>')
                ->rules("required");
            //创建页面PermissionCreator
            $form->choice("selects", "范围")
                ->selects([
                    "member_levels" => "会员等级",
                    "users"         => "会员",
                ])->dataUrls([
                    "users"         => data_source_url("users"),
                    "member_levels" => data_source_url("member_levels"),
                ]);

        }

        $form->textarea("remark");

        $form->saving(function ($form) use ($templates) {
            if ( ! empty($templates)) {
                $smsTemplateCode = $form->sms_template_code;
                $templates = array_where($templates, function ($value, $key) use ($smsTemplateCode) {
                    return $value["code"] == $smsTemplateCode;
                });

                if (count($templates) > 0) {
                    $template = $templates[0];

                    $form->model()->content = $template["content"];
                    $form->model()->sms_template_name = $template["name"];
                } else {
                    throw new ResourceException("请选择短息模板");
                }
            }
        });

        //array (
        //'choice_users' => '[{"id":"6","type":"member_levels","text":"黑卡"},{"id":"91832","type":"users","text":"云心:18666202809"}]',
        //  'templates' => 'SMS_141195417',
        //  '_token' => 'OUKat9VS8oNniprHVUON3lmMzuW8zhOXUphxS10A',
        //)
        $form->saved(function ($form) {
            if ( ! $this->currentId) {
                dispatch(new BatchSmsJob($form->model()->id))->onQueue("mid");
            }
        });
    }
}
