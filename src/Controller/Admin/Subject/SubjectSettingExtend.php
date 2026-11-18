<?php
/**
 * 版权所有 (c) 2021. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Controller\Admin\Subject;

use Encore\Admin\Form;
use Mallto\Admin\Controllers\Base\SubjectSettingExtendInterface;
use Mallto\Tool\SubjectConfigConstants;
use Mallto\Tool\SubjectSettingConstants;

/**
 * 作者： never615 <never615.com>
 * 日期： 2021/3/12
 * 时间： 6:58 下午
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
        // 待办：实现 formSaving() 方法。
    }


    /**
     * @param Form $form
     *
     * @return mixed
     */
    public function formSaved(Form $form)
    {
        // 待办：实现 formSaved() 方法。
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
        // 待办：实现 subjectOwnerConfig() 方法。
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
        // 待办：实现 extend() 方法。
    }
}
