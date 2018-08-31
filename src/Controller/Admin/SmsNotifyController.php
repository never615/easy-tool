<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Support\Facades\Input;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Jobs\BatchSmsJob;
use Mallto\Tool\Data\SmsNotify;
use Mallto\Tool\Exception\ResourceException;


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
        $grid->sms_template_name("模板名");
        $grid->remark();
        $grid->status()->display(function($value){
            return SmsNotify::STATUS[$value];
        });


    }

    /**
     * 需要实现的form设置
     *
     * 如果需要使用tab,则需要复写defaultFormOption()方法,
     * 然后formOption留空即可
     *
     * @param Form $form
     * @return mixed
     */
    protected function formOption(Form $form)
    {
        $templates = [];
        if ($this->currentId) {
            $form->choice("selects", "范围")
                ->selects([
                    "member_levels" => "会员等级",
                    "users"         => "会员",
                ])->dataUrls([
                    "users"         => data_source_url("users"),
                    "member_levels" => data_source_url("member_levels"),
                ]);
            $form->display("sms_template_name", "模板名");
            $form->display("content", "短信内容");
            $form->display("status")->with(function($value){
                return SmsNotify::STATUS[$value];
            });

            //$form->display("failure_lists","发送失败用户");
        } else {
            //创建页面PermissionCreator
            $form->choice("selects", "范围")
                ->selects([
                    "member_levels" => "会员等级",
                    "users"         => "会员",
                ])->dataUrls([
                    "users"         => data_source_url("users"),
                    "member_levels" => data_source_url("member_levels"),
                ]);

            $config = app(\Mallto\Tool\Domain\Config\Config::class);

            $templates = $config->getConfig("aliyun_sms_template_codes", []);
            $templates = json_decode($templates, true);

            $templates2 = array_map(function ($value) {
                $value["name"] = $value["name"].":".$value["content"];
                unset($value["content"]);

                return $value;
            }, $templates);


            $form->select("sms_template_code", "短信模板")
                ->options(array_pluck($templates2, "name", "code"))
                ->help("模板标题:模板内容")
                ->rules("required");
        }

        $form->textarea("remark");

        $form->saving(function ($form) use ($templates) {
            if (!empty($templates)) {
                $smsTemplateCode = $form->sms_template_code;
                $templates = array_where($templates, function ($value, $key) use ($smsTemplateCode) {
                    return $value["code"] == $smsTemplateCode;
                });


                if (count($templates) > 0) {
                    $template = $templates[0];

                    $form->model()->content = $template["content"];
                    $form->model()->sms_template_name = $template["name"];
                }else{
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
            \Log::info(Input::all());
            if (!$this->currentId) {
                dispatch(new BatchSmsJob($form->model()->id));
            }
        });
    }
}
