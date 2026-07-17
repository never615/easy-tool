<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Mallto\Tool\Domain\AppStats\AppStatsPodRepository;

/**
 * 旧 Swoole 监控入口的只读兼容适配器。
 *
 * 现有内部监控仍可通过旧 URL 获取原来的 JSON/Grafana 字段；HTML 管理端入口则统一跳转到
 * AppStats。公开 HTML 入口只复用共享 Blade 展示旧接口已经公开的指标，不读取 Redis 内存
 * 等新增管理端数据，也不再包含任何开始/停止采集控制。
 *
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/2/13
 * Time: 11:32 AM
 */
class SwooleStatsController
{
    public function index()
    {
        // Grafana 与旧 JSON 的判断必须先于 HTML 跳转，以保持既有机器调用的字段和响应类型。
        if ($this->isGrafanaRequest()) {
            return response()->json($this->getGrafanaPayload());
        }

        if ($this->wantsJsonResponse()) {
            if (request()->boolean('all_pods')) {
                return $this->handleAllPods();
            }

            $swooleMetrics = $this->getSwooleMetrics();
            $extraMetrics = $this->getExtraMetrics();

            return response()->json($this->getJsonPayload($swooleMetrics, $extraMetrics));
        }

        // 旧管理端菜单、收藏或历史链接统一进入 AppStats，且刻意不透传 query string。
        // start_collect、stop_collect、duration 已失效，不能让旧书签把控制参数带入新页面。
        if ($this->shouldRenderAdminContent()) {
            return redirect()->route('admin_monitor.app_stats');
        }

        // 连续低频采集不再由用户控制。公开兼容入口收到旧控制参数时只做无副作用跳转，
        // duration 无论取值如何都不会写 Redis，也不会形成新的采集 TTL。
        if (request()->boolean('start_collect') || request()->boolean('stop_collect')) {
            return redirect(request()->url().'?all_pods=1');
        }

        if (request()->boolean('all_pods')) {
            return $this->handleAllPods();
        }

        $swooleMetrics = $this->getSwooleMetrics();
        $extraMetrics = $this->getExtraMetrics();

        return $this->renderCompatibilityPage(
            'Swoole 运行状态',
            array_merge(
                [['title' => 'Swoole 运行状态', 'metrics' => $swooleMetrics]],
                $this->getCompatibilityGroups($extraMetrics)
            )
        );
    }

    // =========================================================================
    // 多 Pod 只读兼容（数据来自持续采集的 AppStatsPodRepository）
    // =========================================================================

    /**
     * 保留一个兼容发布周期的旧扩展点。
     *
     * 旧行为会 SETEX 临时采集标志；现在只跳到只读的所有 Pod 页面。该方法不再读取 duration，
     * 也绝不能恢复 Redis 写入，否则 GET 请求会重新变成运行态控制接口。
     */
    protected function handleStartCollect()
    {
        return redirect(request()->url().'?all_pods=1');
    }

    /**
     * 保留一个兼容发布周期的旧扩展点；停止参数与开始参数一样只做无副作用跳转。
     */
    protected function handleStopCollect()
    {
        return redirect(request()->url().'?all_pods=1');
    }

    /**
     * 连续采集没有用户可控制的 TTL，固定返回 Redis TTL 语义中的“不存在”值 -2。
     *
     * 子类可能仍调用本方法，因此本期保留签名；返回值只用于旧响应结构，不触发 Redis 查询。
     */
    protected function getCollectingTtl(): int
    {
        return -2;
    }

    /**
     * 从共享 Pod 仓储取得已完成新鲜度分类的只读结果。
     *
     * 仓储读取固定 Hash/ZSet，不在 HTTP 路径执行 HDEL、DEL、ZREM 或过期清理；异常和非法
     * payload 已由仓储转换为安全结果，因此兼容端点只返回 pods 列表。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAllPodsStats(): array
    {
        return app(AppStatsPodRepository::class)->read()['pods'];
    }

    /**
     * 聚合所有 Pod 的旧兼容响应。
     *
     * collecting/collecting_ttl 字段继续存在，避免旧调用方因字段缺失报错；它们明确表示当前
     * 已不存在临时采集会话，而不是表示后台持续采集已停止。
     */
    protected function handleAllPods()
    {
        $pods = $this->getAllPodsStats();

        if ($this->wantsJsonResponse()) {
            return response()->json([
                'collecting' => false,
                'collecting_ttl' => -2,
                'pods' => $pods,
            ]);
        }

        return $this->renderMultiPodHtml($pods, false, -2);
    }

    protected function wantsJsonResponse(): bool
    {
        if (request()->boolean('json')) {
            return true;
        }

        if (request()->pjax()) {
            return false;
        }

        $accept = (string) request()->header('accept');

        return request()->ajax()
            || (
                strpos($accept, 'application/json') !== false
                && strpos($accept, 'text/html') === false
            );
    }

    protected function shouldRenderAdminContent(): bool
    {
        return request()->pjax()
            || optional(request()->route())->named('admin_monitor.swoole_stats');
    }

