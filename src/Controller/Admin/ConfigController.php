<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Support\Facades\Cache;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\Config;
use Mallto\Tool\Domain\App\ClearCacheUsecase;

class ConfigController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "configs";
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return Config::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->key();
        $grid->remark()->limit(20);
        $grid->value()->limit(20);;
        $grid->type();


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
        $form->text("key");
        $form->text("remark");
        $form->textarea("value");
        $form->text("type");

        $form->saved(function (Form $form) {
            $key = $form->key;
            $clearCache=app(ClearCacheUsecase::class);
            $clearCache->clearCache();
        });
    }

}
