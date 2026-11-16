<div class="box box-primary" id="owner-api-log-settings">
    <div class="box-header with-border"><h3 class="box-title">第三方调用本系统的接口日志</h3></div>
    <div class="box-body">
        @if ($errors->any())
            <div class="alert alert-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <p>配置需要记录的接口路径，并单独启用对应规则。保存后通过运行缓存生效。</p>
        <form method="POST" action="{{ route('owner_api_log.save') }}">
            {{ csrf_field() }}
            <div class="form-group">
                <label for="owner-log-enabled">是否启用接口日志</label>
                <select class="form-control" id="owner-log-enabled" name="enabled">
                    @foreach (['inherit' => '继承部署配置', '1' => '开启', '0' => '关闭'] as $value => $label)
                        <option value="{{ $value }}" {{ (string) old('enabled', $enabled) === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="help-block">部署默认：{{ $baselineEnabled ? '开启' : '关闭' }}。此处与统一配置中心共用同一个开关；关闭后不记录任何接口。</p>
            </div>
            <div class="form-group">
                <label for="owner-log-rules-source">白名单规则来源</label>
                <select class="form-control" id="owner-log-rules-source" name="rules_source">
                    <option value="inherit" {{ old('rules_source', $rulesSource) === 'inherit' ? 'selected' : '' }}>继承部署配置</option>
                    <option value="override" {{ old('rules_source', $rulesSource) === 'override' ? 'selected' : '' }}>使用本页白名单</option>
                </select>
                <p class="help-block">选择“使用本页白名单”后，仅记录下面已启用规则匹配的接口。空白名单或全部关闭表示不记录任何接口。</p>
                <p class="help-block">继承部署配置时，过滤模式为：<strong>{{ $baselineFilterMode === 'whitelist' ? '白名单' : ($baselineFilterMode === 'blacklist' ? '黑名单' : '无效模式（不采集）') }}</strong>。本页覆盖规则只使用白名单。</p>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead><tr><th style="width:90px">启用规则</th><th>接口完整路径 / 通配符</th><th style="width:80px">操作</th></tr></thead>
                    <tbody id="owner-log-rule-rows">
                    @php($formRules = old('rules', $rules))
                    @foreach ((is_array($formRules) && $formRules !== [] ? array_values($formRules) : [['path' => '', 'enabled' => true]]) as $index => $rule)
                        <tr>
                            <td><input type="hidden" name="rules[{{ $index }}][enabled]" value="0"><input type="checkbox" aria-label="启用此规则" name="rules[{{ $index }}][enabled]" value="1" {{ filter_var($rule['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}></td>
                            <td><input class="form-control" name="rules[{{ $index }}][path]" maxlength="255" placeholder="api/tp/locator/*" value="{{ $rule['path'] ?? '' }}"></td>
                            <td><button type="button" class="btn btn-default btn-sm owner-log-remove">删除</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-default btn-sm" id="owner-log-add-rule">添加规则</button>
            <p class="help-block">例如 <code>api/tp/locator</code> 匹配列表路径，<code>api/tp/locator/*</code> 匹配详情路径，包括 GET、DELETE 等方法。路径不含域名和 ? 后的查询参数，最多 100 条。</p>
            <div class="form-group">
                <label for="owner-log-retention-source">保留时间来源</label>
                <select class="form-control" id="owner-log-retention-source" name="retention_source">
                    <option value="inherit" {{ old('retention_source', $retentionSource) === 'inherit' ? 'selected' : '' }}>继承部署配置（{{ $baselineRetentionDays }} 天）</option>
                    <option value="override" {{ old('retention_source', $retentionSource) === 'override' ? 'selected' : '' }}>使用本页保留天数</option>
                </select>
                <label for="owner-log-retention-days">日志保留天数</label>
                <input class="form-control" id="owner-log-retention-days" type="number" min="1" max="36500" step="1" name="retention_days" value="{{ old('retention_days', $retentionDays) }}">
                <p class="help-block">每日清理超过保留时间的本地入站日志。关闭日志采集后，历史日志仍按保留时间清理。</p>
            </div>
            <button class="btn btn-primary" type="submit">保存配置</button>
            <a class="btn btn-default" href="{{ route('configs.index') }}">统一配置中心</a>
        </form>
    </div>
</div>
<div class="box box-default">
    <div class="box-header with-border"><h3 class="box-title">高频接口固定排除</h3></div>
    <div class="box-body">
        <p>以下接口始终跳过日志采集，不读取管理端规则缓存。即使白名单配置为 <code>*</code>，也不会记录这些接口；调整范围需修改部署配置。</p>
        <ul>@foreach ($hardExclude as $path)<li><code>{{ $path }}</code></li>@endforeach</ul>
    </div>
</div>
<script>
(function () {
    var root = document.getElementById('owner-api-log-settings');
    if (!root) return;
    var rows = root.querySelector('#owner-log-rule-rows');
    var next = rows.querySelectorAll('tr').length;
    root.querySelector('#owner-log-add-rule').addEventListener('click', function () {
        if (rows.querySelectorAll('tr').length >= 100) return;
        var index = next++;
        var row = document.createElement('tr');
        row.innerHTML = '<td><input type="hidden" name="rules[' + index + '][enabled]" value="0"><input type="checkbox" aria-label="启用此规则" name="rules[' + index + '][enabled]" value="1" checked></td>'
            + '<td><input class="form-control" name="rules[' + index + '][path]" maxlength="255" placeholder="api/tp/locator/*"></td>'
            + '<td><button type="button" class="btn btn-default btn-sm owner-log-remove">删除</button></td>';
        rows.appendChild(row);
    });
    rows.addEventListener('click', function (event) {
        if (event.target.classList.contains('owner-log-remove')) event.target.closest('tr').remove();
    });
})();
</script>
