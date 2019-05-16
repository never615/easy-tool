<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;


use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\AdminUtils;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Controller\Admin\Traits\GetAdTypes;
use Mallto\Tool\Data\Ad;
use Mallto\Tool\Data\PagePvManager;
use Mallto\Tool\Exception\ResourceException;


class AdController extends AdminCommonController
{
    use GetAdTypes;

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "页面广告";
    }

    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return Ad::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->ad_type("广告类型")->display(function ($value) {
            return Ad::AD_TYPE[$value] ?? $value;
        });

        $grid->type("使用模块")
            ->display(function ($value) {
                $page = PagePvManager::where("path", $value)->first();

                if (AdminUtils::isOwner()) {
                    return $page ? $page->name.":".$page->path : $value;
                } else {
                    return $page ? $page->name : $value;
                }

            });

        $grid->switch()->switchE();
    }


    protected function formOption(Form $form)
    {
        $this->dynamicDisplay();

        $form->select("type", "模块")
            ->options(PagePvManager::selectSourceDatas())
            ->addElementClass2("mt-ad-type")
            ->rules("required")
            ->help("如果一个模块同时配置了同一类型多个开启状态下广告,则会使用最新创建的")
            ->load("ad_type", "/admin/select_source/ad_types");

        $form->select("ad_type", "广告类型")
            ->addElementClass2("mt-ad-ad-type")
            ->default("text")
            ->rules("required")
            ->help("浮层广告建议尺寸:420 * 520")
            ->options(Ad::AD_TYPE);

        $form->switch("switch");

        $this->imageFormOption($form);
        $this->textFormOption($form);
        $this->imagesFormOption($form);


        $form->text("link", "跳转链接")
            ->addElementClass2("mt-ad-link")
            ->help("如:https://baidu.com");

        $form->saving(function ($form) {
            //检查模块与对应的广告类型是否匹配
            $type = $form->type ?? $form->model()->type;
            $adType = $form->ad_type ?? $form->model()->ad_type;
            $subjectId = $form->subject_id ?? $form->model()->subject_id;

            $page = PagePvManager::where("subject_id", $subjectId)
                ->where("path", $type)
                ->first();

            if (!in_array($adType, $page->ad_types)) {
                throw new ResourceException("该模块不支持设置该广告类型");
            }
        });
    }


    /**
     * 单图广告
     *
     * @param $form
     */
    private function imageFormOption($form)
    {
        $form->image('image')
            ->removable()
            ->uniqueName()
            ->addElementClass2("mt-ad-image")
            ->move('ads/image'.$this->currentId ?: 0);
    }

    /**
     * 文字广告
     *
     * @param $form
     */
    private function textFormOption($form)
    {
        $form->text("content")
            ->addElementClass2("mt-ad-text");
    }

    /**
     * 多图广告
     *
     * @param $form
     */
    private function imagesFormOption($form)
    {
        $form->hasMany("ad_images", "多图广告", function ($form) {
            $form->image("image")
                ->removable()
                ->uniqueName()
                ->addElementClass2("mt-ad-images")
                ->move('ads/images'.$this->currentId ?: 0);

            $form->text("link", "跳转链接")
                ->addElementClass2("mt-ad-images")
                ->help("如:https://baidu.com");
        })->addElementClass2("mt-ad-images");
    }


    /**
     * 根据商品类型动态切换页面展示
     */
    private function dynamicDisplay()
    {
        $defaultScript = <<<EOT
    $(document).ready(function () {   
        var type=$(".mt-ad-ad-type").val();
        typeValueUpdate(type);

     
        $(document).on('change', ".mt-ad-ad-type", function () {
            var selectVal = $(this).val();
            console.log(selectVal);
            typeValueUpdate(selectVal);
           
        });
        
         function typeValueUpdate(selectVal){
             console.log(selectVal);
              switch (selectVal) {
                case 'image':
                case 'float_image':
                    $(".mt-ad-text").closest('.form-group').hide();
                    $(".mt-ad-images").closest('.form-has-many').hide();
                    $(".mt-ad-image").closest('.form-group').show();
                    $(".mt-ad-link").closest('.form-group').show();
                    break;
                case 'text':
                    $(".mt-ad-image").closest('.form-group').hide();
                    $(".mt-ad-images").closest('.form-has-many').hide();
                    $(".mt-ad-text").closest('.form-group').show();
                    $(".mt-ad-link").closest('.form-group').show();
                    break;
                case 'images':
                    $(".mt-ad-text").closest('.form-group').hide();
                    $(".mt-ad-image").closest('.form-group').hide();
                    $(".mt-ad-link").closest('.form-group').hide();
                    $(".mt-ad-images").closest('.form-has-many').show();
                    break;
            }
         }
    });    
EOT;


        Admin::script($defaultScript);
    }


}
