<div class="box box-primary">
    <div class="box-header with-border"><h3 class="box-title">入站接口日志</h3></div>
    <div class="box-body">
        <div class="alert alert-info">
            此页只读取数据库 <code>owner_api_logs</code>，不查询阿里云 SLS。
            @if (!$databaseLogging)
                当前部署默认未启用数据库日志后端，历史记录仍可查看；新请求是否写入以实际 Web / Horizon 配置为准。
            @endif
            “自己上报的日志”是独立的客户端上报功能。
        </div>
        @if ($errors->any())
            <div class="alert alert-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="GET" action="{{ route('owner_api_logs.index') }}">
            <div class="row">
                @foreach (['request_id' => '请求 ID（精确）', 'uuid' => '项目 UUID（精确）', 'url' => 'URL / 路径包含'] as $name => $label)
                    <div class="form-group col-md-4">
                        <label for="owner-api-log-{{ $name }}">{{ $label }}</label>
                        <input class="form-control" id="owner-api-log-{{ $name }}" name="{{ $name }}" value="{{ $filters[$name] ?? '' }}" maxlength="{{ $name === 'request_id' ? 64 : 255 }}">
                    </div>
                @endforeach
            </div>
            <div class="row">
                <div class="form-group col-md-2">
                    <label for="owner-api-log-method">HTTP 方法</label>
                    <select class="form-control" id="owner-api-log-method" name="method">
                        <option value="">全部</option>
                        @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $method)
                            <option value="{{ $method }}" {{ ($filters['method'] ?? '') === $method ? 'selected' : '' }}>{{ $method }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label for="owner-api-log-action">记录类型</label>
                    <select class="form-control" id="owner-api-log-action" name="action">
                        <option value="">全部</option>
                        @foreach (['请求', '响应'] as $action)
                            <option value="{{ $action }}" {{ ($filters['action'] ?? '') === $action ? 'selected' : '' }}>{{ $action }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label for="owner-api-log-status">响应状态码</label>
                    <input class="form-control" type="number" min="100" max="599" id="owner-api-log-status" name="status" value="{{ $filters['status'] ?? '' }}">
                </div>
                @foreach (['created_from' => '记录时间起', 'created_to' => '记录时间止'] as $name => $label)
                    <div class="form-group col-md-3">
                        <label for="owner-api-log-{{ $name }}">{{ $label }}</label>
                        <input class="form-control" id="owner-api-log-{{ $name }}" name="{{ $name }}" placeholder="YYYY-MM-DD HH:mm:ss" value="{{ $filters[$name] ?? '' }}">
                    </div>
                @endforeach
            </div>
            <button class="btn btn-primary" type="submit">查询</button>
            <a class="btn btn-default" href="{{ route('owner_api_logs.index') }}">重置</a>
        </form>
    </div>
    <div class="box-body table-responsive">
        <table class="table table-bordered table-hover">
            <thead><tr><th>ID</th><th>记录时间</th><th>请求 ID</th><th>项目 UUID</th><th>类型</th><th>方法</th><th>状态码</th><th>URL</th><th>操作</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td>{{ $log->id }}</td><td style="white-space:nowrap">{{ $log->created_at }}</td>
                        <td style="overflow-wrap:anywhere">{{ $log->request_id }}</td><td>{{ $log->uuid ?? '—' }}</td>
                        <td>{{ $log->action }}</td><td>{{ $log->method }}</td><td>{{ $log->status ?? '—' }}</td>
                        <td style="overflow-wrap:anywhere" title="{{ $log->url }}">{{ \Illuminate\Support\Str::limit($log->url, 120) }}</td>
                        <td><a href="{{ route('owner_api_logs.show', ['id' => $log->id]) }}">详情</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center">暂无符合条件的数据库记录</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="box-footer clearfix">
        <span>每页 20 条，按记录时间倒序。按状态码查询只会匹配响应记录；详情可查看关联请求。</span>
        <ul class="pager">
            @if ($logs->previousPageUrl())<li class="previous"><a href="{{ $logs->previousPageUrl() }}">上一页</a></li>@endif
            @if ($logs->hasMorePages())<li class="next"><a href="{{ $logs->nextPageUrl() }}">下一页</a></li>@endif
        </ul>
    </div>
</div>