    /**
     * 旧子类可能仍引用该 protected 方法，因此本期保留签名。
     *
     * 基类路由已不再使用它渲染监控内容；管理端 HTML 统一跳转 AppStats。
     */
    protected function renderAdminContent(string $header, string $body)
    {
        return Admin::content(function (Content $content) use ($header, $body) {
            $content->header($header);
            $content->description('LaravelS / Swoole');
            $content->body($body);
        });
    }

    /**
     * 子类通过结构化 groups 扩展公开兼容页，避免重新注入任意 HTML 或 JavaScript 字符串。
     *
     * @param  array<string, mixed>  $extraMetrics
     * @return array<int, array{title: string, metrics: array<string, mixed>}>
     */
    protected function getCompatibilityGroups(array $extraMetrics): array
    {
        return [];
    }

    /**
     * 把各 Pod 的兼容 stats 和随 Pod 快照写入的 SkyLab 指标转换为共享 Blade 数据。
     *
     * @param  array<int, array<string, mixed>>  $pods
     * @return array<int, array{title: string, metrics: array<string, mixed>}>
     */
    protected function podCompatibilityGroups(array $pods): array
    {
        $groups = [];
        foreach ($pods as $pod) {
            $hostname = (string) ($pod['hostname'] ?? 'unknown');
            $groups[] = [
                'title' => $hostname.' / Swoole',
                'metrics' => is_array($pod['stats'] ?? null) ? $pod['stats'] : [],
            ];

            if (is_array($pod['skylab_tcp'] ?? null)) {
                $groups[] = [
                    'title' => $hostname.' / SkyLab TCP',
                    'metrics' => $pod['skylab_tcp'],
                ];
            }
        }

        return $groups;
    }

    /**
     * 渲染共享 standalone 外壳。Blade 使用转义输出，且兼容模式不会加载 AppStats 数据 API，
     * 所以公开旧入口不会因此获得 Redis 内存等仅管理端可见的新指标。
     *
     * @param  array<int, array{title: string, metrics: array<string, mixed>}>  $groups
     */
    protected function renderCompatibilityPage(string $title, array $groups)
    {
        return response()->view('easy-tool::app_stats.standalone', [
            'compatibilityTitle' => $title,
            'compatibilityGroups' => $groups,
        ]);
    }

    /**
     * 保留原 protected 签名供下游包平滑升级；isCollecting/collectingTtl 不再控制页面或进程。
     */
    protected function renderMultiPodHtml(array $allPodsStats, bool $isCollecting, int $collectingTtl)
    {
        return $this->renderCompatibilityPage(
            'Swoole 运行状态 — 所有 Pod',
            $this->podCompatibilityGroups($allPodsStats)
        );
    }

    /**
     * 保留旧 body 扩展点，但只返回结构化共享视图，不恢复开始/停止按钮或控制脚本。
     */
    protected function renderMultiPodBodyHtml(array $allPodsStats, bool $isCollecting, int $collectingTtl): string
    {
        return view('easy-tool::app_stats.content', [
            'mode' => 'compatibility',
            'dataUrl' => null,
            'showRedisMemory' => false,
            'autoRefresh' => false,
            'compatibilityTitle' => 'Swoole 运行状态 — 所有 Pod',
            'compatibilityGroups' => $this->podCompatibilityGroups($allPodsStats),
        ])->render();
    }

    // =========================================================================
    // 原有机器接口方法：Grafana 名称与旧 JSON 顶层结构必须保持不变
    // =========================================================================

    /**
     * 是否为 Grafana 请求
     *
     * @return bool
     */
    protected function isGrafanaRequest(): bool
    {
        return (string) request()->get('grafana') === '1';
    }

    /**
     * Grafana 需要的 metrics（参考 SwooleStatsCollector）
     *
     * @return array
     */
    protected function getGrafanaPayload(): array
    {
        return array_merge($this->getGrafanaMetrics(), $this->getGrafanaExtraMetrics());
    }

    /**
     * Swoole 指标转成 Grafana/Prometheus 结构
     *
     * @return array
     */
    protected function getGrafanaMetrics(): array
    {
        $server = app('swoole');
        $stats = $server->stats();
        $setting = $server->setting;
        if (! isset($stats['worker_num'])) {
            $stats['worker_num'] = $setting['worker_num'];
        }
        if (! isset($stats['task_worker_num'])) {
            $stats['task_worker_num'] = isset($setting['task_worker_num']) ? $setting['task_worker_num'] : 0;
        }

        return [
            ['name' => 'swoole_cpu_num',              'type' => 'gauge', 'value' => swoole_cpu_num()],
            ['name' => 'swoole_start_time',           'type' => 'gauge', 'value' => $stats['start_time']],
            ['name' => 'swoole_connection_num',       'type' => 'gauge', 'value' => $stats['connection_num']],
            ['name' => 'swoole_request_count',        'type' => 'gauge', 'value' => $stats['request_count']],
            ['name' => 'swoole_worker_num',           'type' => 'gauge', 'value' => $stats['worker_num']],
            ['name' => 'swoole_idle_worker_num',      'type' => 'gauge', 'value' => isset($stats['idle_worker_num']) ? $stats['idle_worker_num'] : 0],
            ['name' => 'swoole_task_worker_num',      'type' => 'gauge', 'value' => $stats['task_worker_num']],
            ['name' => 'swoole_task_idle_worker_num', 'type' => 'gauge', 'value' => isset($stats['task_idle_worker_num']) ? $stats['task_idle_worker_num'] : 0],
            ['name' => 'swoole_tasking_num',          'type' => 'gauge', 'value' => isset($stats['tasking_num']) ? $stats['tasking_num'] : 0],
        ];
    }

