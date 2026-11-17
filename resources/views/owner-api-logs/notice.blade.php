<div class="alert alert-info">
    此页只读取数据库 <code>owner_api_logs</code>，不查询阿里云 SLS。
    @if (!$databaseLogging)
        当前部署默认未启用数据库日志后端，历史记录仍可查看；新请求是否写入以实际 Web / Horizon 配置为准。
    @endif
    “自己上报的日志”是独立的客户端上报功能。
</div>
<p class="text-muted">按状态码查询只会匹配响应记录；点击详情可查看关联请求。</p>
