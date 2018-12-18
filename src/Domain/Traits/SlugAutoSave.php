<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Traits;

use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Utils\AppUtils;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/10/26
 * Time: 下午4:37
 */
trait SlugAutoSave
{
    /**
     * 自动生成slug
     *
     * @param        $form
     * @param        $modelClass
     * @param string $slugColumn
     * @param null   $anotherWhereColumn
     * @param null   $antherWhereValue
     */
    protected function slugSavingCheck(
        $form,
        $modelClass = null,
        $slugColumn = "slug",
        $anotherWhereColumn = null,
        $antherWhereValue = null
    ) {
        if (!$modelClass) {
            $modelClass = $this->getModel();
        }

        $subjectId = $form->subject_id ?? $form->model()->subject_id;

        //如果主动修改或提交了标识需要保存
        if ($form->slug) {
            if ($form->slug != $form->model()->slug) {
                //检查slug是否已经存在
                if ($modelClass::where("subject_id", $subjectId)
                    ->where($slugColumn, $form->slug)->exists()) {
                    throw new ResourceException("该标识已经存在:".$form->slug);
                }
            }
        } else {
            if ($form->name && $form->name != $form->model()->name) {
                //检查name,一个subject下不能重复

                $query = $modelClass::where("subject_id", $subjectId)
                    ->where("name", $form->name);

                if ($anotherWhereColumn && $antherWhereValue) {
                    $query = $query->where($anotherWhereColumn, $antherWhereValue);
                }

                if ($query->exists()) {
                    throw new ResourceException($form->name." 已存在,请更换名称");
                }


                //处理slug
                //自动生成slug,同一个主体下不能重复
                $slug = pinyin_permalink($form->name);
                $slug = $this->generatorSlug($slug, $subjectId, $modelClass);
                if (!$form->slug) {
                    $form->slug = $slug;
                }
                $form->model()->$slugColumn = $slug;
            }
        }


    }


    /**
     * 检查是否有重复的slug
     *
     * @param        $name
     * @param        $subjectId
     * @param        $modelClass
     * @param string $slugColumn
     * @param null   $anotherWhereColumn
     * @param null   $antherWhereValue
     * @return string
     */
    private function generatorSlug(
        $name,
        $subjectId,
        $modelClass = null,
        $slugColumn = "slug",
        $anotherWhereColumn = null,
        $antherWhereValue = null
    ) {

        $slug = pinyin_permalink($name);


        $query = $modelClass::where("subject_id", $subjectId)
            ->where($slugColumn, $slug);

        if ($anotherWhereColumn && $antherWhereValue) {
            $query = $query->where($anotherWhereColumn, $antherWhereValue);
        }

        if ($query->exists()) {
            $slug = $slug."_".AppUtils::getRandomString(3);

            return $this->generatorSlug($slug, $subjectId, $modelClass);
        } else {
            return $slug;
        }
    }

}