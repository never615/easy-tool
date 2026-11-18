<style>
    .server-commands { max-width: 1280px; }
    .server-commands .sc-panel { background:#fff; border:1px solid #d8dde6; border-radius:4px; padding:18px; margin-bottom:16px; }
    .server-commands h3 { margin:0 0 14px; font-size:18px; }
    .server-commands .sc-muted { color:#667085; }
    .server-commands .sc-row { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
    .server-commands .sc-field { display:flex; flex-direction:column; gap:5px; min-width:180px; margin-bottom:12px; }
    .server-commands .sc-field label { margin:0; }
    .server-commands .sc-grow { flex:1; }
    .server-commands .sc-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
    .server-commands textarea { font-family:Menlo,Consolas,monospace; min-height:120px; resize:vertical; }
    .server-commands pre { background:#f8fafc; color:#182230; max-height:380px; overflow:auto; white-space:pre-wrap; overflow-wrap:anywhere; }
    .server-commands .sc-details { display:grid; grid-template-columns:max-content 1fr; gap:6px 14px; margin:12px 0; }
    .server-commands .sc-details dd { margin:0; overflow-wrap:anywhere; }
    .server-commands .sc-warning { padding:12px; border-left:3px solid #d97706; background:#fffbeb; margin:12px 0; }
    .server-commands [hidden] { display:none !important; }
    .server-commands .sc-table { width:100%; }
    .server-commands .sc-table td { vertical-align:middle; }
    .server-commands .sc-record-id { font-family:Menlo,Consolas,monospace; font-size:11px; }
    @media(max-width:650px) { .server-commands .sc-field { width:100%; } .server-commands .sc-details { grid-template-columns:1fr; gap:3px; } }
</style>

<section class="server-commands" id="server-command-console">
    <div class="sc-panel">
        <h3>服务器命令</h3>
        <p>选择预设并填写编号，或加载到编辑区后执行。命令在独立运维执行器中运行；关闭页面不会停止已经提交的命令。</p>
        <p class="sc-muted">当前页面节点：{{ $webHostname }} · {{ $webEnvironment }}。执行角色：应用 / Horizon 运维实例，实际节点将在执行记录中显示。本地配置更新不代表其他 Web 节点已生效。</p>
        @unless($enabled)
            <div class="sc-warning">命令执行当前未启用。可查看已授权的预设和执行记录；请按部署手册启用服务器命令功能及专用消费进程。</div>
        @endunless
        <div class="sc-field" style="max-width:220px">
            <label for="sc-timeout">最长执行时间（秒）</label>
            <input id="sc-timeout" type="number" class="form-control" min="1" max="300" value="60" required>
        </div>
        <div id="sc-message" role="status" aria-live="polite" hidden></div>
    </div>

    <form id="sc-preset-form" class="sc-panel" autocomplete="off">
        <h3>预设命令</h3>
        <div class="sc-row">
            <div class="sc-field"><label for="sc-search">搜索预设</label><input id="sc-search" class="form-control" type="search" placeholder="例如：SOS、迁移、状态"></div>
            <div class="sc-field sc-grow"><label for="sc-preset">选择命令</label><select id="sc-preset" class="form-control"></select></div>
        </div>
        <p id="sc-description" class="sc-muted"></p>
        <div id="sc-options" class="sc-row"></div>
        <div class="sc-actions">
            <button id="sc-preset-run" class="btn btn-primary" type="submit">执行预设</button>
            <button id="sc-load" class="btn btn-default" type="button">加载到编辑区</button>
        </div>
    </form>

    <div class="sc-panel">
        <h3>编辑命令 / 自定义 Artisan</h3>
        <p id="sc-editor-mode" class="sc-muted">从预设加载后可以修改参数。修改命令名或增加其他选项会按自定义命令重新检查权限。</p>
        @if($canAny)
            <p class="sc-muted">也可直接输入当前应用的 Artisan 命令。仅执行单条、非交互命令；不支持 Shell 管道和重定向，不会自动添加 --force。</p>
        @else
            <p class="sc-muted">当前账号没有“任意 Artisan 命令执行”权限；可编辑获准预设的参数。</p>
        @endif
        <label for="sc-text" class="sr-only">Artisan 命令文本</label>
        <textarea id="sc-text" class="form-control" maxlength="16384" spellcheck="false" autocomplete="off" placeholder="例如：migrate:status"></textarea>
        <div class="sc-actions">
            <button id="sc-editor-run" class="btn btn-primary" type="button">预览 / 执行</button>
            @if($canAny)<button id="sc-custom" class="btn btn-default" type="button">新建自定义命令</button>@endif
        </div>
    </div>

    <div id="sc-confirmation" class="sc-panel" hidden>
        <h3>确认本次执行</h3>
        <p>请核对命令、参数和执行范围。任意 Artisan 使用应用运行账号权限，可能修改数据或触发服务重启。</p>
        <pre id="sc-confirm-command"></pre>
        <p id="sc-confirm-meta" class="sc-muted"></p>
        <div class="sc-actions">
            <button id="sc-confirm-run" class="btn btn-warning" type="button">确认执行</button>
            <button id="sc-confirm-cancel" class="btn btn-default" type="button">返回修改</button>
        </div>
    </div>

    <div class="sc-panel">
        <div class="sc-row" style="justify-content:space-between;align-items:center"><h3>最近执行记录</h3><button id="sc-refresh" type="button" class="btn btn-default btn-sm">刷新记录</button></div>
        <p class="sc-muted">这里只列出有权查看的最近 20 次执行。HTTP 超时或提交结果不确定时先查看记录，不要重复执行。</p>
        <div class="table-responsive">
            <table class="table table-bordered sc-table">
                <thead><tr><th>执行编号 / 时间</th><th>命令</th><th>状态</th><th>退出码</th><th>操作</th></tr></thead>
                <tbody id="sc-history"><tr><td colspan="5">正在加载执行记录…</td></tr></tbody>
            </table>
        </div>
    </div>

    <div id="sc-result" class="sc-panel" hidden>
        <h3>执行结果</h3>
        <dl id="sc-result-details" class="sc-details"></dl>
        <p id="sc-result-note" class="sc-muted"></p>
        <div id="sc-output" hidden>
            <h4>标准输出</h4><pre id="sc-stdout"></pre>
            <h4>错误输出</h4><pre id="sc-stderr"></pre>
            <button id="sc-copy" class="btn btn-default btn-sm" type="button">复制当前输出</button>
        </div>
        <div id="sc-recovery" class="sc-warning" hidden>
            <p id="sc-recovery-target"></p>
            <p>结果未知不会自动重跑。先核对实际执行实例，确认旧 CLI 及其派生工作已停止；必要时按原部署流程处理，再解除该次占用。此操作不会终止进程，也不撤销已产生的副作用。</p>
            <label for="sc-recovery-text">完成核对后输入“已确认进程停止”</label>
            <input id="sc-recovery-text" class="form-control" type="text" autocomplete="off" style="max-width:360px">
            <div class="sc-actions"><button id="sc-release" class="btn btn-warning" type="button">解除本次故障占用</button></div>
        </div>
    </div>
</section>

<script>
(function () {
    'use strict';
    var root = document.getElementById('server-command-console');
    if (!root) return;
    if (window.disposeServerCommandConsole) window.disposeServerCommandConsole();
    var presets = @json(array_values($presets), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    var urls = @json($urls, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    var csrf = @json($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    var enabled = @json((bool)$enabled);
    var currentSubject = @json($currentSubject);
    var selectedPreset = null, editorPreset = null, confirmation = null, pendingInput = null, submissionId = null;
    var activeRun = null, timer = null, busy = false, stopped = false, requestNumber = 0, inputVersion = 0;
    var names = {queued:'待执行',running:'运行中',succeeded:'命令完成',failed:'非零退出 / 执行失败',timed_out:'超时',not_started:'未启动',unknown:'结果未知',released:'已人工解除占用',cancelled:'已取消'};
    function el(id) { return root.querySelector('#' + id); }
    function text(value) { return value === null || value === undefined ? '—' : (typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value)); }
    function message(value, error) { var box=el('sc-message');box.hidden=false;box.className='alert '+(error?'alert-danger':'alert-info');box.textContent=value; }
    function status(value) { return names[value] || value || '未知'; }
    function running(value) { return value === 'queued' || value === 'running'; }
    function live() { return !stopped && document.documentElement.contains(root); }
    function endpoint(name, id) { return urls[name].replace('__ID__', encodeURIComponent(id)); }
    function setBusy(value) {
        busy=value;
        ['sc-preset-run','sc-editor-run','sc-confirm-run'].forEach(function(id){el(id).disabled=value||!enabled;});
        el('sc-load').disabled=value||!selectedPreset;
        el('sc-release').disabled=value;
    }
    function clearConfirmation() { confirmation=null;pendingInput=null;el('sc-confirmation').hidden=true; }
    function invalidate() { inputVersion++;submissionId=null;clearConfirmation(); }
    function executionError(error) { return error.message+(submissionId?' 本次提交编号：'+submissionId+'。请先查记录；内容未修改时再次提交沿用此编号，不会重放已登记的执行。':''); }
    function uuid() {
        if (!window.crypto || !window.crypto.getRandomValues) throw new Error('浏览器不支持安全提交编号，请使用受支持的浏览器。');
        var b=new Uint8Array(16);window.crypto.getRandomValues(b);b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;
        var h=Array.from(b,function(v){return v.toString(16).padStart(2,'0');}).join('');
        return h.slice(0,8)+'-'+h.slice(8,12)+'-'+h.slice(12,16)+'-'+h.slice(16,20)+'-'+h.slice(20);
    }
    async function request(url, body) {
        var response;
        try { response=await fetch(url,{method:body===undefined?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:body===undefined?undefined:JSON.stringify(body)}); }
        catch (_) { throw new Error('网络请求未完成。命令可能已经提交，请先刷新执行记录核对，不要重复提交。'); }
        var payload;
        try { payload=await response.json(); } catch (_) { throw new Error('未收到有效响应，请检查登录状态并刷新执行记录核对。'); }
        if (!response.ok) throw new Error(payload.message || '操作未完成，请检查权限或执行记录。');
        return payload.data;
    }
    function timeout() { if (!el('sc-timeout').reportValidity()) throw new Error('执行时间须为 1 至 300 秒。');return Number(el('sc-timeout').value); }
    function renderOptions() {
        var area=el('sc-options');area.replaceChildren();
        if (!selectedPreset) {el('sc-description').textContent='暂无匹配且获准执行的预设。';el('sc-load').disabled=true;return;}
        el('sc-description').textContent=(selectedPreset.readonly?'只读 · ':'维护操作 · ')+(selectedPreset.description||'');
        Object.keys(selectedPreset.options||{}).forEach(function(name){
            var spec=selectedPreset.options[name],wrapper=document.createElement('div'),label=document.createElement('label'),input;
            wrapper.className='sc-field';label.textContent=(spec.label||name)+(spec.required?' *':'');label.htmlFor='sc-param-'+name;
            if (spec.choices) {input=document.createElement('select');var empty=document.createElement('option');empty.value='';empty.textContent='请选择';input.appendChild(empty);Object.keys(spec.choices).forEach(function(key){var option=document.createElement('option');option.value=Array.isArray(spec.choices)?spec.choices[key]:key;option.textContent=spec.choices[key];input.appendChild(option);});}
            else {input=document.createElement('input');input.type=(spec.type==='integer'||spec.type==='int')?'number':spec.type==='boolean'?'checkbox':'text';}
            input.id='sc-param-'+name;input.dataset.option=name;input.className=input.type==='checkbox'?'':'form-control';input.required=!!spec.required;
            if(spec.min!==undefined) input.min=spec.min;if(spec.max!==undefined) input.max=spec.max;
            var value=spec.default!==undefined?spec.default:(name==='subject'&&currentSubject?currentSubject:'');
            if(input.type==='checkbox') input.checked=!!value;else input.value=value;
            input.addEventListener('input',invalidate);wrapper.append(label,input);area.appendChild(wrapper);
        });
        el('sc-load').disabled=busy;
    }
    function filterPresets() {
        var query=el('sc-search').value.toLowerCase(),select=el('sc-preset'),previous=select.value;select.replaceChildren();
        presets.forEach(function(p){if((p.title+' '+p.description+' '+p.command).toLowerCase().indexOf(query)<0)return;var option=document.createElement('option');option.value=p.id;option.textContent=p.title;select.appendChild(option);});
        if(Array.from(select.options).some(function(o){return o.value===previous;})) select.value=previous;
        selectedPreset=presets.find(function(p){return p.id===select.value;})||null;
        if(previous!==select.value||!selectedPreset){invalidate();renderOptions();}
    }
    function parameters() {var result={};el('sc-options').querySelectorAll('[data-option]').forEach(function(input){if(input.type==='checkbox')result[input.dataset.option]=input.checked;else if(input.value!=='')result[input.dataset.option]=input.value;});return result;}
    function quote(value) {var s=String(value);return /^[a-zA-Z0-9_./:@,=+\-]+$/.test(s)?s:'"'+s.replace(/\\/g,'\\\\').replace(/"/g,'\\"')+'"';}
    function presetText(p,values) {
        var words=[p.command].concat(p.fixed_arguments||[]);
        Object.keys(p.fixed_options||{}).forEach(function(name){var value=p.fixed_options[name];if(value===true)words.push('--'+name);else if(value!==false&&value!==null)words.push('--'+name+'='+value);});
        Object.keys(values).forEach(function(name){if(values[name]===true)words.push('--'+name);else if(values[name]!==false)words.push('--'+name+'='+values[name]);});
        return words.map(quote).join(' ');
    }
    function payload(fromPreset) {
        if(fromPreset) {if(!selectedPreset)throw new Error('请选择预设命令。');if(!el('sc-preset-form').reportValidity())throw new Error('请填写预设所需参数。');return {preset_id:selectedPreset.id,parameters:parameters(),timeout:timeout()};}
        var command=el('sc-text').value;if(!command.trim())throw new Error('请输入命令或从预设加载。');return {preset_id:editorPreset,text:command,timeout:timeout()};
    }
    async function admit(input) {
        if(busy)return;setBusy(true);clearConfirmation();
        var version=inputVersion;
        try {
            var preview=await request(urls.preview,input);if(!live())return;
            if(version!==inputVersion){message('命令内容已修改，请重新预览后执行。',false);return;}
            if(preview.requires_confirmation){
                confirmation=preview;pendingInput=input;
                el('sc-confirm-command').textContent=preview.command;
                el('sc-confirm-meta').textContent='执行角色：应用 / Horizon 运维实例；超时：'+preview.timeout+' 秒；请求环境：'+(preview.requested_env||'执行器部署环境')+'。结果不代表全实例配置已更新。';
                el('sc-confirmation').hidden=false;el('sc-confirmation').scrollIntoView({block:'nearest'});
                if(!preview.preset_id)el('sc-editor-mode').textContent='自定义 Artisan：将按任意命令权限执行。';
            } else {await submit(input,preview.confirmation_token||null);}
        } catch(error){message(executionError(error),true);}finally{if(live())setBusy(false);}
    }
    async function submit(input, token) {
        if(!submissionId)submissionId=uuid();
        var body=Object.assign({},input,{submission_id:submissionId});if(token)body.confirmation_token=token;
        var record=await request(urls.submit,body);if(!live())return;invalidate();message('已提交执行 '+record.id+'。请查看执行记录，勿重复提交。',false);
        await loadHistory();await openRun(record.id);
    }
    function addDetail(label,value) {var term=document.createElement('dt'),definition=document.createElement('dd');term.textContent=label;definition.textContent=text(value);el('sc-result-details').append(term,definition);}
    function renderRecord(record,withOutput) {
        el('sc-result').hidden=false;el('sc-result-details').replaceChildren();
        addDetail('执行编号',record.id);addDetail('命令',record.command_name);addDetail('状态',status(record.status));addDetail('提交时间',record.created_at);addDetail('开始 / 结束',text(record.started_at)+' / '+text(record.finished_at));addDetail('退出码',record.exit_code);addDetail('执行实例',record.executor);
        if(record.actor_id!==undefined)addDetail('操作人编号',record.actor_id);
        if(withOutput&&record.command)addDetail('执行命令（已脱敏）',record.command);
        if(withOutput&&!record.body_expired)addDetail('命令请求环境',record.requested_env||'继承执行器部署环境（可能与当前页面环境不同）');
        if(record.timeout!==undefined)addDetail('超时预算',record.timeout+' 秒');
        var notes=[];if(record.summary)notes.push(record.summary);if(record.truncated)notes.push('输出已达到上限，后续内容未保存。');if(running(record.status))notes.push('正在等待结果。页面仅查询状态，不会再次执行命令。');else if(record.status==='unknown')notes.push('结果未知；已产生的副作用不会自动回滚，请核对执行实例。');else notes.push('退出码只代表本次命令调用；重启、派生队列或发布效果仍需另行验收。');
        if(record.body_expired)notes.push('命令正文和输出已超过保留期限，当前仅保留执行摘要。');
        if(withOutput&&record.requested_env)notes.push('请求环境为本次 CLI 的 --env 选择；执行实例中的 environment 为父执行器部署环境，两者可以不同。');
        el('sc-result-note').textContent=notes.join(' ');el('sc-recovery').hidden=!record.can_release;
        el('sc-recovery-target').textContent='待解除执行：'+record.id+'；执行实例：'+text(record.executor)+'。';
        if(withOutput){el('sc-output').hidden=false;el('sc-stdout').textContent=record.stdout||'（无输出）';el('sc-stderr').textContent=record.stderr||'（无输出）';}
        else el('sc-output').hidden=true;
    }
    async function loadHistory() {
        try {var rows=await request(urls.history);if(!live())return;var tbody=el('sc-history');tbody.replaceChildren();
            (Array.isArray(rows)?rows:(rows.records||[])).forEach(function(record){var row=document.createElement('tr');[record.id+'\n'+text(record.created_at),record.command_name,status(record.status),text(record.exit_code)].forEach(function(value,index){var cell=document.createElement('td');cell.textContent=value;if(index===0)cell.className='sc-record-id';row.appendChild(cell);});var cell=document.createElement('td'),button=document.createElement('button');button.type='button';button.className='btn btn-default btn-sm';button.textContent='查看';button.addEventListener('click',function(){openRun(record.id);});cell.appendChild(button);row.appendChild(cell);tbody.appendChild(row);});
            if(!tbody.children.length){var row=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=5;cell.textContent='暂无可查看的执行记录。';row.appendChild(cell);tbody.appendChild(row);}
        }catch(error){if(live())message(error.message,true);}
    }
    async function pollRun(id, generation) {
        if(!live()||activeRun!==id||generation!==requestNumber)return;
        try {var record=await request(endpoint('show',id)+'?output=0');if(!live()||activeRun!==id||generation!==requestNumber)return;
            renderRecord(record,false);
            if(running(record.status)){timer=setTimeout(function(){pollRun(id,generation);},2000);}
            else {var result=await request(endpoint('show',id)+'?output=1');if(live()&&activeRun===id&&generation===requestNumber)renderRecord(result,true);await loadHistory();}
        }catch(error){if(live())message(error.message+' 自动轮询已停止，可刷新记录后重新查看。',true);}
    }
    async function openRun(id) {if(timer)clearTimeout(timer);activeRun=id;requestNumber++;el('sc-recovery-text').value='';await pollRun(id,requestNumber);}
    el('sc-search').addEventListener('input',filterPresets);
    el('sc-preset').addEventListener('change',function(){selectedPreset=presets.find(function(p){return p.id===el('sc-preset').value;})||null;invalidate();renderOptions();});
    el('sc-preset-form').addEventListener('submit',function(event){event.preventDefault();try{admit(payload(true));}catch(error){message(error.message,true);}});
    el('sc-load').addEventListener('click',function(){try{var input=payload(true);editorPreset=selectedPreset.id;el('sc-text').value=presetText(selectedPreset,input.parameters);el('sc-editor-mode').textContent='来自预设：'+selectedPreset.title+'。可以修改参数；扩大范围会按自定义权限检查。';invalidate();el('sc-text').focus();}catch(error){message(error.message,true);}});
    el('sc-text').addEventListener('input',invalidate);el('sc-timeout').addEventListener('input',invalidate);
    el('sc-editor-run').addEventListener('click',function(){try{admit(payload(false));}catch(error){message(error.message,true);}});
    if(el('sc-custom'))el('sc-custom').addEventListener('click',function(){editorPreset=null;el('sc-text').value='';el('sc-editor-mode').textContent='自定义 Artisan：执行前需要核对并确认。';invalidate();el('sc-text').focus();});
    el('sc-confirm-cancel').addEventListener('click',clearConfirmation);
    el('sc-confirm-run').addEventListener('click',async function(){if(busy||!confirmation||!pendingInput)return;var input=pendingInput,token=confirmation.confirmation_token;setBusy(true);try{await submit(input,token);}catch(error){message(executionError(error),true);}finally{if(live())setBusy(false);}});
    el('sc-refresh').addEventListener('click',loadHistory);
    el('sc-release').addEventListener('click',async function(){if(busy||!activeRun)return;var confirmationText=el('sc-recovery-text').value;if(confirmationText!=='已确认进程停止'){message('请完成进程核对并输入“已确认进程停止”。',true);return;}setBusy(true);try{await request(endpoint('release',activeRun),{confirmation:confirmationText});message('已解除该次占用；没有重放原命令。',false);await loadHistory();await openRun(activeRun);}catch(error){message(error.message,true);}finally{if(live())setBusy(false);}});
    el('sc-copy').addEventListener('click',async function(){var output='stdout:\n'+el('sc-stdout').textContent+'\n\nstderr:\n'+el('sc-stderr').textContent;try{if(!navigator.clipboard)throw new Error();await navigator.clipboard.writeText(output);message('当前输出已复制。',false);}catch(_){message('浏览器不支持直接复制，请在输出区域选择文本复制。',false);}});
    window.disposeServerCommandConsole=function(){stopped=true;if(timer)clearTimeout(timer);};
    filterPresets();setBusy(false);loadHistory();
})();
</script>
