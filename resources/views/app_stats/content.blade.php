<style>
    /*
     * 页面沿用 Laravel Admin 的信息密度与基础色，只用左侧状态轨强调运行状态。
     * 所有选择器都限定在 app-stats 下，避免影响其他 PJAX 页面。
     */
    .app-stats { width: 100%; color: #333; }
    .app-stats__toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
    .app-stats__toolbar h2 { margin: 0 0 4px; font-size: 20px; font-weight: 600; }
    .app-stats__toolbar p { margin: 0; color: #666; }
    .app-stats__toolbar-actions { display: flex; align-items: center; gap: 12px; }
    .app-stats__auto-refresh { display: inline-flex; align-items: center; gap: 6px; margin: 0; color: #555; cursor: pointer; font-weight: 400; }
    .app-stats__auto-refresh input { margin: 0; }
    .app-stats__toolbar button:focus-visible,
    .app-stats__auto-refresh input:focus-visible,
    .app-stats details summary:focus-visible { outline: 3px solid #3c8dbc; outline-offset: 2px; }
    .app-stats__cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 16px 0; }
    .app-stats__card,
    .app-stats__section { border: 1px solid #ddd; border-left-width: 4px; border-radius: 4px; background: #fff; padding: 14px; }
    .app-stats__card h3,
    .app-stats__section h3 { margin: 0 0 8px; font-size: 15px; font-weight: 600; }
    .app-stats__card strong { display: block; margin-bottom: 6px; font-size: 22px; line-height: 1.2; }
    .app-stats__status { display: block; font-weight: 600; }
    .app-stats__instance,
    .app-stats__data { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
    .app-stats__instance { display: block; margin-top: 3px; color: #666; font-size: 12px; overflow-wrap: anywhere; }
    .app-stats__pod-identity { border-left-width: 4px !important; }
    .app-stats__pod-identity details { margin-top: 7px; }
    .app-stats__pod-identity summary { color: #3c8dbc; cursor: pointer; font-size: 12px; }
    .app-stats__table-wrap { max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    /* standalone 兼容页不保证加载 Bootstrap，因此表格基础边框不能只依赖管理端主题。 */
    .app-stats__table-wrap table { width: 100%; min-width: 920px; margin-bottom: 0; border-collapse: collapse; }
    .app-stats__table-wrap th,
    .app-stats__table-wrap td { padding: 8px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
    .app-stats__table-wrap th { white-space: nowrap; }
    .app-stats__empty { padding: 28px 16px !important; color: #666; text-align: center; }
    .app-stats__sections { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; margin-top: 16px; }
    .app-stats__metrics { display: grid; grid-template-columns: minmax(150px, 1fr) minmax(100px, 1fr); gap: 5px 12px; margin: 10px 0 0; }
    .app-stats__metrics dt,
    .app-stats__metrics dd { margin: 0; overflow-wrap: anywhere; }
    .app-stats__metrics dt { color: #666; font-weight: 400; }
    .app-stats__metrics dd { text-align: right; }
    .app-stats__message { margin: 8px 0 0; color: #666; }
    .app-stats .status-normal,
    .app-stats .status-online { border-left-color: #00a65a !important; color: #19733e; }
    .app-stats .status-warning,
    .app-stats .status-delayed { border-left-color: #f39c12 !important; color: #8a5800; }
    .app-stats .status-error,
    .app-stats .status-expired { border-left-color: #dd4b39 !important; color: #9d2618; }
    .app-stats .status-waiting,
    .app-stats .status-disabled,
    .app-stats .status-unavailable { border-left-color: #777 !important; color: #555; }

    @media (max-width: 767px) {
        .app-stats__toolbar { align-items: stretch; flex-direction: column; }
        .app-stats__toolbar-actions { align-items: flex-start; flex-wrap: wrap; }
        .app-stats__cards { grid-template-columns: 1fr; }
        .app-stats__sections { grid-template-columns: 1fr; }
    }
</style>

@if($mode === 'admin')
    <section class="app-stats" data-mode="admin" data-url="{{ $dataUrl ?? '' }}">
        <header class="app-stats__toolbar">
            <div>
                <h2>全集群运行状态</h2>
                <p id="app-stats-refresh-state" aria-live="polite">等待首次刷新；自动刷新已关闭</p>
            </div>
            @if($autoRefresh)
                <div class="app-stats__toolbar-actions">
                    {{--
                        自动刷新默认关闭，避免用户查看展开指标时页面持续变动，也避免监控页在后台
                        长时间产生无必要请求。该选择只影响当前页面生命周期，不写 Cookie 或 Redis。
                    --}}
                    <label class="app-stats__auto-refresh" for="app-stats-auto-refresh">
                        <input type="checkbox" id="app-stats-auto-refresh" aria-describedby="app-stats-refresh-state">
                        <span>自动刷新</span>
                    </label>
                    <button type="button" id="app-stats-refresh" class="btn btn-primary">立即刷新</button>
                </div>
            @endif
        </header>

        <div id="app-stats-alert" class="alert alert-warning" hidden role="status" aria-live="polite"></div>
        <div id="app-stats-summary" class="app-stats__cards" aria-label="集群摘要"></div>

        <div class="app-stats__table-wrap">
            <table class="table table-bordered table-striped">
                <caption class="sr-only">在线与延迟 Pod 的最新运行状态</caption>
                <thead>
                <tr>
                    <th scope="col">Pod / instance ID</th>
                    <th scope="col">状态</th>
                    <th scope="col">样本年龄</th>
                    <th scope="col">Worker</th>
                    <th scope="col">Task Worker</th>
                    <th scope="col">Tasking</th>
                    <th scope="col">连接数</th>
                    <th scope="col">启动时间</th>
                </tr>
                </thead>
                <tbody id="app-stats-pods"></tbody>
            </table>
        </div>

        <div id="app-stats-sections" class="app-stats__sections" aria-label="全局运行区块"></div>
    </section>

    <script>
        (function () {
            'use strict';

            // Laravel Admin 使用 PJAX 替换内容。同一任务重复进入页面时，先销毁旧实例，
            // 防止保留旧 interval、请求或 document 事件监听并造成重复刷新。
            if (window.easyToolAppStats && typeof window.easyToolAppStats.destroy === 'function') {
                window.easyToolAppStats.destroy();
            }

            const root = document.querySelector('.app-stats[data-mode="admin"]');
            if (!root) {
                return;
            }

            const dataUrl = root.dataset.url;
            const showRedisMemory = @json((bool) $showRedisMemory);
            const refreshButton = document.getElementById('app-stats-refresh');
            const autoRefreshToggle = document.getElementById('app-stats-auto-refresh');
            const refreshState = document.getElementById('app-stats-refresh-state');
            const alertBox = document.getElementById('app-stats-alert');
            const summaryRoot = document.getElementById('app-stats-summary');
            const podsRoot = document.getElementById('app-stats-pods');
            const sectionsRoot = document.getElementById('app-stats-sections');
            let timer = null;
            let controller = null;
            let lastSuccessAt = null;
            let destroyed = false;
            let refreshMilliseconds = 5000;
            // 自动刷新是一次页面会话内的显式选择，默认关闭且不写入持久化存储。
            // 用户下次进入监控页时仍需主动开启，避免长期占用浏览器与服务端资源。
            let autoRefreshEnabled = false;
            // 每个 Pod 的 instance_id 在一次进程生命周期内唯一。以它记录详情展开状态，
            // 即使刷新重新创建整行 DOM，也不会把用户正在查看的完整指标收回去。
            const expandedPodIds = new Set();

            // 动态数据一律通过 textContent 写入新建 DOM 节点，不把 API 值解释为标记文本。
            const text = (tag, value, className) => {
                const node = document.createElement(tag);
                node.textContent = value == null ? '未知' : String(value);
                if (className) {
                    node.className = className;
                }
                return node;
            };

            const statusKey = (status) => {
                const allowed = [
                    'normal', 'online', 'warning', 'delayed', 'error', 'expired',
                    'waiting', 'disabled', 'unavailable'
                ];

                return allowed.includes(status) ? status : 'unavailable';
            };

            const statusLabel = (status) => ({
                normal: '正常',
                online: '在线',
                warning: '告警',
                delayed: '延迟',
                error: '错误',
                expired: '过期',
                waiting: '等待采样',
                disabled: '未启用',
                unavailable: '不可用'
            }[statusKey(status)]);

            const finiteNumber = (value) => value !== null
                && value !== ''
                && Number.isFinite(Number(value));

            const ratio = (used, total) => {
                if (!finiteNumber(used) || !finiteNumber(total)) {
                    return '未知';
                }

                return Number(used) + ' / ' + Number(total);
            };

            const bytes = (value) => {
                if (!finiteNumber(value)) {
                    return '未知';
                }

                const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
                let size = Number(value);
                let unit = 0;
                while (size >= 1024 && unit < units.length - 1) {
                    size /= 1024;
                    unit++;
                }

                return size.toFixed(unit === 0 ? 0 : 1) + ' ' + units[unit];
            };

            const formatTimestamp = (value) => {
                if (value === null || value === undefined || value === '') {
                    return '未知';
                }

                const date = typeof value === 'number'
                    ? new Date(value * 1000)
                    : new Date(value);

                return Number.isNaN(date.getTime()) ? '未知' : date.toLocaleString();
            };

            const metricLabels = {
                used_memory: '已用内存（used_memory）',
                used_memory_rss: '常驻内存（used_memory_rss）',
                maxmemory: '内存上限（maxmemory）',
                used_percent: '内存使用率（used_percent）',
                mem_fragmentation_ratio: '内存碎片率（mem_fragmentation_ratio）',
                maxmemory_policy: '淘汰策略（maxmemory_policy）',
                evicted_keys: '累计淘汰 Key（evicted_keys）',
                evicted_keys_delta: '本次淘汰增量（evicted_keys_delta）',
                rejected_connections: '拒绝连接（rejected_connections）',
                stream_total: 'Stream 当前长度（stream_total）',
                pending: '待确认消息（pending）',
                consumed_retained: '已确认但仍保留（consumed_retained）',
                consumers: '消费者数量（consumers）',
                last_delivered_id: '最后投递 ID（last_delivered_id）',
                dead_stream_total: '预留 Dead Stream 长度（dead_stream_total）',
                dead_stream_enabled: 'Dead Stream 自动写入（dead_stream_enabled）'
            };

            const metricValue = (key, value) => {
                if (value === null || value === undefined) {
                    return '未知';
                }
                if (['used_memory', 'used_memory_rss', 'maxmemory'].includes(key)) {
                    return bytes(value);
                }
                if (key === 'used_percent') {
                    return finiteNumber(value) ? Number(value).toFixed(2) + '%' : '未知';
                }
                if (typeof value === 'boolean') {
                    return value ? '是' : '否';
                }
                if (typeof value === 'object') {
                    return JSON.stringify(value);
                }

                return String(value);
            };

            const metricList = (metrics) => {
                const list = document.createElement('dl');
                list.className = 'app-stats__metrics';
                Object.entries(metrics && typeof metrics === 'object' ? metrics : {}).forEach(([key, value]) => {
                    list.append(text('dt', metricLabels[key] || key));
                    list.append(text('dd', metricValue(key, value), 'app-stats__data'));
                });

                return list;
            };

            const summaryCard = (title, value, status) => {
                const safeStatus = statusKey(status);
                const card = document.createElement('article');
                card.className = 'app-stats__card status-' + safeStatus;
                card.append(text('h3', title));
                card.append(text('strong', value, 'app-stats__data'));
                card.append(text('span', statusLabel(safeStatus), 'app-stats__status'));

                return card;
            };

            const renderSummary = (clusterValue, summaryValue, sectionsValue) => {
                const cluster = clusterValue || {};
                const summary = summaryValue || {};
                const sections = sectionsValue || {};
                const redis = sections.redis_memory || {status: 'unavailable', metrics: {}};
                const mqtt = sections.mqtt_publish_stream || {status: 'unavailable', metrics: {}};
                const podCount = finiteNumber(cluster.online_pods) && finiteNumber(cluster.delayed_pods)
                    ? Number(cluster.online_pods) + ' 在线 / ' + Number(cluster.delayed_pods) + ' 延迟'
                    : '未知';
                const tasking = finiteNumber(summary.tasking_num) ? Number(summary.tasking_num) : '未知';
                const redisPercent = finiteNumber(redis.metrics && redis.metrics.used_percent)
                    ? Number(redis.metrics.used_percent).toFixed(2) + '%'
                    : '未知';
                const pending = finiteNumber(mqtt.metrics && mqtt.metrics.pending)
                    ? Number(mqtt.metrics.pending)
                    : '未知';

                const cards = [
                    summaryCard('Pod', podCount, cluster.status),
                    summaryCard('Worker', ratio(summary.worker_used, summary.worker_total), cluster.status),
                    summaryCard(
                        'Task Worker',
                        ratio(summary.task_worker_used, summary.task_worker_total) + '，Tasking ' + tasking,
                        cluster.status
                    ),
                    summaryCard('Redis 内存', redisPercent, redis.status),
                    summaryCard('MQTT Pending', pending, mqtt.status)
                ];

                summaryRoot.replaceChildren(...cards);
            };

            const renderPods = (pods) => {
                if (!Array.isArray(pods) || pods.length === 0) {
                    const row = document.createElement('tr');
                    const cell = text('td', '暂无在线或延迟 Pod 数据', 'app-stats__empty');
                    cell.colSpan = 8;
                    row.append(cell);
                    podsRoot.replaceChildren(row);
                    return;
                }

                const rows = pods.map((podValue) => {
                    const pod = podValue || {};
                    const metrics = pod.metrics || {};
                    const safeStatus = statusKey(pod.status);
                    const row = document.createElement('tr');
                    const identity = document.createElement('td');
                    identity.className = 'app-stats__pod-identity status-' + safeStatus;
                    identity.append(text('strong', pod.hostname));
                    identity.append(text('small', pod.instance_id || '未知实例', 'app-stats__instance'));

                    const details = document.createElement('details');
                    const podIdentity = String(pod.instance_id || pod.hostname || 'unknown-pod');
                    details.dataset.podId = podIdentity;
                    details.open = expandedPodIds.has(podIdentity);
                    details.addEventListener('toggle', () => {
                        if (details.open) {
                            expandedPodIds.add(podIdentity);
                        } else {
                            expandedPodIds.delete(podIdentity);
                        }
                    });
                    details.append(text('summary', '查看 Swoole / SkyLab 完整指标'));
                    const detailMetrics = document.createElement('div');
                    detailMetrics.append(metricList(pod.stats || {}));
                    detailMetrics.append(metricList(pod.skylab_tcp || {}));
                    details.append(detailMetrics);
                    identity.append(details);

                    row.append(identity);
                    row.append(text('td', statusLabel(safeStatus), 'status-' + safeStatus));
                    row.append(text(
                        'td',
                        finiteNumber(pod.sample_age_seconds) ? Number(pod.sample_age_seconds) + ' 秒' : '未知',
                        'app-stats__data'
                    ));
                    row.append(text('td', ratio(metrics.worker_used, metrics.worker_total), 'app-stats__data'));
                    row.append(text('td', ratio(metrics.task_worker_used, metrics.task_worker_total), 'app-stats__data'));
                    row.append(text('td', finiteNumber(metrics.tasking_num) ? Number(metrics.tasking_num) : '未知', 'app-stats__data'));
                    row.append(text('td', finiteNumber(metrics.connection_num) ? Number(metrics.connection_num) : '未知', 'app-stats__data'));
                    row.append(text('td', formatTimestamp(metrics.start_time)));

                    return row;
                });

                podsRoot.replaceChildren(...rows);
            };

            const messageLabel = (message) => ({
                pod_store_read_failed: 'Pod 状态读取失败',
                section_read_failed: '区块状态读取失败',
                redis_memory_monitor_disabled: 'Redis 内存监控未启用',
                redis_memory_waiting_for_first_sample: '等待 Redis 内存首次采样',
                redis_memory_waiting_for_complete_sample: '等待下一次完整 Redis 内存采样',
                redis_memory_sample_delayed: 'Redis 内存采样延迟',
                redis_memory_state_read_failed: 'Redis 内存状态读取失败',
                mqtt_publish_stream_read_failed: 'MQTT Publish Stream 状态读取失败'
            }[message] || '运行状态异常');

            const renderSections = (sectionsValue) => {
                const sections = sectionsValue && typeof sectionsValue === 'object' ? sectionsValue : {};
                const entries = Object.entries(sections)
                    .filter(([key]) => showRedisMemory || key !== 'redis_memory');

                if (entries.length === 0) {
                    const empty = document.createElement('article');
                    empty.className = 'app-stats__section status-unavailable';
                    empty.append(text('h3', '全局运行区块'));
                    empty.append(text('p', '暂无可展示数据'));
                    sectionsRoot.replaceChildren(empty);
                    return;
                }

                const cards = entries.map(([key, sectionValue]) => {
                    const section = sectionValue || {};
                    const safeStatus = statusKey(section.status);
                    const card = document.createElement('article');
                    card.className = 'app-stats__section status-' + safeStatus;
                    card.append(text('h3', section.title || key));
                    card.append(text('p', statusLabel(safeStatus), 'app-stats__status'));
                    if (section.sampled_at) {
                        card.append(text('p', '采样时间：' + formatTimestamp(section.sampled_at)));
                    }
                    if (section.message) {
                        card.append(text('p', messageLabel(section.message), 'app-stats__message'));
                    }
                    card.append(metricList(section.metrics || {}));

                    return card;
                });

                sectionsRoot.replaceChildren(...cards);
            };

            const render = (payload) => {
                renderSummary(payload.cluster, payload.summary, payload.sections);
                renderPods(payload.pods);
                renderSections(payload.sections);
                lastSuccessAt = new Date();
                refreshState.textContent = '最近刷新：' + lastSuccessAt.toLocaleTimeString()
                    + (autoRefreshEnabled ? '；自动刷新已开启' : '；自动刷新已关闭');
                // HTTP 成功不代表所有数据源成功。Pod store 失败会通过 cluster.message 返回，
                // 必须在页面内明确提示，不能只剩一条红色状态轨让用户猜测原因。
                const clusterMessage = payload.cluster && payload.cluster.message;
                if (clusterMessage) {
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = messageLabel(clusterMessage);
                    alertBox.hidden = false;
                } else {
                    alertBox.hidden = true;
                }
            };

            const setPollingInterval = (seconds) => {
                const nextMilliseconds = Math.max(1, Number(seconds) || 5) * 1000;
                const intervalUnchanged = nextMilliseconds === refreshMilliseconds;
                refreshMilliseconds = nextMilliseconds;

                // 关闭开关时只保存服务端返回的推荐间隔，不创建定时器。下次开启时直接
                // 使用最新间隔；这也保证首次页面加载只请求一次，不会暗中开始轮询。
                if (!autoRefreshEnabled) {
                    if (timer !== null) {
                        window.clearInterval(timer);
                        timer = null;
                    }
                    return;
                }

                if (timer !== null && intervalUnchanged) {
                    return;
                }
                if (timer !== null) {
                    window.clearInterval(timer);
                }
                timer = window.setInterval(refresh, refreshMilliseconds);
            };

            const refresh = async () => {
                if (destroyed || document.hidden) {
                    return;
                }

                // 手动刷新、恢复可见或 interval 同时触发时，取消上一请求，始终只保留最新响应。
                if (controller) {
                    controller.abort();
                }
                const requestController = new AbortController();
                controller = requestController;
                if (refreshButton) {
                    refreshButton.disabled = true;
                }
                refreshState.textContent = lastSuccessAt
                    ? '正在刷新；上次成功：' + lastSuccessAt.toLocaleTimeString()
                    : '正在获取集群状态';

                try {
                    const response = await fetch(dataUrl, {
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        signal: requestController.signal
                    });
                    if (!response.ok) {
                        throw new Error('app_stats_request_failed');
                    }

                    const payload = await response.json();
                    if (destroyed || requestController !== controller) {
                        return;
                    }
                    render(payload);
                    setPollingInterval(payload.refresh_seconds);
                } catch (error) {
                    if (error.name !== 'AbortError' && !destroyed) {
                        const message = lastSuccessAt
                            ? '刷新失败，继续显示最近一次成功数据。上次成功：' + lastSuccessAt.toLocaleTimeString()
                            : '刷新失败，暂时没有可展示的数据。';
                        alertBox.className = 'alert alert-warning';
                        alertBox.textContent = message;
                        alertBox.hidden = false;
                        refreshState.textContent = message;
                    }
                } finally {
                    if (requestController === controller && refreshButton) {
                        refreshButton.disabled = false;
                    }
                }
            };

            const onRefreshClick = () => refresh();
            const onAutoRefreshChange = () => {
                autoRefreshEnabled = Boolean(autoRefreshToggle && autoRefreshToggle.checked);
                setPollingInterval(refreshMilliseconds / 1000);
                refreshState.textContent = lastSuccessAt
                    ? '最近刷新：' + lastSuccessAt.toLocaleTimeString()
                        + (autoRefreshEnabled ? '；自动刷新已开启' : '；自动刷新已关闭')
                    : (autoRefreshEnabled ? '等待首次刷新；自动刷新已开启' : '等待首次刷新；自动刷新已关闭');
            };
            const onVisibilityChange = () => {
                if (document.hidden) {
                    if (controller) {
                        controller.abort();
                    }
                    if (autoRefreshEnabled) {
                        refreshState.textContent = lastSuccessAt
                            ? '页面不可见，自动刷新已暂停；上次成功：' + lastSuccessAt.toLocaleTimeString()
                            : '页面不可见，自动刷新已暂停';
                    }
                    return;
                }

                // 默认关闭时，切回浏览器标签页不能被当成一次隐式刷新；只有用户明确
                // 开启自动刷新后，恢复可见才立即补取一次最新状态。
                if (autoRefreshEnabled) {
                    refresh();
                }
            };

            const api = {
                destroy: () => {
                    if (destroyed) {
                        return;
                    }
                    destroyed = true;
                    if (timer !== null) {
                        window.clearInterval(timer);
                        timer = null;
                    }
                    if (controller) {
                        controller.abort();
                        controller = null;
                    }
                    if (refreshButton) {
                        refreshButton.removeEventListener('click', onRefreshClick);
                    }
                    if (autoRefreshToggle) {
                        autoRefreshToggle.removeEventListener('change', onAutoRefreshChange);
                    }
                    document.removeEventListener('visibilitychange', onVisibilityChange);
                    document.removeEventListener('pjax:beforeReplace', onPjaxBeforeReplace);
                    if (window.easyToolAppStats === api) {
                        window.easyToolAppStats = null;
                    }
                }
            };
            const onPjaxBeforeReplace = () => api.destroy();

            if (refreshButton) {
                refreshButton.addEventListener('click', onRefreshClick);
            }
            if (autoRefreshToggle) {
                autoRefreshToggle.checked = false;
                autoRefreshToggle.addEventListener('change', onAutoRefreshChange);
            }
            document.addEventListener('visibilitychange', onVisibilityChange);
            document.addEventListener('pjax:beforeReplace', onPjaxBeforeReplace, {once: true});
            window.easyToolAppStats = api;

            // 首屏始终主动请求一次，让页面有可读数据；后续是否继续轮询完全由自动刷新
            // 开关决定。首次失败时也不自动重试，用户可手动刷新或主动开启自动刷新。
            refresh();
        })();
    </script>
@else
    {{--
        旧公开入口只渲染控制器明确提供的兼容指标；Blade 的双花括号会统一转义动态值，
        不加载 AppStats 数据接口，也不暴露 Redis 内存区块或管理端权限信息。
    --}}
    <section class="app-stats app-stats--compatibility" data-mode="compatibility">
        <h1>{{ $compatibilityTitle }}</h1>
        @foreach($compatibilityGroups as $group)
            <article class="app-stats__section">
                <h2>{{ $group['title'] }}</h2>
                <div class="app-stats__table-wrap">
                    <table class="table table-bordered table-striped">
                        <thead>
                        <tr><th scope="col">指标</th><th scope="col">值</th></tr>
                        </thead>
                        <tbody>
                        @forelse($group['metrics'] as $name => $value)
                            <tr>
                                <td>{{ $name }}</td>
                                <td>{{ is_scalar($value) || $value === null ? ($value ?? '未知') : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2">暂无数据</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        @endforeach
    </section>
@endif
