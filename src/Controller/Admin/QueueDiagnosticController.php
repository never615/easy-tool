<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticConfig;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticRedisStore;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticSnapshotter;

class QueueDiagnosticController extends Controller
{
    public function index(
        QueueDiagnosticConfig $config,
        QueueDiagnosticRedisStore $store,
        QueueDiagnosticSnapshotter $snapshotter
    ) {
        $snapshot = $this->snapshot($config, $store, $snapshotter);
        $payload = [
            'config' => $config->snapshot(),
            'settings' => $config->currentSettings(),
            'snapshot' => $snapshot,
        ];

        if ($this->wantsJsonResponse()) {
            return response()->json($payload);
        }

        return Admin::content(function (Content $content) use ($payload) {
            $content->header('队列诊断监控');
            $content->description('Redis / Horizon');
            $content->body($this->renderHtml($payload));
        });
    }

    private function wantsJsonResponse(): bool
    {
        if (request()->boolean('json')) {
            return true;
        }

        if (request()->pjax()) {
            return false;
        }

        $accept = (string)request()->header('accept');

        return strpos($accept, 'application/json') !== false
            && strpos($accept, 'text/html') === false;
    }

    public function saveSettings(QueueDiagnosticConfig $config)
    {
        $config->saveSettings(request()->all());

        return redirect()->route('queue_diagnostics.index', [ 'saved' => 1 ]);
    }

    private function snapshot(
        QueueDiagnosticConfig $config,
        QueueDiagnosticRedisStore $store,
        QueueDiagnosticSnapshotter $snapshotter
    ): array {
        if (request()->boolean('capture') && $config->enabled() && $config->snapshotEnabled()) {
            return $snapshotter->capture();
        }

        $windowStart = (int)request()->get('window', $config->windowStart());

        return $store->windowSnapshot($windowStart);
    }

