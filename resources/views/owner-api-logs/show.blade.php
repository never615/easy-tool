<div class="box box-primary">
    <div class="box-header with-border">
        <h3 class="box-title">请求 ID：{{ $log->request_id }}</h3>
        <div class="box-tools"><a class="btn btn-default btn-sm" href="{{ route('owner_api_logs.index', ['request_id' => $log->request_id]) }}">查看此请求的全部记录</a></div>
    </div>
    <div class="box-body">
        <p>同一请求 ID、同一项目 UUID 的请求与响应一并展示，最多 20 条。异步写入顺序可能不同；只有请求记录时，响应可能尚未落库或后续处理抛出了异常。</p>
        <a class="btn btn-default btn-sm" href="{{ route('owner_api_logs.index') }}">返回日志列表</a>
    </div>
</div>
@foreach ($records as $record)
    <div class="box {{ $record->id === $log->id ? 'box-primary' : 'box-default' }}">
        <div class="box-header with-border"><h3 class="box-title">{{ $record->action }} · 记录 #{{ $record->id }}</h3></div>
        <div class="box-body">
            <dl class="dl-horizontal">
                @foreach (['created_at' => '记录时间', 'request_id' => '请求 ID', 'uuid' => '项目 UUID', 'method' => 'HTTP 方法', 'status' => '响应状态码', 'request_ip' => '来源 IP', 'user_id' => '用户 ID', 'url' => 'URL'] as $field => $label)
                    <dt>{{ $label }}</dt><dd style="overflow-wrap:anywhere">{{ $record->{$field} ?? '—' }}</dd>
                @endforeach
            </dl>
            <h4>请求头</h4>
            <pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:320px;overflow:auto">{{ \Mallto\Tool\Domain\Log\OwnerApiLogViewer::formatPayload($record->header) }}</pre>
            <h4>{{ $record->action === '请求' ? '请求参数' : '响应内容' }}</h4>
            <pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:600px;overflow:auto">{{ \Mallto\Tool\Domain\Log\OwnerApiLogViewer::formatPayload($record->input) }}</pre>
        </div>
    </div>
@endforeach
