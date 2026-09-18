<style>
    .location-ingress-counter { color: #333; }
    .location-ingress-counter__toolbar { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
    .location-ingress-counter__toolbar p { margin: 4px 0 0; color: #666; }
    .location-ingress-counter__actions { display: flex; align-items: center; gap: 10px; }
    .location-ingress-counter__actions select { height: 34px; }
    .location-ingress-counter__auto { margin: 0; color: #555; font-weight: normal; }
    .location-ingress-counter__notice { margin-bottom: 14px; padding: 10px 12px; border-left: 4px solid #3c8dbc; background: #f5f9fc; }
    .location-ingress-counter__notice--disabled { border-left-color: #f39c12; background: #fffbf2; }
    .location-ingress-counter__section { margin-bottom: 16px; padding: 14px; border: 1px solid #ddd; border-left: 4px solid #3c8dbc; border-radius: 3px; background: #fff; }
    .location-ingress-counter__section h3 { margin: 0 0 7px; font-size: 16px; }
    .location-ingress-counter__section p { margin: 0 0 12px; color: #666; }
    .location-ingress-counter__switch { border-left-color: #f39c12; }
    .location-ingress-counter__switch-state { margin: 0 0 8px; }
    .location-ingress-counter__switch-result { min-height: 20px; margin: 10px 0 0; color: #666; }
    .location-ingress-counter__switch-result--error { color: #dd4b39; }
    .location-ingress-counter__table-wrap { overflow-x: auto; }
    .location-ingress-counter table { width: 100%; min-width: 650px; border-collapse: collapse; }
    .location-ingress-counter th, .location-ingress-counter td { padding: 8px; border: 1px solid #ddd; text-align: left; }
    .location-ingress-counter th { white-space: nowrap; background: #fafafa; }
    .location-ingress-counter__empty { color: #777; }
    .location-ingress-counter__status { margin: 0; font-weight: 600; }
    .location-ingress-counter__detail { margin-top: 10px; padding: 10px; border: 1px dashed #bbb; background: #fafafa; white-space: pre-wrap; }
</style>

@php
    $effectiveEnabled = (bool) ($switchState['effective_enabled'] ?? false);
    $requestedEnabled = ($switchState['configured'] ?? false)
        ? (bool) ($switchState['requested_enabled'] ?? false)
        : null;
    $switchTarget = ($requestedEnabled ?? $effectiveEnabled) ? '0' : '1';
    $effectiveMode = (string) (($diagnosticsModeState ?? [])['effective_mode'] ?? 'off');
    $requestedMode = (($diagnosticsModeState ?? [])['configured'] ?? false)
        ? (string) (($diagnosticsModeState ?? [])['requested_mode'] ?? 'off')
        : null;
    $canTraceDetails = (bool) ($canTraceDetails ?? false);
@endphp

<section
    class="location-ingress-counter"
    data-url="{{ $dataUrl }}"
    data-switch-url="{{ $switchUrl }}"
    data-mode-switch-url="{{ $modeSwitchUrl ?? '' }}"
    data-csrf-token="{{ $csrfToken }}"
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
        仅调试期启用计数。关闭时不会写 Redis；页面仍可能显示尚未过期的历史 bucket。
    </div>

    <section class="location-ingress-counter__section location-ingress-counter__switch">
        <h3>统计开关</h3>
        <p class="location-ingress-counter__switch-state">
            当前处理请求的进程：
            <strong id="location-ingress-counter-effective">{{ $effectiveEnabled ? '已开启' : '已关闭' }}</strong>
            （这是唯一可表述为“已生效”的状态）
        </p>
        <p class="location-ingress-counter__switch-state">
            配置中心保存的目标：
            <strong id="location-ingress-counter-requested">{{ $requestedEnabled === null ? '未配置覆盖' : ($requestedEnabled ? '开启' : '关闭') }}</strong>
        </p>
        @if ($canSwitch)
            <p>操作会发布启动配置、刷新 config cache，并在重启功能开启时请求 LaravelS/Horizon 重启；旧常驻进程短暂继续使用旧值是正常现象。</p>
            <button
                id="location-ingress-counter-switch"
                type="button"
                class="btn {{ $switchTarget === '1' ? 'btn-warning' : 'btn-default' }}"
                data-target="{{ $switchTarget }}"
            >{{ $switchTarget === '1' ? '开启统计' : '停止统计' }}</button>
        @else
            <p>当前账号没有“定位入口调试开关”权限；仍可只读查看已保留的统计 bucket。</p>
        @endif
        <p id="location-ingress-counter-switch-result" class="location-ingress-counter__switch-result" aria-live="polite"></p>
    </section>

    <section class="location-ingress-counter__section location-ingress-counter__switch">
        <h3>诊断模式</h3>
        <p class="location-ingress-counter__switch-state">
            当前处理请求的进程：<strong id="location-ingress-diagnostics-effective">{{ $effectiveMode }}</strong>
            （只有这个状态代表当前进程实际生效）
        </p>
        <p class="location-ingress-counter__switch-state">
            配置中心保存的目标：<strong id="location-ingress-diagnostics-requested">{{ $requestedMode ?? '未配置覆盖' }}</strong>
        </p>
        @if ($canModeSwitch ?? false)
            <p>off 不新增诊断数据；summary 记录低基数分钟汇总；trace 额外保留有界短期样本。发布后必须由 LaravelS 与 Horizon 新进程加载。</p>
            <label for="location-ingress-diagnostics-mode">目标模式</label>
            <select id="location-ingress-diagnostics-mode">
                @foreach (['off', 'summary', 'trace'] as $mode)
                    <option value="{{ $mode }}" {{ ($requestedMode ?? $effectiveMode) === $mode ? 'selected' : '' }}>{{ $mode }}</option>
                @endforeach
            </select>
            <button id="location-ingress-diagnostics-mode-submit" type="button" class="btn btn-warning">发布诊断模式</button>
        @else
            <p>当前账号没有“定位诊断模式开关”权限；仍可读取已有聚合数据。</p>
        @endif
        <p id="location-ingress-diagnostics-mode-result" class="location-ingress-counter__switch-result" aria-live="polite"></p>
    </section>

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
        const switchUrl = root.dataset.switchUrl;
        const modeSwitchUrl = root.dataset.modeSwitchUrl;
        const traceListUrl = root.dataset.traceListUrl;
        const traceDetailUrlTemplate = root.dataset.traceDetailUrlTemplate;
        const csrfToken = root.dataset.csrfToken;
        const minutes = root.querySelector('#location-ingress-counter-minutes');
        const auto = root.querySelector('#location-ingress-counter-auto');
        const refresh = root.querySelector('#location-ingress-counter-refresh');
        const status = root.querySelector('#location-ingress-counter-status');
        const notice = root.querySelector('#location-ingress-counter-notice');
        const received = root.querySelector('#location-ingress-counter-received');
        const accepted = root.querySelector('#location-ingress-counter-accepted');
        const stages = root.querySelector('#location-ingress-diagnostics-stages');
        const switchButton = root.querySelector('#location-ingress-counter-switch');
        const switchResult = root.querySelector('#location-ingress-counter-switch-result');
        const effectiveState = root.querySelector('#location-ingress-counter-effective');
        const requestedState = root.querySelector('#location-ingress-counter-requested');
        const modeSelect = root.querySelector('#location-ingress-diagnostics-mode');
        const modeButton = root.querySelector('#location-ingress-diagnostics-mode-submit');
        const modeResult = root.querySelector('#location-ingress-diagnostics-mode-result');
        const effectiveModeState = root.querySelector('#location-ingress-diagnostics-effective');
        const requestedModeState = root.querySelector('#location-ingress-diagnostics-requested');
        const traceOrigin = root.querySelector('#location-ingress-trace-origin');
        const traceTransport = root.querySelector('#location-ingress-trace-transport');
        const traceSolution = root.querySelector('#location-ingress-trace-solution');
        const traceRefresh = root.querySelector('#location-ingress-trace-refresh');
        const traces = root.querySelector('#location-ingress-traces');
        const traceDetail = root.querySelector('#location-ingress-trace-detail');
        let timer = null;
        let controller = null;
        let switchController = null;
        let modeController = null;
        let traceController = null;
        let switchInFlight = false;
        let modeInFlight = false;

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
            if (!dataUrl || switchInFlight) return;
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
                const enabled = payload.ingress_counter_enabled === true;
                const diagnosticsMode = escapeHtml(payload.diagnostics_mode ?? 'off');
                notice.classList.toggle('location-ingress-counter__notice--disabled', !enabled);
                notice.textContent = enabled
                    ? '入口计数已开启；诊断模式为 ' + diagnosticsMode + '。'
                    : '入口计数已关闭；诊断模式为 ' + diagnosticsMode + '，页面仍可能显示未过期数据。';
                status.textContent = '已读取最近 ' + payload.minutes + ' 分钟，共 ' + ingressRows.length + ' 个入口维度和 ' + stageRows.length + ' 个阶段维度。';
                if (traceListUrl) loadTraces();
            } catch (error) {
                if (error.name !== 'AbortError') status.textContent = '读取失败：Redis 或调试页面暂不可用。';
            } finally {
                refresh.disabled = switchInFlight;
            }
        };
        const updateSwitchRequest = function (requestedEnabled) {
            if (typeof requestedEnabled !== 'boolean') return;

            requestedState.textContent = requestedEnabled ? '开启' : '关闭';
            if (!switchButton) return;

            switchButton.dataset.target = requestedEnabled ? '0' : '1';
            switchButton.textContent = requestedEnabled ? '停止统计' : '开启统计';
            switchButton.classList.toggle('btn-warning', !requestedEnabled);
            switchButton.classList.toggle('btn-default', requestedEnabled);
        };
        const switchMessage = function (payload) {
            switch (payload.publish_status) {
                case 'restart_scheduled':
                    return '启动配置已发布，并已请求 LaravelS/Horizon 重启；请以“当前处理请求的进程”状态确认生效。';
                case 'restart_disabled':
                    return '启动配置已发布，但重启功能已关闭；请等待计划内重启后再确认生效。';
                case 'restart_not_scheduled':
                    return '启动配置已发布，但本次未请求重启；请按重启策略处理后再确认生效。';
                case 'publish_throttled':
                    return '人工发布正处于 30 秒节流中，本次目标未保存；请稍后重新操作。';
                case 'published':
                    return '启动配置已发布；请核对重启调度及新进程的实际状态。';
                default:
                    return '发布失败；本次操作未报告已生效。';
            }
        };
        const switchCounter = async function () {
            if (!switchButton || !switchUrl || switchInFlight) return;

            const enabled = switchButton.dataset.target === '1';
            const action = enabled ? '开启' : '停止';
            if (!window.confirm(
                '确认' + action + '入口统计？此操作将发布启动配置、刷新 config cache，并在重启功能开启时请求 LaravelS/Horizon 重启。'
            )) {
                return;
            }

            switchInFlight = true;
            if (timer) clearInterval(timer);
            timer = null;
            auto.checked = false;
            auto.disabled = true;
            refresh.disabled = true;
            switchButton.disabled = true;
            if (controller) controller.abort();
            switchController = new AbortController();
            switchResult.classList.remove('location-ingress-counter__switch-result--error');
            switchResult.textContent = '正在发布启动配置并请求重启…';
            try {
                const response = await fetch(switchUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ enabled: enabled ? '1' : '0' }),
                    signal: switchController.signal
                });
                const payload = await response.json().catch(function () {
                    return {};
                });
                const published = response.ok && payload.accepted === true;

                if (typeof payload.effective_enabled === 'boolean') {
                    effectiveState.textContent = payload.effective_enabled ? '已开启' : '已关闭';
                }
                if (typeof payload.requested_enabled === 'boolean') {
                    updateSwitchRequest(payload.requested_enabled);
                }
                switchResult.textContent = switchMessage(payload);
                switchResult.classList.toggle('location-ingress-counter__switch-result--error', !published);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    switchResult.textContent = '发布失败；本次操作未报告已生效。';
                    switchResult.classList.add('location-ingress-counter__switch-result--error');
                }
            } finally {
                switchInFlight = false;
                auto.disabled = false;
                refresh.disabled = false;
                switchButton.disabled = false;
            }
        };
        const modeMessage = function (payload) {
            if (payload.publish_status === 'restart_scheduled') {
                return '启动配置目标已发布，并已请求 LaravelS/Horizon 重启；当前进程状态仍以页面显示为准。';
            }
            if (payload.publish_status === 'restart_disabled' || payload.publish_status === 'restart_not_scheduled') {
                return '启动配置目标已发布，等待 LaravelS 与 Horizon 新进程加载后再确认。';
            }
            if (payload.publish_status === 'publish_throttled') {
                return '人工发布正处于 30 秒节流中，本次目标未保存。';
            }
            if (payload.publish_status === 'published') {
                return '启动配置目标已发布；请以新进程和新分钟 bucket 确认生效。';
            }

            return '发布失败；本次操作未报告已生效。';
        };
        const switchMode = async function () {
            if (!modeButton || !modeSelect || !modeSwitchUrl || modeInFlight) return;
            const mode = modeSelect.value;
            if (!window.confirm('确认发布定位诊断模式 ' + mode + '？该操作将发布启动配置并按重启策略请求新进程加载。')) return;

            modeInFlight = true;
            modeButton.disabled = true;
            modeResult.classList.remove('location-ingress-counter__switch-result--error');
            modeResult.textContent = '正在发布启动配置并请求重启…';
            modeController = new AbortController();
            try {
                const response = await fetch(modeSwitchUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ mode: mode }),
                    signal: modeController.signal
                });
                const payload = await response.json().catch(function () { return {}; });
                if (typeof payload.requested_mode === 'string') {
                    requestedModeState.textContent = payload.requested_mode;
                    modeSelect.value = payload.requested_mode;
                }
                if (typeof payload.effective_mode === 'string') {
                    effectiveModeState.textContent = payload.effective_mode;
                }
                modeResult.textContent = modeMessage(payload);
                modeResult.classList.toggle('location-ingress-counter__switch-result--error', !(response.ok && payload.accepted === true));
            } catch (error) {
                if (error.name !== 'AbortError') {
                    modeResult.textContent = '发布失败；本次操作未报告已生效。';
                    modeResult.classList.add('location-ingress-counter__switch-result--error');
                }
            } finally {
                modeInFlight = false;
                modeButton.disabled = false;
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
            if (switchInFlight) return;
            if (timer) clearInterval(timer);
            timer = auto.checked ? setInterval(load, 10000) : null;
        };
        refresh.addEventListener('click', load);
        minutes.addEventListener('change', load);
        auto.addEventListener('change', syncTimer);
        if (switchButton) switchButton.addEventListener('click', switchCounter);
        if (modeButton) modeButton.addEventListener('click', switchMode);
        if (traceRefresh) traceRefresh.addEventListener('click', loadTraces);
        if (traces) traces.addEventListener('click', function (event) {
            const button = event.target.closest('[data-trace-id]');
            if (button) loadTraceDetail(button.dataset.traceId);
        });
        document.addEventListener('pjax:beforeReplace', function () {
            if (timer) clearInterval(timer);
            if (controller) controller.abort();
            if (switchController) switchController.abort();
            if (modeController) modeController.abort();
            if (traceController) traceController.abort();
        }, { once: true });
    }());
</script>
