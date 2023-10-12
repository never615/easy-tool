<?php
/**
 * Copyright (c) 2020. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\Subject;

use Encore\Admin\Form;
use Encore\Admin\Form\EmbeddedForm;
use Mallto\Admin\Controllers\Base\SubjectConfigExtendInterface;
use Mallto\Tool\SubjectConfigConstants;

/**
 * User: never615 <never615.com>
 * Date: 2020/7/22
 * Time: 7:03 下午
 */
class SubjectConfigExtend implements SubjectConfigExtendInterface
{

    protected $subjectConfigExpandObjs = [];


    /**
     * 主体管理基本信息扩展
     *
     * 位于主体管理第一个tab中
     *
     * 使用示例:
     *
     * $form->text('address');
     * $form->embeds('tel', '客服电话', function ($form) {
     * $form->text('tel1', '电话1');
     * $form->text('tel2', '电话2');
     * });
     * $form->ueditor('detail', '关于');
     *
     * @param Form $form
     *
     * @return mixed
     */
    public function basicInfoExtend(Form $form, $currentId)
    {
        // TODO: Implement basicInfoExtend() method.
    }


    /**
     * 主体拥有者可以编辑的(主体拥有者如:商场的拥有者/管理员)
     *
     * 动态属性列扩展的形式展现管理的数据,保存在open_extra_config中(json)
     *
     * 使用:实现该方法直接调用如下即可.
     *
     * $form->switch(SubjectConfigConstants::SUBJECT_OWNER_CONFIG_COUPON_EXPIRED_NOTIFY_SWITCH,
     * '卡券到期提醒');
     *
     * $form->text(SubjectConfigConstants::SUBJECT_OWNER_CONFIG_COUPON_EXPIRED_NOTIFY,
     * '卡券到期前第几天提醒');
     *
     * @param EmbeddedForm $form
     *
     * @return mixed
     */
    public function subjectOwnerExtraConfigByJson(EmbeddedForm $form, $currentId)
    {
        // TODO: Implement subjectOwnerExtraConfigByJson() method.
    }


    /**
     * 主体拥有者可以扩展的配置
     *
     * 可以在其中进行任意扩展,如新增tab
     *
     * @param Form $form
     *
     * @param int  $currentId 当前修改的主体id
     *
     * @return mixed
     */
    public function subjectOwnerExtend(Form $form, $currentId)
    {
        // TODO: Implement subjectOwnerExtend() method.
    }


    /**
     * 项目拥有者可以配置的
     *
     * 必须创建对应的字段的subjects表中
     *
     * 使用:
     * $form->select('park_system', '停车系统')
     * ->options(ParkOperate::PARK_REALIZE);
     *
     * @param Form $form
     *
     * @return mixed
     */
    public function projectOwnerConfig(Form $form, $currentId)
    {
        // TODO: Implement projectOwnerConfig() method.
        foreach ($this->subjectConfigExpandObjs as $subjectConfigExpandObj) {
            $subjectConfigExpandObj->projectOwnerConfig($form);
        }
        $form->embeds('extra_config', '阿里云短信配置', function (EmbeddedForm $form) {
            $form->text(SubjectConfigConstants::OWNER_CONFIG_SMS_SIGN, '短信签名');

            $form->text(SubjectConfigConstants::OWNER_CONFIG_SMS_TEMPLATE_CODE, '短信验证码模板号');

            foreach ($this->subjectConfigExpandObjs as $subjectConfigExpandObj) {
                $subjectConfigExpandObj->projectOwnerExtraConfigByJson($form);
            }
        });

    }


    /**
     * 项目拥有者可以配置的
     *
     * 保存在 extra_config 中,json格式
     *
     * 使用示例:
     * $form->text(SubjectConfigConstants::OWNER_CONFIG_ADMIN_WECHAT_UUID, '管理端微信服务uuid')
     * ->help('用于微信开放平台授权,获取指定uuid对应的服务号下微信用户的openid,</br>
     * 有的项目管理端单独使用一个公众号,所以需要配置单独的uuid');
     *
     * $form->text(SubjectConfigConstants::OWNER_CONFIG_SMS_SIGN, '短信签名');
     *
     * @param EmbeddedForm $form
     *
     * @return mixed
     */
    public function projectOwnerExtraConfigByJson(EmbeddedForm $form, $currentId)
    {

    }


    /**
     * @param Form $form
     *
     * @param      $adminUser
     *
     * @return mixed
     */
    public function formSaving(Form $form, $adminUser)
    {
        // TODO: Implement formSaving() method.
    }


    /**
     * @param Form $form
     *
     * @return mixed
     */
    public function formSaved(Form $form)
    {
        // TODO: Implement formSaved() method.
    }
}
