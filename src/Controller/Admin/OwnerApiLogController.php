<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Mallto\Admin\Data\Administrator;
use Mallto\Tool\Domain\Log\LoggerDb;
use Mallto\Tool\Domain\Log\OwnerApiLogViewer;

/** 只读数据库入站日志；仅注册列表、详情两个 GET 路由。 */
class OwnerApiLogController extends Controller
{
    public function index(Request $request, OwnerApiLogViewer $viewer)
    {
        $endTimeRules = ['nullable', 'date_format:Y-m-d H:i:s'];
        if ($request->filled('created_from')) {
            $endTimeRules[] = 'after_or_equal:created_from';
        }
        $filters = $request->validate([
            'request_id' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'action' => ['nullable', Rule::in(['请求', '响应'])],
            'status' => ['nullable', 'integer', 'between:100,599'],
            'created_from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'created_to' => $endTimeRules,
            'page' => ['nullable', 'integer', 'between:1,100000'],
        ]);
        // 列表不加载请求头、正文，不计算全表总数；每页固定 20 条。
        $logs = $viewer->query($this->admin(), $filters)
            ->select(['id', 'request_id', 'uuid', 'action', 'method', 'status', 'url', 'created_at'])
            ->orderByDesc('created_at')->orderByDesc('id')->simplePaginate(20)
            ->appends(array_diff_key($filters, ['page' => true]));

        return $this->page('数据库记录', 'easy-tool::owner-api-logs.index', [
            'logs' => $logs, 'filters' => $filters,
            'databaseLogging' => config('app.log.logger') === LoggerDb::class && config('app.db_log', false),
        ]);
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
