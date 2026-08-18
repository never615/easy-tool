<?php

namespace Mallto\Tool\Tests\Unit;

use InvalidArgumentException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Mallto\Tool\Controller\Admin\ConfigModuleController;
use Mallto\Tool\Controller\Admin\ConfigController;
use Mallto\Tool\Controller\Admin\NewConfigController;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigOverrideSource;
use Mallto\Tool\Domain\NewConfig\GlobalConfigModuleForm;
use Mallto\Tool\Domain\NewConfig\GlobalConfigOverrideManager;
use Mallto\Tool\Domain\NewConfig\NewConfigPublisher;
use Mallto\Tool\Seeder\NewConfigDocumentSeeder;
use ReflectionMethod;
use Tests\TestCase;

class GlobalConfigAdminContractTest extends TestCase
{
    /** @var list<string> */
    private array $createdKeys = [];

    private string $originalDefaultConnection;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = DB::getDefaultConnection();
        config()->set('database.connections.global_config_admin_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('global_config_admin_testing');
        DB::setDefaultConnection('global_config_admin_testing');

        Schema::create('new_configs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('key')->unique();
            $table->string('env_key')->nullable();
            $table->string('group_key');
            $table->string('name')->nullable();
            $table->string('type')->default('string');
            $table->text('value')->nullable();
            $table->text('default_value')->nullable();
            $table->text('options')->nullable();
            $table->text('remark')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('requires_reload')->default(false);
            $table->string('override_source', 32)->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->text('last_publish_error')->nullable();
            $table->timestamps();
        });
    }

    public function tearDown(): void
    {
        if ($this->createdKeys !== []) {
            NewConfig::withoutEvents(function (): void {
                NewConfig::query()->whereIn('key', $this->createdKeys)->delete();
            });
        }

        DB::disconnect('global_config_admin_testing');
        DB::setDefaultConnection($this->originalDefaultConnection);
        DB::purge('global_config_admin_testing');

        parent::tearDown();
    }

    public function test_audit_json_never_contains_raw_database_values(): void
    {
        $secret = 'phpunit-unique-global-config-secret';
        $key = 'phpunit.global_config.audit.' . bin2hex(random_bytes(4));
        $this->createGlobalRow($key, $secret);

        $exitCode = Artisan::call('tool:global_config_override_audit', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(2, $exitCode);
        $this->assertStringNotContainsString($secret, $output);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $row = collect($decoded['rows'])->firstWhere('key', $key);
        $this->assertIsArray($row);
        $this->assertSame(hash('sha256', $secret), $row['database_value_sha256']);
        $this->assertArrayNotHasKey('database_value', $row);
    }

    public function test_adopt_command_marks_only_existing_global_rows_as_reviewed(): void
    {
        $key = 'phpunit.global_config.adopt.' . bin2hex(random_bytes(4));
        $row = $this->createGlobalRow($key, 'keep-this-value');

        $exitCode = Artisan::call('tool:global_config_override_adopt', [
            'keys' => [$key],
            '--source' => GlobalConfigOverrideSource::REVIEWED,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('keep-this-value', $row->fresh()->value);
        $this->assertSame(GlobalConfigOverrideSource::REVIEWED, $row->fresh()->override_source);
    }

    public function test_adopt_command_rejects_unsupported_sources_and_duplicate_keys(): void
    {
        $key = 'phpunit.global_config.reject.' . bin2hex(random_bytes(4));
        $row = $this->createGlobalRow($key, 'unchanged');

        $this->assertSame(1, Artisan::call('tool:global_config_override_adopt', [
            'keys' => [$key],
            '--source' => GlobalConfigOverrideSource::MANUAL,
        ]));
        $this->assertNull($row->fresh()->override_source);

        $this->assertSame(1, Artisan::call('tool:global_config_override_adopt', [
            'keys' => [$key, $key],
        ]));
        $this->assertNull($row->fresh()->override_source);
    }

    public function test_system_boolean_override_rejects_invalid_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GlobalConfigOverrideManager::class)->setSystem(
            'location.pgsql_maintenance.dry_run',
            'unexpected'
        );
    }

    public function test_module_html_states_explicit_override_and_environment_baseline_semantics(): void
    {
        $html = $this->renderModuleHtml([
            'title' => '定位维护配置',
            'save_route' => 'configs.location_maintenance.save',
            'rows' => [[
                'key' => 'location.trajectory_segments.chunk_minutes',
                'name' => '展示轨迹分段分钟',
                'remark' => '',
                'env_key' => 'TRAJECTORY_SEGMENTS_CHUNK_MINUTES',
                'type' => 'integer',
                'ui' => 'input',
                'placeholder' => '',
                'family_key' => '',
                'family_label' => '',
                'baseline_value' => '27',
                'override_value' => '10',
                'effective_value' => '27',
                'effective_source' => 'laravel_config',
                'override_source' => null,
                'override_enabled' => false,
                'config_backed' => true,
                'override_policy' => 'manual',
                'sensitive' => false,
                'requires_reload' => false,
                'last_published_at' => null,
                'last_publish_error' => null,
            ]],
        ]);

        $this->assertStringNotContainsString('> 启用数据库覆盖</label>', $html);
        $this->assertStringContainsString('自动启用数据库覆盖', $html);
        $this->assertStringContainsString('环境值：', $html);
        $this->assertStringContainsString('数据库覆盖值：', $html);
        $this->assertStringContainsString('最终生效值：', $html);
        $this->assertStringContainsString('来源：环境配置', $html);
        $this->assertStringNotContainsString('overrides[location.trajectory_segments.chunk_minutes][enabled]', $html);
        $this->assertStringContainsString(
            'name="overrides[location.trajectory_segments.chunk_minutes][value]" value="27"',
            $html
        );
    }

    public function test_database_native_module_row_uses_normal_enable_control_without_override_wording(): void
    {
        $html = $this->renderModuleHtml([
            'title' => '定位算法配置',
            'save_route' => 'configs.location_algorithm.save',
            'rows' => [[
                'key' => 'location_args',
                'name' => '定位算法参数',
                'remark' => '',
                'env_key' => 'GLOBAL_CONFIG_LOCATION_ARGS',
                'type' => 'json',
                'ui' => 'textarea',
                'placeholder' => '',
                'family_key' => '',
                'family_label' => '',
                'baseline_value' => '{"speed_const":25}',
                'override_value' => '{"speed_const":30}',
                'effective_value' => '{"speed_const":30}',
                'effective_source' => 'database',
                'override_source' => null,
                'override_enabled' => true,
                'config_backed' => false,
                'override_policy' => 'manual',
                'sensitive' => false,
                'requires_reload' => false,
                'last_published_at' => null,
                'last_publish_error' => null,
            ]],
        ]);

        $this->assertStringContainsString('启用配置', $html);
        $this->assertStringContainsString('数据库值：', $html);
        $this->assertStringContainsString('代码兜底值：', $html);
        $this->assertStringContainsString('来源：数据库配置', $html);
        $this->assertStringNotContainsString('自动启用数据库覆盖', $html);
        $this->assertStringNotContainsString('数据库覆盖值：', $html);
    }

    public function test_real_location_modules_classify_database_native_and_config_backed_rows(): void
    {
        $form = new GlobalConfigModuleForm(new class extends NewConfigPublisher {
            public function __construct()
            {
            }
        });

        $algorithm = collect($form->snapshot('location_algorithm')['rows'])->keyBy('key');
        $maintenance = collect($form->snapshot('location_maintenance')['rows'])->keyBy('key');

        $this->assertFalse($algorithm['location_args']['config_backed']);
        $this->assertSame('code_default', $algorithm['location_args']['effective_source']);
        $this->assertTrue($maintenance['location.trajectory_segments.chunk_minutes']['config_backed']);
        $this->assertSame('laravel_config', $maintenance['location.trajectory_segments.chunk_minutes']['effective_source']);
    }

    public function test_database_native_module_form_keeps_normal_enable_semantics(): void
    {
        $form = new GlobalConfigModuleForm(new class extends NewConfigPublisher {
            public function __construct()
            {
            }
        });

        $snapshot = $form->save('basic', [
            'overrides' => [
                'debug_log' => [
                    'enabled' => '1',
                    'value' => '1',
                ],
            ],
        ]);

        $row = NewConfig::query()->where('key', 'debug_log')->firstOrFail();
        $this->createdKeys[] = 'debug_log';
        $this->assertSame(GlobalConfigOverrideSource::MANUAL, $row->override_source);
        $this->assertTrue((bool)$row->is_enabled);
        $snapshotRow = collect($snapshot['rows'])->firstWhere('key', 'debug_log');
        $this->assertSame('database', $snapshotRow['effective_source']);
        $this->assertSame('1', $snapshotRow['effective_value']);

        $snapshot = $form->save('basic', [
            'overrides' => [
                'debug_log' => [
                    'enabled' => '0',
                    'value' => '1',
                ],
            ],
        ]);

        $row->refresh();
        $this->assertSame('1', $row->value);
        $this->assertNull($row->override_source);
        $this->assertFalse((bool)$row->is_enabled);
        $snapshotRow = collect($snapshot['rows'])->firstWhere('key', 'debug_log');
        $this->assertSame('code_default', $snapshotRow['effective_source']);
        $this->assertSame('0', $snapshotRow['effective_value']);
    }

    public function test_config_backed_module_form_automatically_sets_and_clears_override_by_value(): void
    {
        $form = new GlobalConfigModuleForm(new class extends NewConfigPublisher {
            public function __construct()
            {
            }
        });
        $key = 'location.trajectory_segments.chunk_minutes';
        $baseline = (string)((int)config($key));
        $override = (string)(((int)$baseline) + 1);

        $snapshot = $form->save('location_maintenance', [
            'overrides' => [
                $key => ['value' => $override],
            ],
        ]);

        $row = NewConfig::query()->where('key', $key)->firstOrFail();
        $this->createdKeys[] = $key;
        $this->assertSame(GlobalConfigOverrideSource::MANUAL, $row->override_source);
        $this->assertTrue((bool)$row->is_enabled);
        $snapshotRow = collect($snapshot['rows'])->firstWhere('key', $key);
        $this->assertSame('database_override', $snapshotRow['effective_source']);
        $this->assertSame($override, $snapshotRow['effective_value']);

        $snapshot = $form->save('location_maintenance', [
            'overrides' => [
                $key => ['value' => $baseline],
            ],
        ]);

        $row->refresh();
        $this->assertNull($row->value);
        $this->assertNull($row->override_source);
        $this->assertFalse((bool)$row->is_enabled);
        $snapshotRow = collect($snapshot['rows'])->firstWhere('key', $key);
        $this->assertSame('laravel_config', $snapshotRow['effective_source']);
        $this->assertSame($baseline, $snapshotRow['effective_value']);
    }

    public function test_raw_admin_grids_never_render_app_secret_value(): void
    {
        $secret = 'phpunit-secret-must-not-appear';

        foreach ([ConfigController::class, NewConfigController::class] as $controller) {
            $method = new ReflectionMethod($controller, 'displayConfigValue');
            $method->setAccessible(true);
            $html = (string)$method->invoke(null, 'app_secret', $secret, 50);

            $this->assertStringContainsString('已脱敏', $html);
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    public function test_database_usage_document_is_byte_identical_to_repository_document(): void
    {
        $method = new ReflectionMethod(NewConfigDocumentSeeder::class, 'configurationUsageMarkdown');
        $method->setAccessible(true);

        $this->assertSame(
            file_get_contents(base_path('docs/configuration-usage.md')),
            $method->invoke(new NewConfigDocumentSeeder())
        );
    }

    private function createGlobalRow(string $key, string $value): NewConfig
    {
        $this->createdKeys[] = $key;

        return NewConfig::withoutEvents(static function () use ($key, $value): NewConfig {
            return NewConfig::query()->create([
                'key' => $key,
                'env_key' => 'PHPUNIT_' . strtoupper(substr(sha1($key), 0, 12)),
                'group_key' => NewConfig::GROUP_GLOBAL_CONFIG,
                'name' => $key,
                'type' => 'string',
                'value' => $value,
                'default_value' => null,
                'is_enabled' => true,
                'requires_reload' => false,
                'override_source' => null,
            ]);
        });
    }

    private function renderModuleHtml(array $snapshot): string
    {
        $method = new ReflectionMethod(ConfigModuleController::class, 'renderHtml');
        $method->setAccessible(true);

        return (string)$method->invoke(new ConfigModuleController(), $snapshot);
    }
}
