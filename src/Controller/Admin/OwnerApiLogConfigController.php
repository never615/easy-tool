<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigOverrideManager;

/** 白名单规则及总开关快捷入口；保存复用配置中心和提交后的缓存失效。 */
class OwnerApiLogConfigController extends Controller
{
    public function index()
    {
        $rows = NewConfig::query()->where('group_key', NewConfig::GROUP_GLOBAL_CONFIG)
            ->whereIn('key', ['owner_api_log.enabled', 'owner_api_log.rules', 'owner_api_log.retention_days'])
            ->get()->keyBy('key');
        $overrides = [];
        foreach ($rows as $key => $row) {
            if ($row->is_enabled && $row->override_source !== null) {
                $overrides[$key] = $row->value;
            }
        }
        $hasRulesOverride = array_key_exists('owner_api_log.rules', $overrides);
        $rules = $hasRulesOverride
            ? json_decode((string) $overrides['owner_api_log.rules'], true)
            : config('owner_api_log.admin_rules', []);
        $rules = is_array($rules) ? array_values($rules) : [];
        $snapshot = [
            'enabled' => array_key_exists('owner_api_log.enabled', $overrides)
                ? (filter_var($overrides['owner_api_log.enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                : 'inherit',
            'baselineEnabled' => (bool) config('app.log.owner_api', false),
            'baselineFilterMode' => (string) config('owner_api_log.filter_mode', 'blacklist'),
            'rulesSource' => $hasRulesOverride ? 'override' : 'inherit',
            'rules' => $rules,
            'retentionSource' => array_key_exists('owner_api_log.retention_days', $overrides) ? 'override' : 'inherit',
            'retentionDays' => $overrides['owner_api_log.retention_days'] ?? config('owner_api_log.retention_days', 7),
            'baselineRetentionDays' => config('owner_api_log.retention_days', 7),
            'hardExclude' => (array) config('owner_api_log.hard_exclude', []),
        ];

        return Admin::content(function (Content $content) use ($snapshot): void {
            $content->header('入站接口日志');
            $content->description('采集开关、白名单规则与保留时间');
            $content->body(view('easy-tool::owner-api-log-settings', $snapshot)->render());
        });
    }

    public function save(Request $request, GlobalConfigOverrideManager $manager)
    {
        // 全部输入先完成校验，再一次事务写入，避免只有部分设置保存成功。
        $input = $this->validatedInput($request);
        DB::transaction(function () use ($input, $manager): void {
            if ($input['enabled'] === 'inherit') {
                $manager->clear('owner_api_log.enabled');
            } else {
                $manager->setManual('owner_api_log.enabled', $input['enabled']);
            }
            if ($input['rules_source'] === 'inherit') {
                $manager->clear('owner_api_log.rules');
            } else {
                $manager->setManual('owner_api_log.rules', json_encode($input['rules'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if ($input['retention_source'] === 'inherit') {
                $manager->clear('owner_api_log.retention_days');
            } else {
                $manager->setManual('owner_api_log.retention_days', (string) $input['retention_days']);
            }
        });

        admin_toastr('接口日志配置已保存，将通过运行缓存生效。');
        return redirect()->route('owner_api_log.settings');
    }

    /** @return array{enabled: string, rules_source: string, rules: array, retention_source: string, retention_days?: mixed} */
    public function validatedInput(Request $request): array
    {
        $input = $request->only('enabled', 'rules_source', 'rules', 'retention_source', 'retention_days');
        $validated = Validator::make($input, [
            'enabled' => ['required', Rule::in(['inherit', '0', '1'])],
            'rules_source' => ['required', Rule::in(['inherit', 'override'])],
            'rules' => ['nullable', 'array', 'max:100'],
            'rules.*' => ['array'],
            'rules.*.path' => ['nullable', 'string', 'max:255'],
            'rules.*.enabled' => ['required', 'boolean'],
            'retention_source' => ['required', Rule::in(['inherit', 'override'])],
            'retention_days' => ['required_if:retention_source,override', 'nullable', 'integer', 'between:1,36500'],
        ], [
            'rules.max' => '白名单最多支持 100 条规则。',
            'rules.*.path.max' => '接口路径不能超过 255 个字符。',
            'retention_days.between' => '日志保留天数必须为 1–36500 天。',
        ])->validate();

        $rules = [];
        foreach ($validated['rules'] ?? [] as $index => $rule) {
            $path = trim((string) ($rule['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_.*%~\/-]+$/D', $path) || str_contains($path, '//')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'rules.' . $index . '.path' => '请填写完整接口路径或通配符，不包含域名、查询参数和 HTTP 方法。',
                ]);
            }
            $path = $path === '/' ? '/' : trim($path, '/');
            $rules[] = ['path' => $path, 'enabled' => filter_var($rule['enabled'], FILTER_VALIDATE_BOOLEAN)];
        }
        $validated['rules'] = $rules;

        return $validated;
    }
}
