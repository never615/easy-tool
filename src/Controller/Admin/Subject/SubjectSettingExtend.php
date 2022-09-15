<?php
/**
 * Copyright (c) 2021. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\Subject;

use Encore\Admin\Form;
use Mallto\Admin\Controllers\Base\SubjectSettingExtendInterface;
use Mallto\Tool\SubjectConfigConstants;
use Mallto\Tool\SubjectSettingConstants;

/**
 * User: never615 <never615.com>
 * Date: 2021/3/12
 * Time: 6:58 下午
 */
class SubjectSettingExtend implements SubjectSettingExtendInterface
{

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


    /**
     * 主体拥有者可以编辑的配置,保存在subject_settings表中的subject_owner_configs列.json
     *
     * 该函数中的代码主体拥有者有权限可以看到
     *
     * 展示在一个tab中
     *
     * @param Form\EmbeddedForm $form
     * @param                   $currentId
     * @param                   $adminUser
     *
     * @return mixed
     */
    public function subjectOwnerConfig(Form\EmbeddedForm $form, $currentId, $adminUser)
    {
        // TODO: Implement subjectOwnerConfig() method.
    }


    /**
     * 公开配置,可以通过接口请求到
     *
     * 该函数中的代码只有项目拥有者有权限可以看到
     *
     * 保存在subject_settings表中的public_configs列.json
     *
     * 展示在一个tab中
     *
     *
     * @param Form\EmbeddedForm $form
     * @param                   $currentId
     * @param                   $adminUser
     *
     * @return mixed
     */
    public function publicConfig(Form\EmbeddedForm $form, $currentId, $adminUser)
    {

    }


    /**
     * 私有配置,只有代码中可以使用
     *
     * 该函数中的代码只有项目拥有者有权限可以看到
     *
     * 保存在subject_settings表中的private_configs列.json
     *
     * 展示在一个tab中
     *
     *
     * @param Form\EmbeddedForm $form
     * @param                   $currentId
     * @param                   $adminUser
     *
     * @return mixed
     */
    public function privateConfig(Form\EmbeddedForm $form, $currentId, $adminUser)
    {
        $form->select(SubjectConfigConstants::SMS_SYSTEM, '短信系统')
            ->options(SubjectSettingConstants::SMS_KEY);
    }


    /**
     * 任意扩展配置
     *
     *
     * @param Form $form
     * @param      $currentId
     * @param      $adminUser
     *
     * @return mixed
     */
    public function extend(Form $form, $currentId, $adminUser)
    {
        // TODO: Implement extend() method.
    }
}
