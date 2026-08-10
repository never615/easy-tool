<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigNewConfig;

/**
 * ConfigController：历史 `/admin/configs` 路由的兼容控制器。
 *
 * 路由名保留，但页面实际读写的是 NewConfig 的 `global_config` 分组，
 * 不会查询或写入旧 `configs` 表。
 */
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


    /**
     * 处理「gridOption」相关的内部协作步骤。
     */
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

        $grid->tools(function ($tools) {
            $tools->append($this->usageNotice());
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
            ->help('历史路由名为 configs；保存实际写入 new_configs 的 global_config 分组，不会写入旧 configs 表，并通过运行缓存生效。');
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

    /**
     * 处理「plainRemarkText」相关的内部协作步骤。
     */
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

    /**
     * 处理「limitText」相关的内部协作步骤。
     */
    private static function limitText(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit) . '...';
    }

    /**
     * 处理「escape」相关的内部协作步骤。
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 处理「usageNotice」相关的内部协作步骤。
     */
    private function usageNotice(): string
    {
        return '<div class="alert alert-warning" style="margin:10px 0 12px;">'
            . '<strong>提示：</strong>当前页面沿用历史 configs 路由名，但实际数据源是 new_configs；旧 configs 表只用于升级迁移。日常请优先使用“配置中心”下对应的模块化运行时配置页面。'
            . '保存后通过运行缓存生效，无需发布或重启。'
            . '</div>';
    }

}
