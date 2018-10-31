<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;


use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\Ad;


class AdController extends AdminCommonController
{

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
        $grid->ad_type("广告类型")->select(Ad::AD_TYPE);
        $grid->type("使用模块")->select(Ad::MODULE);
    }


    protected function formOption(Form $form)
    {
        $this->dynamicDisplay();

        $form->select("ad_type", "广告类型")
            ->default("image")
            ->addElementClass2("mt-ad-ad-type")
            ->options(Ad::AD_TYPE);

        $form->select("type", "模块")
            ->options(Ad::MODULE)
            ->rules("required")
            ->addElementClass2("mt-ad-type");

        $this->imageFormOption($form);
        $this->textFormOption($form);
        $this->imagesFormOption($form);


        $form->text("link", "跳转链接")
            ->addElementClass2("mt-ad-link")
            ->help("如:https://baidu.com");
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
