<style>
    .location-ingress-counter { color: #333; }
    .location-ingress-counter__toolbar { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
    .location-ingress-counter__toolbar p { margin: 4px 0 0; color: #666; }
    .location-ingress-counter__actions { display: flex; align-items: center; gap: 10px; }
    .location-ingress-counter__actions select { height: 34px; }
    .location-ingress-counter__auto { margin: 0; color: #555; font-weight: normal; }
    .location-ingress-counter__notice { margin-bottom: 14px; padding: 10px 12px; border-left: 4px solid #3c8dbc; background: #f5f9fc; }
    .location-ingress-counter__section { margin-bottom: 16px; padding: 14px; border: 1px solid #ddd; border-left: 4px solid #3c8dbc; border-radius: 3px; background: #fff; }
    .location-ingress-counter__section h3 { margin: 0 0 7px; font-size: 16px; }
    .location-ingress-counter__section p { margin: 0 0 12px; color: #666; }
    .location-ingress-counter__table-wrap { overflow-x: auto; }
    .location-ingress-counter table { width: 100%; min-width: 650px; border-collapse: collapse; }
    .location-ingress-counter th, .location-ingress-counter td { padding: 8px; border: 1px solid #ddd; text-align: left; }
    .location-ingress-counter th { white-space: nowrap; background: #fafafa; }
    .location-ingress-counter__empty { color: #777; }
    .location-ingress-counter__status { margin: 0; font-weight: 600; }
    .location-ingress-counter__detail { margin-top: 10px; padding: 10px; border: 1px dashed #bbb; background: #fafafa; white-space: pre-wrap; }
</style>

@php $canTraceDetails = (bool) ($canTraceDetails ?? false); @endphp

<section
    class="location-ingress-counter"
    data-url="{{ $dataUrl }}"
    @if ($canTraceDetails)
        data-trace-list-url="{{ $traceListUrl }}"
        data-trace-detail-url-template="{{ $traceDetailUrlTemplate }}"
    @endif
>
    <header class="location-ingress-counter__toolbar">
        <div>
            <h2 style="margin:0; font-size:20px;">入口量、业务阶段与短期样本</h2>
            <p id="location-ingress-counter-status" class="location-ingress-counter__status">等待手动刷新。</p>
        </div>
        <div class="location-ingress-counter__actions">
            <label for="location-ingress-counter-minutes">窗口</label>
            <select id="location-ingress-counter-minutes">
                <option value="1">最近 1 分钟</option>
                <option value="5">最近 5 分钟</option>
                <option value="10" selected>最近 10 分钟</option>
                <option value="30">最近 30 分钟</option>
            </select>
            <label class="location-ingress-counter__auto">
                <input id="location-ingress-counter-auto" type="checkbox"> 当前页每 10 秒刷新
            </label>
            <button id="location-ingress-counter-refresh" type="button" class="btn btn-primary">立即刷新</button>
        </div>
    </header>

    <div id="location-ingress-counter-notice" class="location-ingress-counter__notice">
        计数和诊断模式由部署配置维护，修改后需人工重启 LaravelS 与 Horizon；此页面只读展示当前进程数据。
    </div>

    <section class="location-ingress-counter__section">
        <h3>原始入口消息（received）</h3>
        <p>一条 MQTT/TCP/HTTP/UDP/mock 原始消息只计一次，用于比较真实设备流量与模拟流量。</p>
        <div class="location-ingress-counter__table-wrap">
            <table>
                <thead><tr><th>来源</th><th>传输</th><th>接入方案</th><th>消息数</th></tr></thead>
                <tbody id="location-ingress-counter-received"><tr><td class="location-ingress-counter__empty" colspan="4">尚未读取。</td></tr></tbody>
            </table>
        </div>
    </section>

    <section class="location-ingress-counter__section">
        <h3>项目归属记录（accepted）</h3>
        <p>已取得 subject_id 的标准化定位记录。一条聚合网关包可能对应多条记录，不能视为原始包数。</p>
        <div class="location-ingress-counter__table-wrap">
            <table>
                <thead><tr><th>来源</th><th>传输</th><th>接入方案</th><th>项目 ID</th><th>记录数</th></tr></thead>
                <tbody id="location-ingress-counter-accepted"><tr><td class="location-ingress-counter__empty" colspan="5">尚未读取。</td></tr></tbody>
            </table>
        </div>
    </section>

    <section class="location-ingress-counter__section">
        <h3>业务阶段漏斗与累计延迟</h3>
        <p>上报至该阶段耗时是从入口到当前阶段的累计耗时，不是该阶段独占执行时间。P50/P95 为固定 bucket 的近似上界。</p>
        <div class="location-ingress-counter__table-wrap">
            <table>
                <thead><tr><th>来源</th><th>传输</th><th>接入方案</th><th>阶段</th><th>结果</th><th>数量</th><th>平均 ms</th><th>最大 ms</th><th>P50 ms</th><th>P95 ms</th></tr></thead>
                <tbody id="location-ingress-diagnostics-stages"><tr><td class="location-ingress-counter__empty" colspan="10">尚未读取。</td></tr></tbody>
            </table>
        </div>
    </section>

    @if ($canTraceDetails)
        <section class="location-ingress-counter__section">
            <h3>近期诊断样本</h3>
            <p>trace 为有界短期样本；未采样上报的 error/drop 可能只含终点事件。</p>
            <div class="location-ingress-counter__actions">
                <select id="location-ingress-trace-origin"><option value="external">external</option><option value="mock">mock</option><option value="unknown">unknown</option></select>
                <select id="location-ingress-trace-transport"><option value="tcp">tcp</option><option value="mqtt">mqtt</option><option value="http">http</option><option value="udp">udp</option><option value="direct">direct</option><option value="unknown">unknown</option></select>
                <select id="location-ingress-trace-solution"><option value="skylab">skylab</option><option value="skylab_mqtt">skylab_mqtt</option><option value="skylab_uwb">skylab_uwb</option><option value="ylwl_gateway">ylwl_gateway</option><option value="huawei_ap_ble">huawei_ap_ble</option><option value="luojie_wristband_new">luojie_wristband_new</option><option value="bolian_lora">bolian_lora</option><option value="w_b_mapper">w_b_mapper</option><option value="lance_aoa">lance_aoa</option><option value="lance_aoa_mqtt">lance_aoa_mqtt</option><option value="hytera">hytera</option><option value="hytera_dmr">hytera_dmr</option><option value="lierda_lora">lierda_lora</option><option value="ovi_b2315_lora">ovi_b2315_lora</option><option value="unknown">unknown</option></select>
                <button id="location-ingress-trace-refresh" type="button" class="btn btn-default">读取样本</button>
            </div>
            <div class="location-ingress-counter__table-wrap">
                <table>
                    <thead><tr><th>时间</th><th>方案</th><th>阶段</th><th>结果</th><th>累计 ms</th><th>原因</th><th>操作</th></tr></thead>
                    <tbody id="location-ingress-traces"><tr><td class="location-ingress-counter__empty" colspan="7">等待手动刷新。</td></tr></tbody>
                </table>
            </div>
            <pre id="location-ingress-trace-detail" class="location-ingress-counter__detail" hidden></pre>
        </section>
    @endif
</section>

<script>
    (function () {
        const root = document.querySelector('.location-ingress-counter');
        if (!root || root.dataset.initialized === 'true') return;
        root.dataset.initialized = 'true';

        const dataUrl = root.dataset.url;
        const traceListUrl = root.dataset.traceListUrl;
        const traceDetailUrlTemplate = root.dataset.traceDetailUrlTemplate;
        const minutes = root.querySelector('#location-ingress-counter-minutes');
        const auto = root.querySelector('#location-ingress-counter-auto');
        const refresh = root.querySelector('#location-ingress-counter-refresh');
        const status = root.querySelector('#location-ingress-counter-status');
        const notice = root.querySelector('#location-ingress-counter-notice');
        const received = root.querySelector('#location-ingress-counter-received');
        const accepted = root.querySelector('#location-ingress-counter-accepted');
        const stages = root.querySelector('#location-ingress-diagnostics-stages');
        const traceOrigin = root.querySelector('#location-ingress-trace-origin');
        const traceTransport = root.querySelector('#location-ingress-trace-transport');
        const traceSolution = root.querySelector('#location-ingress-trace-solution');
        const traceRefresh = root.querySelector('#location-ingress-trace-refresh');
        const traces = root.querySelector('#location-ingress-traces');
        const traceDetail = root.querySelector('#location-ingress-trace-detail');
        let timer = null;
        let controller = null;
        let traceController = null;

        const escapeHtml = function (value) {
            return String(value ?? '').replace(/[&<>'"]/g, function (character) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character];
            });
        };
        const renderRows = function (target, rows, fields, colspan) {
            if (!rows.length) {
                target.innerHTML = '<tr><td class="location-ingress-counter__empty" colspan="' + colspan + '">此窗口内没有计数。</td></tr>';
                return;
            }
            target.innerHTML = rows.map(function (row) {
                return '<tr>' + fields.map(function (field) {
                    return '<td>' + escapeHtml(row[field] ?? '-') + '</td>';
                }).join('') + '</tr>';
            }).join('');
        };
        const load = async function () {
            if (!dataUrl) return;
            if (controller) controller.abort();
            controller = new AbortController();
            refresh.disabled = true;
            status.textContent = '正在读取 Redis 分钟 bucket…';
            try {
                const response = await fetch(dataUrl + '?minutes=' + encodeURIComponent(minutes.value), {
                    headers: { 'Accept': 'application/json' },
                    signal: controller.signal
                });
                if (!response.ok) throw new Error('request_failed');
                const payload = await response.json();
                const ingressRows = Array.isArray(payload.ingress_rows) ? payload.ingress_rows : [];
                const stageRows = Array.isArray(payload.stage_rows) ? payload.stage_rows : [];
                renderRows(received, ingressRows.filter(function (row) { return row.stage === 'received'; }), ['origin', 'transport', 'location_solution', 'count'], 4);
                renderRows(accepted, ingressRows.filter(function (row) { return row.stage === 'accepted'; }), ['origin', 'transport', 'location_solution', 'subject_id', 'count'], 5);
                renderRows(stages, stageRows, ['origin', 'transport', 'location_solution', 'stage', 'outcome', 'count', 'average_ms', 'elapsed_max_ms', 'p50_ms', 'p95_ms'], 10);
                const diagnosticsMode = escapeHtml(payload.diagnostics_mode ?? 'off');
                notice.textContent = '当前进程入口计数状态：' + (payload.ingress_counter_enabled === true ? '已开启' : '已关闭')
                    + '；诊断模式：' + diagnosticsMode + '。开关由部署配置维护，页面只读。';
                status.textContent = '已读取最近 ' + payload.minutes + ' 分钟，共 ' + ingressRows.length + ' 个入口维度和 ' + stageRows.length + ' 个阶段维度。';
                if (traceListUrl) loadTraces();
            } catch (error) {
                if (error.name !== 'AbortError') status.textContent = '读取失败：Redis 或调试页面暂不可用。';
            } finally {
                refresh.disabled = false;
            }
        };
        const renderTraceRows = function (rows) {
            if (!traces) return;
            if (!rows.length) {
                traces.innerHTML = '<tr><td class="location-ingress-counter__empty" colspan="7">此范围内没有短期样本。</td></tr>';
                return;
            }
            traces.innerHTML = rows.map(function (row) {
                const traceId = String(row.trace_id ?? '');
                const detailButton = traceDetailUrlTemplate && traceId
                    ? '<button type="button" class="btn btn-xs btn-default" data-trace-id="' + escapeHtml(traceId) + '">详情</button>'
                    : '-';

                return '<tr><td>' + escapeHtml(row.created_at ?? '-') + '</td><td>' +
                    escapeHtml(row.location_solution ?? '-') + '</td><td>' +
                    escapeHtml(row.stage ?? '-') + '</td><td>' +
                    escapeHtml(row.outcome ?? '-') + '</td><td>' +
                    escapeHtml(row.elapsed_ms ?? '-') + '</td><td>' +
                    escapeHtml(row.reason ?? '-') + '</td><td>' + detailButton + '</td></tr>';
            }).join('');
        };
        const loadTraces = async function () {
            if (!traceListUrl || !traces || !traceOrigin || !traceTransport || !traceSolution) return;
            if (traceController) traceController.abort();
            traceController = new AbortController();
            const query = '?origin=' + encodeURIComponent(traceOrigin.value) +
                '&transport=' + encodeURIComponent(traceTransport.value) +
                '&location_solution=' + encodeURIComponent(traceSolution.value) + '&limit=20';
            try {
                const response = await fetch(traceListUrl + query, {
                    headers: { 'Accept': 'application/json' },
                    signal: traceController.signal
                });
                if (!response.ok) throw new Error('request_failed');
                const payload = await response.json();
                renderTraceRows(Array.isArray(payload.rows) ? payload.rows : []);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    traces.innerHTML = '<tr><td class="location-ingress-counter__empty" colspan="7">样本读取失败。</td></tr>';
                }
            }
        };
        const loadTraceDetail = async function (traceId) {
            if (!traceDetail || !traceDetailUrlTemplate) return;
            const url = traceDetailUrlTemplate.replace('__trace_id__', encodeURIComponent(traceId));
            try {
                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) throw new Error('request_failed');
                const payload = await response.json();
                traceDetail.textContent = JSON.stringify(payload, null, 2);
                traceDetail.hidden = false;
            } catch (error) {
                traceDetail.textContent = '样本详情读取失败。';
                traceDetail.hidden = false;
            }
        };
        const syncTimer = function () {
            if (timer) clearInterval(timer);
            timer = auto.checked ? setInterval(load, 10000) : null;
        };
        refresh.addEventListener('click', load);
        minutes.addEventListener('change', load);
        auto.addEventListener('change', syncTimer);
        if (traceRefresh) traceRefresh.addEventListener('click', loadTraces);
        if (traces) traces.addEventListener('click', function (event) {
            const button = event.target.closest('[data-trace-id]');
            if (button) loadTraceDetail(button.dataset.traceId);
        });
        document.addEventListener('pjax:beforeReplace', function () {
            if (timer) clearInterval(timer);
            if (controller) controller.abort();
            if (traceController) traceController.abort();
        }, { once: true });
    }());
</script>
