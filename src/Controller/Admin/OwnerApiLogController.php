<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Mallto\Admin\Data\Administrator;
use Mallto\Tool\Data\OwnerApiLog;
use Mallto\Tool\Domain\Log\LoggerDb;
use Mallto\Tool\Domain\Log\OwnerApiLogViewer;

/** 只读数据库入站日志；仅注册列表、详情两个 GET 路由。 */
class OwnerApiLogController extends Controller
{
    public function index(Request $request, OwnerApiLogViewer $viewer)
    {
        // 当前 Grid 的隐藏导出按钮不拦截导出参数，入口同时禁止直接导出。
        abort_if($request->has(Grid\Exporter::$queryName), 403);
        $endTimeRules = ['nullable', 'date_format:Y-m-d H:i:s'];
        if ($request->filled('created_from')) {
            $endTimeRules[] = 'after_or_equal:created_from';
        }
        $request->validate([
            'request_id' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'action' => ['nullable', Rule::in(['请求', '响应'])],
            'status' => ['nullable', 'integer', 'between:100,599'],
            'created_from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'created_to' => $endTimeRules,
            'page' => ['nullable', 'integer', 'between:1,100000'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);
        $admin = $this->admin();
        $grid = new Grid(new OwnerApiLog());
        // 列表只加载概要；原生分页计算筛选后的总数，不加载请求头和正文。
        $grid->model()->where(function ($query) use ($viewer, $admin): void {
            $viewer->applyTo($query, $admin);
        })->select(['id', 'request_id', 'uuid', 'action', 'method', 'status', 'url', 'created_at'])
            ->orderByDesc('created_at')->orderByDesc('id');
        $grid->paginate(20);
        $grid->perPages([20, 50, 100]);
        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->disableRowSelector();
        $grid->disableColumnSelector();
        $grid->actions(function (Grid\Displayers\Actions $actions): void {
            $actions->disableEdit();
            $actions->disableDelete();
        });
        $grid->column('id', 'ID');
        $grid->column('created_at', '记录时间');
        $grid->column('request_id', '请求 ID');
        $grid->column('uuid', '项目 UUID');
        $grid->column('action', '类型');
        $grid->column('method', '方法');
        $grid->column('status', '状态码');
        $grid->column('url', 'URL')->limit(120);
        // 所有组件筛选使用同一套查询逻辑，保留 URL 字面量匹配。
        $grid->filter(function (Grid\Filter $filter) use ($viewer): void {
            $filter->disableIdFilter();
            foreach (['request_id' => '请求 ID（精确）', 'uuid' => '项目 UUID（精确）',
                'url' => 'URL / 路径包含', 'method' => 'HTTP 方法', 'action' => '记录类型',
                'status' => '响应状态码', 'created_from' => '记录时间起', 'created_to' => '记录时间止'] as $name => $label) {
                $field = $filter->where(function ($query) use ($viewer, $name): void {
                    $viewer->applyFilters($query, [$name => $this->input]);
                }, $label, $name);
                if ($name === 'method') {
                    $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
                    $field->select(array_combine($methods, $methods));
                } elseif ($name === 'action') {
                    $field->select(['请求' => '请求', '响应' => '响应']);
                } elseif ($name === 'status') {
                    $field->integer();
                } elseif (in_array($name, ['created_from', 'created_to'], true)) {
                    $field->datetime();
                }
            }
        });
        $grid->expandFilter();
        $grid->header(static fn () => view('easy-tool::owner-api-logs.notice', [
            'databaseLogging' => config('app.log.logger') === LoggerDb::class && config('app.db_log', false),
        ])->render());

        return Admin::content(static function (Content $content) use ($grid): void {
            $content->header('入站接口日志');
            $content->description('数据库记录');
            $content->body($grid->render());
        });
    }

    public function show($id, OwnerApiLogViewer $viewer)
    {
        $admin = $this->admin();
        $log = $viewer->query($admin)->findOrFail($id);
        // request_id 之外再限定 UUID，列表与直接访问详情保持相同的数据权限。
        $related = $log->request_id === '' ? collect() : $viewer->query($admin)
            ->where('request_id', $log->request_id)->where('uuid', $log->uuid)
            ->where('id', '<>', $log->id)->orderBy('id')->limit(19)->get();
        $records = $related->push($log)->sortBy('id')->values();

        return $this->page('请求与响应详情', 'easy-tool::owner-api-logs.show', [
            'log' => $log, 'records' => $records,
        ]);
    }

    private function admin(): Administrator
    {
        $admin = Admin::user();
        abort_unless($admin instanceof Administrator, 403);
        return $admin;
    }

    private function page(string $description, string $view, array $data)
    {
        return Admin::content(static function (Content $content) use ($description, $view, $data): void {
            $content->header('入站接口日志');
            $content->description($description);
            $content->body(view($view, $data)->render());
        });
    }
}