    /**
     * 额外指标转 Grafana/Prometheus 结构（子类可重写）
     *
     * @return array
     */
    protected function getGrafanaExtraMetrics(): array
    {
        return [];
    }

    /**
     * 获取 Swoole 运行指标
     *
     * @return array
     */
    protected function getSwooleMetrics(): array
    {
        $server = app('swoole');
        $stats = $server->stats();

        return array_merge([
            'hostname' => gethostname(),
            'swoole_cpu_num' => swoole_cpu_num(),
        ], [
            'worker_num_use' => ($stats['worker_num'] - $stats['idle_worker_num']).'/'.$stats['worker_num'],
            'task_worker_num_use' => ($stats['task_worker_num'] - $stats['task_idle_worker_num']).'/'.$stats['task_worker_num'],
//            'worker_num' => $stats['worker_num'],
//            'idle_worker_num' => $stats['idle_worker_num'],
//            'task_worker_num' => $stats['task_worker_num'],
//            'task_idle_worker_num' => $stats['task_idle_worker_num'],
            'start_time' => $stats['start_time'].' ('.date('Y-m-d H:i:s', $stats['start_time']).')',
            'connection_num' => $stats['connection_num'],
            'request_count' => $stats['request_count'],
            'tasking_num' => $stats['tasking_num'],
        ], []);
    }

    /**
     * 获取额外指标（子类可重写）
     *
     * @return array
     */
    protected function getExtraMetrics(): array
    {
        return [];
    }

    /**
     * JSON 响应结构（子类可重写）
     *
     * @param  array  $swooleMetrics
     * @param  array  $extraMetrics
     * @return array
     */
    protected function getJsonPayload(array $swooleMetrics, array $extraMetrics): array
    {
        if (empty($extraMetrics)) {
            return $swooleMetrics;
        }

        return [
            'swoole' => $swooleMetrics,
            'extra' => $extraMetrics,
        ];
    }

    /**
     * 旧字符串 HTML 扩展点保留一个兼容发布周期，但新路由渲染链不再调用该返回值。
     *
     * @param  array  $extraMetrics
     * @return string
     */
    protected function getExtraHtml(array $extraMetrics): string
    {
        return '';
    }

    /**
     * 旧字符串脚本扩展点保留一个兼容发布周期，但新路由渲染链不再调用该返回值。
     *
     * @param  array  $extraMetrics
     * @return string
     */
    protected function getExtraScript(array $extraMetrics): string
    {
        return '';
    }

    /**
     * 保留旧签名供下游子类兼容；extraHtml/extraScript 被有意忽略，防止任意字符串注入共享页。
     *
     * @param  array  $swooleMetrics
     * @param  string  $extraHtml
     * @param  string  $extraScript
     * @return \Illuminate\Http\Response
     */
    protected function renderHtmlWithExtras(array $swooleMetrics, string $extraHtml = '', string $extraScript = '')
    {
        return $this->renderCompatibilityPage('Swoole 运行状态', [[
            'title' => 'Swoole 运行状态',
            'metrics' => $swooleMetrics,
        ]]);
    }

    /**
     * 保留旧 body 签名供下游子类兼容；只渲染结构化指标，不执行传入的字符串扩展。
     */
    protected function renderCurrentPodBodyHtml(
        array $swooleMetrics,
        string $extraHtml = '',
        string $extraScript = ''
    ): string {
        return view('easy-tool::app_stats.content', [
            'mode' => 'compatibility',
            'dataUrl' => null,
            'showRedisMemory' => false,
            'autoRefresh' => false,
            'compatibilityTitle' => 'Swoole 运行状态',
            'compatibilityGroups' => [[
                'title' => 'Swoole 运行状态',
                'metrics' => $swooleMetrics,
            ]],
        ])->render();
    }

    /**
     * 把 key/value 指标转换为 Grafana/Prometheus 结构
     *
     * @param  array  $metrics
     * @return array
     */
    protected function buildGrafanaMetricsFromMap(array $metrics): array
    {
        $result = [];
        foreach ($metrics as $key => $value) {
            if (is_numeric($value)) {
                $result[] = [
                    'name' => $key,
                    'type' => 'gauge',
                    'value' => $value + 0,
                ];
            }
        }

        return $result;
    }
}
