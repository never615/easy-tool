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
</style>

@php
    $effectiveEnabled = (bool) ($switchState['effective_enabled'] ?? false);
    $requestedEnabled = ($switchState['configured'] ?? false)
        ? (bool) ($switchState['requested_enabled'] ?? false)
        : null;
    $switchTarget = ($requestedEnabled ?? $effectiveEnabled) ? '0' : '1';
@endphp

<section
    class="location-ingress-counter"
    data-url="{{ $dataUrl }}"
    data-switch-url="{{ $switchUrl }}"
    data-csrf-token="{{ $csrfToken }}"
>
    <header class="location-ingress-counter__toolbar">
        <div>
            <h2 style="margin:0; font-size:20px;">入口流量与项目归属</h2>
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
</section>

<script>
    (function () {
        const root = document.querySelector('.location-ingress-counter');
        if (!root || root.dataset.initialized === 'true') return;
        root.dataset.initialized = 'true';

        const dataUrl = root.dataset.url;
        const switchUrl = root.dataset.switchUrl;
        const csrfToken = root.dataset.csrfToken;
        const minutes = root.querySelector('#location-ingress-counter-minutes');
        const auto = root.querySelector('#location-ingress-counter-auto');
        const refresh = root.querySelector('#location-ingress-counter-refresh');
        const status = root.querySelector('#location-ingress-counter-status');
        const notice = root.querySelector('#location-ingress-counter-notice');
        const received = root.querySelector('#location-ingress-counter-received');
        const accepted = root.querySelector('#location-ingress-counter-accepted');
        const switchButton = root.querySelector('#location-ingress-counter-switch');
        const switchResult = root.querySelector('#location-ingress-counter-switch-result');
        const effectiveState = root.querySelector('#location-ingress-counter-effective');
        const requestedState = root.querySelector('#location-ingress-counter-requested');
        let timer = null;
        let controller = null;
        let switchController = null;
        let switchInFlight = false;

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
                const rows = Array.isArray(payload.rows) ? payload.rows : [];
                renderRows(received, rows.filter(function (row) { return row.stage === 'received'; }), ['origin', 'transport', 'location_solution', 'count'], 4);
                renderRows(accepted, rows.filter(function (row) { return row.stage === 'accepted'; }), ['origin', 'transport', 'location_solution', 'subject_id', 'count'], 5);
                const enabled = payload.enabled === true;
                notice.classList.toggle('location-ingress-counter__notice--disabled', !enabled);
                notice.textContent = enabled
                    ? '计数已开启：当前诊断期间会写入短暂保留的 Redis 分钟 bucket。'
                    : '计数已关闭：不会写 Redis；下方仅显示尚未过期的历史 bucket。';
                status.textContent = '已读取最近 ' + payload.minutes + ' 分钟，共 ' + rows.length + ' 个聚合维度。';
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
        const syncTimer = function () {
            if (switchInFlight) return;
            if (timer) clearInterval(timer);
            timer = auto.checked ? setInterval(load, 10000) : null;
        };
        refresh.addEventListener('click', load);
        minutes.addEventListener('change', load);
        auto.addEventListener('change', syncTimer);
        if (switchButton) switchButton.addEventListener('click', switchCounter);
        document.addEventListener('pjax:beforeReplace', function () {
            if (timer) clearInterval(timer);
            if (controller) controller.abort();
            if (switchController) switchController.abort();
        }, { once: true });
    }());
</script>
