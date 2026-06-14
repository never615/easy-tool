<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigNewConfig;

class ConfigController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return '全局配置';
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return NewConfig::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->model()
            ->where('group_key', NewConfig::GROUP_GLOBAL_CONFIG)
            ->orderBy('sort')
            ->orderBy('id');

        $grid->key('Key')->copyable();
        $grid->name('配置项')->limit(30);
        $grid->value('当前值')->limit(40)->editable(); //limit必须放在 editable 之前
        $grid->remark('说明')->display(function ($value) {
            $text = ConfigController::plainRemarkText($value);
            if ($text === '') {
                return '-';
            }

            return '<span title="' . ConfigController::escape($text) . '">'
                . ConfigController::escape(ConfigController::limitText($text, 40))
                . '</span>';
        });
        $grid->env_key('Env Key')->copyable();
        $grid->last_published_at('发布时间');
        $grid->last_publish_error('发布错误')->limit(40);

        $grid->filter(function ($filter) {
            $filter->ilike('key');
            $filter->ilike('name', '配置项');
            $filter->ilike('env_key', 'Env Key');
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
        $form->text("key", 'Key')->required()
            ->help('保存后会写入 new_configs 的 global_config 分组，并发布为运行期配置快照。');
        $form->text("name", '配置项');
        $form->textarea("remark", '说明')
            ->help('说明按纯文本保存；历史 HTML 标签会在保存时自动清理。');
        $form->textarea("value", '当前值');
        $form->display('env_key', 'Env Key');
        $form->display('last_published_at', '最近发布时间');
        $form->display('last_publish_error', '最近发布错误');

        $form->saving(function (Form $form) {
            $key = (string)$form->key;
            $remark = self::plainRemarkText($form->remark ? (string)$form->remark : null);
            $attributes = GlobalConfigNewConfig::attributesFor(
                $key,
                (string)$form->value,
                $remark !== '' ? $remark : null
            );

            $form->model()->env_key = $attributes['env_key'];
            $form->model()->group_key = $attributes['group_key'];
            $form->model()->type = $attributes['type'];
            $form->model()->default_value = $attributes['default_value'];
            $form->model()->options = $attributes['options'];
            $form->model()->remark = $attributes['remark'];
            $form->model()->is_enabled = $attributes['is_enabled'];
            $form->model()->requires_reload = $attributes['requires_reload'];
            if (!$form->name) {
                $form->model()->name = $attributes['name'];
            }
        });
    }

    private static function plainRemarkText($value): string
    {
        $text = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;

        return trim($text);
    }

    private static function limitText(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit) . '...';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

}
