<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Support\Facades\Cache;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\NewConfigBootstrapKeyGuard;
use Mallto\Tool\Domain\NewConfig\NewConfigEffectiveEnv;
use Mallto\Tool\Domain\NewConfig\NewConfigPublisher;

class NewConfigController extends AdminCommonController
{
    private const RELOAD_THROTTLE_KEY = 'new_configs:manual_reload_throttle';
    private const RELOAD_THROTTLE_SECONDS = 30;

    protected function getHeaderTitle()
    {
        return '配置中心';
    }

    protected function getModel()
    {
        return NewConfig::class;
    }

    protected function gridOption(Grid $grid)
    {
        $grid->model()
            ->orderBy('group_key')
            ->orderBy('sort')
            ->orderBy('id');

        $grid->disableCreateButton();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableDelete();
        });

        $grid->group_key('分组')->label();
        $grid->name('配置项');
        $grid->type('类型')->label();
        $grid->value('当前值')->limit(30)->editable(); //limit必须放在 editable 之前
        $grid->default_value('默认值')->limit(30);
        $grid->remark('说明')->limit(40);
        $grid->is_enabled('启用')->switchE();
        $grid->requires_reload('需重启')->display(function ($value) {
            return $value
                ? '<span class="label label-warning">是</span>'
                : '<span class="label label-default">否</span>';
        });
        $grid->last_published_at('发布时间');
        $grid->key('Key')->copyable();
        $grid->env_key('Env Key')->copyable();
        $grid->last_publish_error('发布错误')->limit(40);

        $grid->filter(function ($filter) {
            $filter->ilike('key', 'Key');
            $filter->ilike('env_key', 'Env Key');
            $filter->ilike('name', '配置项');
            $filter->equal('group_key', '分组');
        });

        $grid->tools(function ($tools) {
            $tools->append('<a class="btn btn-sm btn-warning" href="' . route('new_configs.publish_restart') . '"><i class="fa fa-refresh"></i> 发布与重启</a>');
        });
    }

    public function envPreview(NewConfigEffectiveEnv $effectiveEnv)
    {
        return response()->json($effectiveEnv->snapshot(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function reload(NewConfigPublisher $publisher)
    {
        if (!Cache::add(self::RELOAD_THROTTLE_KEY, time(), self::RELOAD_THROTTLE_SECONDS)) {
            admin_toastr('重启操作过于频繁，请 30 秒后再试。', 'warning');

            return redirect()->route('new_configs.publish_restart');
        }

        $result = $publisher->publish(true, true);
        $restart = $result['restart'] ?? null;

        if ($restart === null) {
            admin_toastr('配置已发布；当前没有需要重启的已发布配置项，服务广播未执行。', 'warning');
        } elseif (is_array($restart) && ($restart['skipped'] ?? false)) {
            admin_toastr('配置已发布，但服务重启跳过：' . ($restart['reason'] ?? 'unknown'), 'warning');
        } else {
            $generation = $result['generation']['generation'] ?? null;
            $horizon = $restart['horizon'] ?? null;
            $horizonMessage = is_array($horizon) && !($horizon['skipped'] ?? false)
                ? '，并已请求 Horizon 重启'
                : '';
            $generationMessage = $generation ? "，配置版本 {$generation} 已广播" : '';
            admin_toastr('配置已发布，并已触发服务重启' . $horizonMessage . $generationMessage . '。');
        }

        return redirect()->route('new_configs.publish_restart');
    }

    protected function formOption(Form $form)
    {
        $form->tools(function (Form\Tools $tools) {
            $tools->disableDelete();
        });

        $form->display('group_key', '分组');
        $form->display('name', '配置项');
        $form->display('type', '类型');
        $form->textarea('value', '当前值')
            ->help('留空时使用默认值。boolean 建议填写 0/1；float 可填写 0.01。');
        $form->switch('is_enabled', '启用')->default(1);
        $form->display('default_value', '默认值');
        $form->display('options', '选项');
        $form->display('remark', '说明');
        $form->display('requires_reload', '需重启')->with(function ($value) {
            return $value ? '是' : '否';
        })
            ->help('开启时表示该配置需要服务重启后才会被所有新进程读取。保存配置只发布运行期 env 并刷新 config cache，不会自动重启；需要生效时请到“发布与重启”页面执行。');
        $form->display('last_published_at', '最近发布时间');
        $form->display('last_publish_error', '最近发布错误');
        $form->display('key', 'Key');
        $form->display('env_key', 'Env Key')
            ->help('导出为运行期环境变量名，例如 SWOOLE_TASK_MONITOR_ENABLED。<br>' . NewConfigBootstrapKeyGuard::forbiddenHint());
    }

}
