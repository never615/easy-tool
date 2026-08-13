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
    .location-ingress-counter__table-wrap { overflow-x: auto; }
    .location-ingress-counter table { width: 100%; min-width: 650px; border-collapse: collapse; }
    .location-ingress-counter th, .location-ingress-counter td { padding: 8px; border: 1px solid #ddd; text-align: left; }
    .location-ingress-counter th { white-space: nowrap; background: #fafafa; }
    .location-ingress-counter__empty { color: #777; }
    .location-ingress-counter__status { margin: 0; font-weight: 600; }
</style>

<section class="location-ingress-counter" data-url="{{ $dataUrl }}">
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
        const minutes = root.querySelector('#location-ingress-counter-minutes');
        const auto = root.querySelector('#location-ingress-counter-auto');
        const refresh = root.querySelector('#location-ingress-counter-refresh');
        const status = root.querySelector('#location-ingress-counter-status');
        const notice = root.querySelector('#location-ingress-counter-notice');
        const received = root.querySelector('#location-ingress-counter-received');
        const accepted = root.querySelector('#location-ingress-counter-accepted');
        let timer = null;
        let controller = null;

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
                refresh.disabled = false;
            }
        };
        const syncTimer = function () {
            if (timer) clearInterval(timer);
            timer = auto.checked ? setInterval(load, 10000) : null;
        };
        refresh.addEventListener('click', load);
        minutes.addEventListener('change', load);
        auto.addEventListener('change', syncTimer);
        document.addEventListener('pjax:beforeReplace', function () {
            if (timer) clearInterval(timer);
            if (controller) controller.abort();
        }, { once: true });
    }());
</script>
