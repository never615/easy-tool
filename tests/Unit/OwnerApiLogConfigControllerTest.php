<?php

declare(strict_types=1);

namespace Mallto\Tool\Tests\Unit;

use DOMDocument;
use DOMXPath;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Mallto\Tool\Controller\Admin\OwnerApiLogConfigController;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\Log\OwnerApiLogConfigDefinitions;
use Mallto\Tool\Domain\NewConfig\GlobalConfigApplyMode;
use Mallto\Tool\Domain\NewConfig\GlobalConfigDefinitions;
use Mallto\Tool\Domain\NewConfig\GlobalConfigOverrideManager;
use Mallto\Tool\Domain\NewConfig\GlobalConfigOverrideSource;
use Mallto\Tool\Domain\NewConfig\GlobalConfigRegistry;
use Mallto\Tool\Domain\NewConfig\GlobalConfigRuntimeResolver;
use Mallto\Tool\Domain\RuntimeConfig\Events\GlobalConfigChanged;
use Mockery;
use Tests\TestCase;

final class OwnerApiLogConfigControllerTest extends TestCase
{
    private string $originalDefaultConnection;

    /** @var list<array{key: string, payload: array}> */
    private array $cacheUpdates = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = DB::getDefaultConnection();
        config([
            'database.connections.owner_api_log_config_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'session.driver' => 'array',
            'app.log.owner_api' => true,
            'owner_api_log.include' => ['api/tp/locator', 'api/tp/locator/*'],
            'owner_api_log.retention_days' => 7,
            'owner_api_log.hard_exclude' => ['api/tp/location/results'],
        ]);
        DB::purge('owner_api_log_config_testing');
        DB::setDefaultConnection('owner_api_log_config_testing');
        Schema::create('new_configs', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('key')->unique();
            foreach (['env_key', 'group_key', 'name', 'type', 'override_source'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['value', 'default_value', 'options', 'remark'] as $column) {
                $table->text($column)->nullable();
            }
            $table->integer('sort')->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('requires_reload')->default(false);
            $table->timestamps();
        });
        OwnerApiLogConfigDefinitions::register();
        Event::fake([GlobalConfigChanged::class]);
        // 使用真实模型观察者，仅替换其外部缓存边界。
        $resolver = Mockery::mock();
        $resolver->shouldReceive('replace')->andReturnUsing(function (NewConfig $row): void {
            $this->cacheUpdates[] = ['key' => $row->key, 'payload' => GlobalConfigRuntimeResolver::payloadFor($row)];
        });
        $resolver->shouldNotReceive('forget');
        $this->app->instance(GlobalConfigRuntimeResolver::class, $resolver);
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        view()->share('errors', new ViewErrorBag());
    }

    protected function tearDown(): void
    {
        DB::disconnect('owner_api_log_config_testing');
        DB::setDefaultConnection($this->originalDefaultConnection);
        DB::purge('owner_api_log_config_testing');
        parent::tearDown();
    }

    public function test_validation_preserves_global_off_and_each_rule_switch_without_method_matching(): void
    {
        $validated = $this->controller()->validatedInput($this->request([
            'rules' => [
                ['path' => ' /api/tp/locator/ ', 'enabled' => '1'],
                ['path' => 'api/tp/locator/*', 'enabled' => '0'],
                ['path' => '  ', 'enabled' => '1'],
            ],
        ]));
        $this->assertSame('0', $validated['enabled']);
        $this->assertSame([
            ['path' => 'api/tp/locator', 'enabled' => true],
            ['path' => 'api/tp/locator/*', 'enabled' => false],
        ], $validated['rules']);
        $this->assertSame([], $this->controller()->validatedInput($this->request())['rules']);
    }

    public function test_validation_rejects_domains_query_parameters_methods_and_invalid_switches(): void
    {
        foreach (['GET:api/tp/locator', 'https://example.test/locator', 'api/tp/locator?x=1',
            'api//tp/locator', 'api/tp/locator#fragment'] as $path) {
            $this->assertInvalid(['rules' => [['path' => $path, 'enabled' => '1']]], 'rules.0.path');
        }
        $this->assertInvalid(['rules' => [['path' => 'api/tp/locator', 'enabled' => 'invalid']]], 'rules.0.enabled');
        $this->assertInvalid(['rules' => [['path' => 'api/tp/locator']]], 'rules.0.enabled');
        $this->assertInvalid(['enabled' => 'invalid'], 'enabled');
        $this->assertInvalid(['enabled' => 'inherit'], 'enabled');
        $this->assertInvalid(['rules' => array_fill(0, 101, ['path' => '*', 'enabled' => '1'])], 'rules');
    }

    public function test_retention_requires_valid_days(): void
    {
        foreach ([null, 0, -1, 36501, 'seven', '1.5'] as $days) {
            $this->assertInvalid(['retention_days' => $days], 'retention_days');
        }
    }

    public function test_save_uses_shared_config_keys_and_invalidates_runtime_values_including_explicit_false(): void
    {
        $response = $this->controller()->save($this->request([
            'rules' => [
                ['path' => 'api/tp/locator', 'enabled' => '1'],
                ['path' => 'api/tp/locator/*', 'enabled' => '0'],
            ],
            'retention_days' => '14',
        ]), new GlobalConfigOverrideManager());

        $this->assertSame(route('owner_api_log.settings'), $response->getTargetUrl());
        $rows = NewConfig::query()->get()->keyBy('key');
        $this->assertCount(3, $rows);
        $this->assertSame('0', $rows['owner_api_log.enabled']->value);
        $this->assertSame([
            ['path' => 'api/tp/locator', 'enabled' => true],
            ['path' => 'api/tp/locator/*', 'enabled' => false],
        ], json_decode($rows['owner_api_log.rules']->value, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame('14', $rows['owner_api_log.retention_days']->value);
        foreach ($rows as $row) {
            $this->assertTrue($row->is_enabled);
            $this->assertSame(GlobalConfigOverrideSource::MANUAL, $row->override_source);
            $this->assertFalse($row->requires_reload);
        }
        $this->assertCount(3, $this->cacheUpdates);
        $this->assertSame(['found' => true, 'value' => '0'], $this->cacheUpdates[0]['payload']);
        Event::assertDispatchedTimes(GlobalConfigChanged::class, 3);
    }

    public function test_empty_whitelist_is_saved_as_explicit_value_and_page_has_no_inheritance_action(): void
    {
        $this->controller()->save($this->request(), new GlobalConfigOverrideManager());
        $this->assertSame('[]', NewConfig::query()->where('key', 'owner_api_log.rules')->value('value'));
        $this->assertSame(['found' => true, 'value' => '[]'], $this->cacheUpdates[1]['payload']);
        $html = $this->indexHtml();
        $this->assertStringNotContainsString('继承部署配置', $html);
        $this->assertStringNotContainsString('rules_source', $html);
        $this->assertStringNotContainsString('retention_source', $html);
    }

    public function test_invalid_save_does_not_partially_write_or_invalidate_configuration(): void
    {
        try {
            $this->controller()->save($this->request(['retention_days' => 0]), new GlobalConfigOverrideManager());
            $this->fail('Invalid retention must fail before saving any switch or rule.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('retention_days', $exception->errors());
        }
        $this->assertSame(0, NewConfig::query()->count());
        $this->assertSame([], $this->cacheUpdates);
        Event::assertNotDispatched(GlobalConfigChanged::class);
    }

    public function test_index_renders_explicit_global_off_and_disabled_rule_with_independent_controls(): void
    {
        $this->controller()->save($this->request(['rules' => [
            ['path' => 'api/tp/locator', 'enabled' => '0'],
            ['path' => 'api/tp/locator/*', 'enabled' => '1'],
        ]]), new GlobalConfigOverrideManager());
        $html = $this->indexHtml();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//select[@name="enabled"]/option[@value="0"][@selected]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="rules[0][enabled]"][@type="checkbox"][@checked]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="rules[1][enabled]"][@type="checkbox"][@checked]')->length);
        $this->assertSame(2, $xpath->query('//input[@type="hidden"][@value="0"][contains(@name,"[enabled]")]')->length);
        $this->assertStringContainsString('api/tp/location/results', $html);
        $this->assertStringNotContainsString('name="filter_mode"', $html);
    }

    public function test_index_displays_deployment_values_when_no_page_override_exists(): void
    {
        DB::table('new_configs')->insert([
            ['key' => 'owner_api_log.enabled', 'group_key' => NewConfig::GROUP_GLOBAL_CONFIG,
                'value' => '0', 'is_enabled' => true, 'override_source' => null],
            ['key' => 'owner_api_log.rules', 'group_key' => NewConfig::GROUP_GLOBAL_CONFIG,
                'value' => '[]', 'is_enabled' => false, 'override_source' => GlobalConfigOverrideSource::MANUAL],
        ]);
        $xpath = $this->xpath($this->indexHtml());
        $this->assertSame(1, $xpath->query('//select[@name="enabled"]/option[@value="1"][@selected]')->length);
        $this->assertSame(0, $xpath->query('//select[@name="enabled"]/option[@value="inherit"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="rules[0][path]"][@value="api/tp/locator"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="retention_days"][@value="7"]')->length);
        $this->assertStringContainsString('当前值来自本次部署的默认配置', $this->indexHtml());
    }

    public function test_saving_displayed_defaults_pins_all_values_against_later_deployment_changes(): void
    {
        $this->controller()->save($this->request([
            'enabled' => '1', 'retention_days' => '7',
            'rules' => [['path' => 'api/tp/locator', 'enabled' => '1']],
        ]), new GlobalConfigOverrideManager());
        config(['app.log.owner_api' => false, 'owner_api_log.retention_days' => 30,
            'owner_api_log.admin_rules' => [['path' => 'api/tp/floor', 'enabled' => true]]]);
        $html = $this->indexHtml();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//select[@name="enabled"]/option[@value="1"][@selected]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="retention_days"][@value="7"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="rules[0][path]"][@value="api/tp/locator"]')->length);
        $this->assertStringNotContainsString('api/tp/floor', $html);
        $this->assertStringNotContainsString('当前值来自本次部署的默认配置', $html);
    }

    public function test_validation_old_input_wins_over_saved_values_and_is_html_escaped(): void
    {
        session()->flashInput([
            'enabled' => '0',
            'retention_days' => '12', 'rules' => [4 => ['path' => '<script>alert(1)</script>', 'enabled' => '0']],
        ]);
        $html = $this->indexHtml();
        $xpath = $this->xpath($html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertSame(1, $xpath->query('//select[@name="enabled"]/option[@value="0"][@selected]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="retention_days"][@value="12"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="rules[0][path]"]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="rules[4][path]"]')->length);
        $this->assertSame(0, $xpath->query('//input[@type="checkbox"][@name="rules[0][enabled]"][@checked]')->length);
    }

    public function test_module_and_routes_use_configuration_center_and_admin_protection(): void
    {
        $module = GlobalConfigRegistry::modules()['owner_api_log'];
        $this->assertSame('owner_api_log.settings', $module['route']);
        $this->assertSame('owner_api_log.save', $module['save_route']);
        foreach (['enabled', 'rules', 'retention_days'] as $suffix) {
            $definition = GlobalConfigDefinitions::find('owner_api_log.' . $suffix);
            $this->assertSame('laravel_config', $definition['baseline_source']);
            $this->assertSame(GlobalConfigApplyMode::SWOOLE_REDIS, $definition['apply_mode']);
        }
        foreach (['settings' => 'GET', 'save' => 'POST'] as $action => $method) {
            $route = $this->app['router']->getRoutes()->getByName('owner_api_log.' . $action);
            $this->assertNotNull($route);
            $this->assertContains($method, $route->methods());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('adminE_base', $route->gatherMiddleware());
            $this->assertContains('adminE.auto_permission', $route->gatherMiddleware());
            $this->assertStringContainsString(OwnerApiLogConfigController::class, $route->getActionName());
        }
    }

    private function controller(): OwnerApiLogConfigController
    {
        return new OwnerApiLogConfigController();
    }

    private function request(array $input = []): Request
    {
        return Request::create('/admin/configs/owner-api-log', 'POST', array_replace([
            'enabled' => '0', 'rules' => [], 'retention_days' => '7',
        ], $input));
    }

    private function assertInvalid(array $input, string $field): void
    {
        try {
            $this->controller()->validatedInput($this->request($input));
            $this->fail('Expected validation error for ' . $field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function indexHtml(): string
    {
        $html = '';
        $content = Mockery::mock(Content::class);
        $content->shouldReceive('header')->once()->with('入站接口日志');
        $content->shouldReceive('description')->once()->with('采集开关、白名单规则与保留时间');
        $content->shouldReceive('body')->once()->andReturnUsing(function (string $body) use (&$html): void {
            $html = $body;
        });
        Admin::shouldReceive('content')->once()->andReturnUsing(static function (callable $callback) use ($content) {
            $callback($content);
            return $content;
        });
        $this->controller()->index();
        return $html;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }
}