    private function renderHtml(array $payload): string
    {
        $config = $payload['config'];
        $snapshot = $payload['snapshot'];
        $enabled = !empty($config['enabled']);
        $statusClass = $enabled ? 'label-success' : 'label-default';
        $statusText = $enabled ? '已开启' : '未开启';
        $captureUrl = request()->url() . '?capture=1';
        $jsonUrl = request()->url() . '?json=1';
        $savedAlert = request()->boolean('saved')
            ? '<div class="alert alert-success queue-diag-alert">队列诊断配置已保存，下一次队列事件或 snapshot 会读取新配置。</div>'
            : '';
        $windowTime = !empty($snapshot['window_started_at'])
            ? date('Y-m-d H:i:s', (int)$snapshot['window_started_at'])
            : '-';

        return <<<HTML
<style>
    .queue-diag-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .queue-diag-panel { background: #fff; border: 1px solid #d8dde6; border-radius: 4px; padding: 14px 16px; margin-bottom: 14px; }
    .queue-diag-panel h3 { margin-top: 0; font-size: 16px; font-weight: 600; }
    .queue-diag-table { width: 100%; border-collapse: collapse; }
    .queue-diag-table th, .queue-diag-table td { border-bottom: 1px solid #edf0f5; padding: 7px 8px; text-align: left; vertical-align: top; }
    .queue-diag-table th { width: 36%; color: #5f6b7a; font-weight: 600; background: #fafbfc; }
    .queue-diag-actions { margin: 0 0 14px; }
    .queue-diag-actions a { margin-right: 10px; }
    .queue-diag-alert { margin-bottom: 14px; }
    .queue-diag-form-table input[type="text"], .queue-diag-form-table input[type="number"] { max-width: 360px; }
    .queue-diag-help { color: #8a94a6; font-size: 12px; margin-top: 4px; }
    .queue-diag-form-actions { margin-top: 12px; }
    .queue-diag-empty { color: #8a94a6; padding: 8px 0; }
    @media (max-width: 900px) { .queue-diag-grid { grid-template-columns: 1fr; } }
</style>

{$savedAlert}

<div class="queue-diag-actions">
    <span class="label {$statusClass}">{$statusText}</span>
    <span style="margin-left:8px;color:#667085">当前窗口: {$windowTime}</span>
    <a class="btn btn-xs btn-primary" href="{$captureUrl}">采样并刷新</a>
    <a class="btn btn-xs btn-default" href="{$jsonUrl}">JSON</a>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>配置状态</h3>
        {$this->renderTable($config)}
    </div>
    <div class="queue-diag-panel">
        <h3>窗口事件</h3>
        {$this->renderTable($snapshot['events'] ?? [])}
    </div>
</div>

<div class="queue-diag-panel">
    <h3>配置管理</h3>
    {$this->renderSettingsForm($payload['settings'] ?? [])}
</div>

<div class="queue-diag-panel">
    <h3>Redis Memory</h3>
    {$this->renderRedisMemoryTable($snapshot['redis_memory'] ?? [])}
</div>

<div class="queue-diag-panel">
    <h3>队列 Backlog</h3>
    {$this->renderTable($snapshot['queue_sizes'] ?? [])}
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>Top Jobs</h3>
        {$this->renderTopRows($snapshot['jobs'] ?? [])}
    </div>
    <div class="queue-diag-panel">
        <h3>数据来源</h3>
        {$this->renderTopRows($snapshot['sources'] ?? [])}
    </div>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>Payload Top</h3>
        {$this->renderTopRows($snapshot['payload_jobs'] ?? [])}
    </div>
    <div class="queue-diag-panel">
        <h3>慢任务</h3>
        {$this->renderTopRows($snapshot['slow_jobs'] ?? [])}
    </div>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>大 Payload</h3>
        {$this->renderTopRows($snapshot['large_payload_jobs'] ?? [])}
    </div>
    <div class="queue-diag-panel">
        <h3>失败任务</h3>
        {$this->renderTopRows($snapshot['failed_jobs'] ?? [])}
    </div>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>异常状态</h3>
        {$this->renderTable($snapshot['anomaly'] ?? [])}
    </div>
    <div class="queue-diag-panel">
        <h3>Keyspace</h3>
        {$this->renderTable($snapshot['keyspace'] ?? [])}
    </div>
</div>

<div class="queue-diag-panel">
    <h3>最近事件</h3>
    {$this->renderTable($snapshot['last_event'] ?? [])}
</div>
HTML;
    }

    private function renderSettingsForm(array $settings): string
    {
        if (empty($settings)) {
            return '<div class="queue-diag-empty">暂无配置定义</div>';
        }

        $saveUrl = route('queue_diagnostics.settings');
        $html = '<form method="POST" action="' . $this->escape($saveUrl) . '">';
        $html .= csrf_field();
        $html .= '<table class="queue-diag-table queue-diag-form-table"><thead><tr><th>配置项</th><th>当前值</th><th>说明</th></tr></thead><tbody>';

        foreach ($settings as $setting) {
            $key = (string)($setting['key'] ?? '');
            $label = (string)($setting['label'] ?? $key);
            $type = (string)($setting['type'] ?? 'string');
            $value = (string)($setting['value'] ?? '');
            $remark = (string)($setting['remark'] ?? '');
            $default = (string)($setting['default'] ?? '');
            $source = !empty($setting['is_default'])
                ? '<span class="label label-default">默认值</span>'
                : '<span class="label label-info">已覆盖</span>';

            $html .= '<tr>';
            $html .= '<th>' . $this->escape($label) . '<div class="queue-diag-help">' . $this->escape($key) . '</div></th>';
            $html .= '<td>' . $this->renderSettingInput($key, $type, $value, $setting) . '</td>';
            $html .= '<td>' . $this->escape($remark)
                . '<div class="queue-diag-help">默认值: ' . $this->escape($default) . ' ' . $source . '</div></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<div class="queue-diag-form-actions">'
            . '<button type="submit" class="btn btn-primary">保存配置</button> '
            . '<span class="queue-diag-help">配置保存在专用表中，不进入全局 configs 页面；等于默认值的项不会额外落库。</span>'
            . '</div>';

        return $html . '</form>';
    }

    private function renderSettingInput(string $key, string $type, string $value, array $setting): string
    {
        $escapedKey = $this->escape($key);

        if ($type === 'boolean') {
            $checked = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? ' checked' : '';

            return '<input type="hidden" name="' . $escapedKey . '" value="0">'
                . '<label style="font-weight:400">'
                . '<input type="checkbox" name="' . $escapedKey . '" value="1"' . $checked . '> 开启'
                . '</label>';
        }

        if ($type === 'integer') {
            $min = array_key_exists('min', $setting) ? ' min="' . $this->escape((string)$setting['min']) . '"' : '';
            $max = array_key_exists('max', $setting) ? ' max="' . $this->escape((string)$setting['max']) . '"' : '';

            return '<input class="form-control input-sm" type="number" name="' . $escapedKey . '" value="'
                . $this->escape($value) . '"' . $min . $max . '>';
        }

        return '<input class="form-control input-sm" type="text" name="' . $escapedKey . '" value="'
            . $this->escape($value) . '">';
    }

    private function renderTable(array $rows): string
    {
        if (empty($rows)) {
            return '<div class="queue-diag-empty">暂无数据</div>';
        }

        $html = '<table class="queue-diag-table"><tbody>';
        foreach ($rows as $key => $value) {
            $html .= '<tr><th>' . $this->escape((string)$key) . '</th><td>' . $this->escapeValue($value) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function renderRedisMemoryTable(array $rows): string
    {
        if (empty($rows)) {
            return '<div class="queue-diag-empty">暂无数据</div>';
        }

        $descriptions = $this->redisMemoryDescriptions();
        $html = '<table class="queue-diag-table"><thead><tr><th>参数</th><th>当前值</th><th>作用</th></tr></thead><tbody>';

        foreach ($rows as $key => $value) {
            $key = (string)$key;
            $html .= '<tr><th>' . $this->escape($key) . '</th><td>' . $this->renderRedisMemoryValue($key, $value) . '</td><td>'
                . $this->escape($descriptions[$key] ?? 'Redis INFO memory 返回字段，用于补充判断 Redis 内存状态。') . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function renderRedisMemoryValue(string $key, $value): string
    {
        if ($this->isRedisMemoryByteKey($key) && is_numeric($value)) {
            return $this->escape($this->formatBytes((int)$value));
        }

        return $this->escapeValue($value);
    }

    private function isRedisMemoryByteKey(string $key): bool
    {
        return in_array($key, [
            'used_memory',
            'used_memory_rss',
            'used_memory_peak',
            'used_memory_overhead',
            'used_memory_startup',
            'used_memory_dataset',
            'allocator_allocated',
            'allocator_active',
            'allocator_resident',
            'total_system_memory',
            'used_memory_lua',
            'used_memory_scripts',
            'maxmemory',
            'allocator_frag_bytes',
            'allocator_rss_bytes',
            'rss_overhead_bytes',
            'mem_fragmentation_bytes',
            'mem_not_counted_for_evict',
            'mem_replication_backlog',
            'mem_clients_slaves',
            'mem_clients_normal',
            'mem_cluster_links',
            'mem_aof_buffer',
            'mem_overhead_db_hashtable_rehashing',
            'mem_overhead_hashtable_main',
            'mem_overhead_hashtable_expires',
        ], true);
    }

    private function formatBytes(int $bytes): string
    {
        $sign = $bytes < 0 ? '-' : '';
        $absoluteBytes = abs($bytes);
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
        $value = (float)$absoluteBytes;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        if ($unitIndex === 0) {
            return $sign . number_format($value, 0) . ' B';
        }

        return $sign . number_format($value, 2) . ' ' . $units[$unitIndex]
            . ' (' . number_format($bytes) . ' B)';
    }

    private function redisMemoryDescriptions(): array
    {
        return [
            'error' => 'Redis INFO memory 采样失败原因；出现该字段时需要先确认 Redis 权限、网络或命令兼容性。',
            'used_memory' => 'Redis 分配器已分配的内存总量，排查 Redis 内存告警时最核心的观察值。',
            'used_memory_human' => 'used_memory 的可读格式，便于页面快速查看。',
            'used_memory_rss' => '操作系统视角下 Redis 进程占用的物理内存，常用于判断实际驻留内存。',
            'used_memory_rss_human' => 'used_memory_rss 的可读格式。',
            'used_memory_peak' => 'Redis 启动以来 used_memory 的历史峰值，用于判断是否曾经冲高。',
            'used_memory_peak_human' => 'used_memory_peak 的可读格式。',
            'used_memory_peak_perc' => '当前 used_memory 相对历史峰值的比例，用于判断是否仍处在高位。',
            'used_memory_overhead' => 'Redis 元数据开销，例如 key 字典、过期字典、客户端缓冲等，不含主要数据集。',
            'used_memory_startup' => 'Redis 启动后基础空实例占用的内存。',
            'used_memory_dataset' => '估算的数据集本体内存，通常更接近业务 key/value 占用。',
            'used_memory_dataset_perc' => '数据集本体占可用内存的比例，用于区分业务数据和 Redis 元数据开销。',
            'allocator_allocated' => '内存分配器实际分配给 Redis 的内存。',
            'allocator_active' => '内存分配器已激活的内存页，通常大于 allocator_allocated。',
            'allocator_resident' => '内存分配器驻留在物理内存中的页，常用于分析分配器层面的碎片。',
            'total_system_memory' => 'Redis 所在系统可见的总内存。',
            'total_system_memory_human' => 'total_system_memory 的可读格式。',
            'used_memory_lua' => 'Lua 引擎占用的内存。',
            'used_memory_lua_human' => 'used_memory_lua 的可读格式。',
            'used_memory_scripts' => '缓存脚本占用的内存。',
            'used_memory_scripts_human' => 'used_memory_scripts 的可读格式。',
            'number_of_cached_scripts' => 'Redis 缓存的 Lua 脚本数量。',
            'maxmemory' => 'Redis 配置的最大内存上限；阿里云 1GB 实例通常接近 1073741824。',
            'maxmemory_human' => 'maxmemory 的可读格式。',
            'maxmemory_policy' => '达到 maxmemory 后的淘汰策略，用于判断是否会淘汰带 TTL 的 key。',
            'allocator_frag_ratio' => '分配器活跃内存和已分配内存的比值，偏高表示分配器碎片较多。',
            'allocator_frag_bytes' => '分配器碎片估算字节数。',
            'allocator_rss_ratio' => '分配器驻留内存和活跃内存的比值，偏高表示分配器保留了更多物理页。',
            'allocator_rss_bytes' => '分配器 RSS 开销估算字节数。',
            'rss_overhead_ratio' => 'Redis RSS 与分配器 resident 的比值，用于观察分配器之外的进程内存开销。',
            'rss_overhead_bytes' => 'Redis RSS 中分配器之外的额外开销估算字节数。',
            'mem_fragmentation_ratio' => 'Redis 整体内存碎片率，常用来判断 RSS 明显大于 used_memory 的原因。',
            'mem_fragmentation_bytes' => 'Redis 整体内存碎片估算字节数。',
            'mem_not_counted_for_evict' => '不计入 maxmemory 淘汰判断的内存，例如 AOF、复制积压等缓冲。',
            'mem_replication_backlog' => '主从复制 backlog 占用内存。',
            'mem_clients_slaves' => '从库客户端输出缓冲占用内存，旧版 Redis 字段名。',
            'mem_clients_slaves_human' => 'mem_clients_slaves 的可读格式。',
            'mem_clients_normal' => '普通客户端连接缓冲占用内存，连接数或慢客户端异常时会升高。',
            'mem_clients_normal_human' => 'mem_clients_normal 的可读格式。',
            'mem_cluster_links' => 'Redis Cluster 节点链路占用内存。',
            'mem_cluster_links_human' => 'mem_cluster_links 的可读格式。',
            'mem_aof_buffer' => 'AOF 缓冲占用内存。',
            'mem_allocator' => 'Redis 使用的内存分配器，例如 jemalloc。',
            'mem_overhead_db_hashtable_rehashing' => '数据库 hash 表 rehash 过程中额外占用的内存。',
            'active_defrag_running' => '主动碎片整理是否正在运行。',
            'lazyfree_pending_objects' => '等待异步释放的对象数量，持续升高可能说明释放压力较大。',
            'mem_overhead_hashtable_main' => '主 key 字典 hash 表占用内存。',
            'mem_overhead_hashtable_expires' => '过期时间字典 hash 表占用内存；TTL key 多时会升高。',
            'oom_err_count' => 'Redis 发生 OOM 错误的累计次数，是判断是否触顶的重要信号。',
        ];
    }

    private function renderTopRows(array $rows): string
    {
        if (empty($rows)) {
            return '<div class="queue-diag-empty">暂无数据</div>';
        }

        $html = '<table class="queue-diag-table"><thead><tr><th>名称</th><th>分数</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . $this->escape((string)($row['name'] ?? '-')) . '</td><td>' . $this->escape((string)($row['score'] ?? 0)) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function escapeValue($value): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        return $this->escape((string)$value);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
