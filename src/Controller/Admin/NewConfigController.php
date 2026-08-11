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

/**
 * NewConfigController：处理 new config controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
class NewConfigController extends AdminCommonController
{
    private const RELOAD_THROTTLE_KEY = 'new_configs:manual_reload_throttle';
    private const RELOAD_THROTTLE_SECONDS = 30;

    /**
     * 处理「getHeaderTitle」相关的内部协作步骤。
     */
    protected function getHeaderTitle()
    {
        return '配置中心';
    }

    /**
     * 处理「getModel」相关的内部协作步骤。
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
            ->orderBy('group_key')
            ->orderBy('sort')
            ->orderBy('id');

        $grid->disableCreateButton();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableDelete();
        });

        $grid->group_key('分组')->display(function ($groupKey) {
            $groupKey = (string)$groupKey;
            $groupLabel = NewConfig::groupOptions()[$groupKey] ?? '未知分组';

            return '<span class="label label-info">' . e($groupLabel) . '</span>'
                . '<br><code>' . e($groupKey) . '</code>';
        });
        $grid->name('配置项');
        $grid->type('类型')->label();
        $grid->value('当前值')->limit(50)->editable(); //limit必须放在 editable 之前
        $grid->default_value('默认值')->limit(50);
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
            $filter->equal('group_key', '分组')->select(NewConfig::groupOptions());
        });

        $grid->tools(function ($tools) {
            $tools->append('<a class="btn btn-sm btn-warning" href="' . route('new_configs.publish_restart') . '"><i class="fa fa-refresh"></i> 发布启动配置</a>');
        });
    }

    /**
     * 处理「envPreview」相关的内部协作步骤。
     */
    public function envPreview(NewConfigEffectiveEnv $effectiveEnv)
    {
        return response()->json($effectiveEnv->snapshot(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 处理「reload」相关的内部协作步骤。
     */
    public function reload(NewConfigPublisher $publisher)
    {
        if (!Cache::add(self::RELOAD_THROTTLE_KEY, time(), self::RELOAD_THROTTLE_SECONDS)) {
            admin_toastr('发布操作过于频繁，请 30 秒后再试。', 'warning');

            return redirect()->route('new_configs.publish_restart');
        }

        $result = $publisher->publish(true, true);
        $restart = $result['restart'] ?? null;

        if ($restart === null) {
            admin_toastr('配置已发布；当前没有需要重启的已发布配置项，服务广播未执行。', 'warning');
        } elseif (is_array($restart) && ($restart['reason'] ?? null) === 'new_config.restart.enabled is false') {
            admin_toastr('启动配置已发布；重启功能已关闭。', 'warning');
        } elseif (is_array($restart) && ($restart['skipped'] ?? false)) {
            admin_toastr('启动配置已发布，但服务重启跳过：' . ($restart['reason'] ?? 'unknown'), 'warning');
        } else {
            $generation = $result['generation']['generation'] ?? null;
            $horizon = $restart['horizon'] ?? null;
            $horizonMessage = is_array($horizon) && !($horizon['skipped'] ?? false)
                ? '，并已请求 Horizon 重启'
                : '';
            $generationMessage = $generation ? "，配置版本 {$generation} 已广播" : '';
            admin_toastr('启动配置已发布，重启请求已调度' . $horizonMessage . $generationMessage . '。');
        }

        return redirect()->route('new_configs.publish_restart');
    }

    /**
     * 处理「formOption」相关的内部协作步骤。
     */
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
            ->help('开启时表示该启动配置需要服务重启后才会被所有新进程读取。保存后在下次计划内重启生效；本页不会修改真实 .env。');
        $form->display('last_published_at', '最近发布时间');
        $form->display('last_publish_error', '最近发布错误');
        $form->display('key', 'Key');
        $form->display('env_key', 'Env Key')
            ->help('导出为运行期环境变量名，例如 SWOOLE_TASK_MONITOR_ENABLED。<br>' . NewConfigBootstrapKeyGuard::forbiddenHint());
    }

}
