<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;


use Mallto\Admin\Data\Traits\BaseModel;


class Ad extends BaseModel
{

//    const MODULE = [
//        "member_center" => "会员中心",
//        "seckill"       => "秒杀模块",
//        "main_page"     => "主页",
//    ];

//    //单图广告的可选页面模块
//    const IMAGE_MODULE = [
//        "seckill" => "秒杀模块",
//    ];
//
//    //文字广告的可选页面模块
//    const TEXT_MODULE = [
//        "member_center" => "会员中心",
//    ];
//
//    //轮播图广告的可选页面模块
//    const IMAGES_MODULE = [
//        "main_page" => "主页",
//    ];

    //广告类型
    const AD_TYPE = [
        "image"       => "模块头图",
        "text"        => "跑马灯广告",
        "images"      => "多图(轮播图)",
        "float_image" => "浮层广告",
    ];


    public function ad_images()
    {
        return $this->hasMany(AdImage::class);
    }

}
