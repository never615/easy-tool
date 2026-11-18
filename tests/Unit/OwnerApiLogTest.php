<?php

declare(strict_types=1);

namespace Mallto\Tool\Tests\Unit;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Commands\PruneOwnerApiLogsCommand;
use Mallto\Tool\Domain\Log\LoggerDb;
use Mallto\Tool\Domain\Log\OwnerApiLogFilter;
use Mallto\Tool\Domain\Log\OwnerApiLogPayload;
use Mallto\Tool\Domain\Log\OwnerApiLogSettings;
use Mallto\Tool\Domain\NewConfig\GlobalConfigRuntimeResolver;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Middleware\OwnerApiLog;
use Mallto\Tool\Providers\ToolServiceProvider;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class OwnerApiLogTest extends TestCase
{
    private string $originalDefaultConnection;


    public function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = DB::getDefaultConnection();
        config(['database.connections.owner_api_log_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('owner_api_log_testing');
        DB::setDefaultConnection('owner_api_log_testing');
        $this->migration()->up();

        config([
            'app.log.owner_api' => true,
            'app.db_log' => true,
            'app.log.dispatch_now' => false,
            'auth.guards.api.provider' => null,
            'owner_api_log.include' => [],
            'owner_api_log.retention_days' => 7,
            'owner_api_log.cleanup_enabled' => true,
            'owner_api_log.cleanup_at' => '04:20',
            'owner_api_log.cleanup_batch_size' => 500,
            'owner_api_log.max_text_bytes' => 65536,
            'owner_api_log.hard_exclude' => [],
        ]);
        $settings = Mockery::mock(OwnerApiLogSettings::class);
        $settings->shouldReceive('forRequest')->andReturnUsing(fn (): array => [
            'enabled' => (bool) config('app.log.owner_api'),
            'include' => config('owner_api_log.include', []),
        ]);
        $settings->shouldReceive('retentionDays')->andReturnUsing(
            static fn () => config('owner_api_log.retention_days', 7)
        );
        $this->app->instance(OwnerApiLogSettings::class, $settings);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('owner_api_log_testing');
        DB::setDefaultConnection($this->originalDefaultConnection);
        DB::purge('owner_api_log_testing');
        parent::tearDown();
    }

    public function test_whitelist_matches_full_path_and_all_methods_without_query_parameters(): void
    {
        config(['owner_api_log.include' => ['api/tp/locator']]);

        $this->assertTrue($this->logs('/api/tp/locator?third_slug=45C671000141&with_area=1'));
        $this->assertTrue($this->logs('/api/tp/locator?third_slug=different'));
        $this->assertTrue($this->logs('/api/tp/locator', 'POST'));
        $this->assertFalse($this->logs('/admin/api/locator'));
        $this->assertFalse($this->logs('/api/tp/locator/42'));

        $filter = new OwnerApiLogFilter();
        $this->assertTrue($filter->matches(Request::create('/api/tp/locator/42', 'PUT'),
            [' /api/tp/locator/*/ ']));
        $this->assertTrue($filter->matches(Request::create('/api/tp/locator/42', 'DELETE'),
            ['api/tp/locator/*']));
        $this->assertFalse($filter->matches(Request::create('/api/tp/locator'),
            ['', null, 42, 'GET:api/tp/locator', 'api/tp/locator?with_area=1']));
    }

    public function test_empty_whitelist_and_global_switch_do_not_collect(): void
    {
        config(['owner_api_log.include' => []]);
        $this->assertFalse($this->logs('/api/tp/locator'));
        config(['owner_api_log.include' => ['api/tp/locator'], 'app.log.owner_api' => false]);
        $this->assertFalse($this->logs('/api/tp/locator'));
    }

    public function test_obsolete_blacklist_settings_cannot_expand_collection(): void
    {
        config(['owner_api_log.filter_mode' => 'blacklist', 'owner_api_log.exclude' => [],
            'app.log.except' => [], 'owner_api_log.include' => ['api/tp/locator']]);
        $resolver = Mockery::mock();
        $resolver->shouldReceive('getMany')->with([
            'owner_api_log.enabled' => true, 'owner_api_log.rules' => '',
        ])->andReturnUsing(static fn (array $defaults): array => $defaults);
        $this->app->instance(GlobalConfigRuntimeResolver::class, $resolver);
        $this->app->instance(OwnerApiLogSettings::class, new OwnerApiLogSettings());
        $this->assertTrue($this->logs('/api/tp/locator'));
        $this->assertFalse($this->logs('/api/tp/floor'));
        config(['owner_api_log.include' => []]);
        $this->assertFalse($this->logs('/api/tp/locator'));
    }

    public function test_static_high_frequency_exclusion_skips_settings_and_jobs_before_any_runtime_lookup(): void
    {
        config(['owner_api_log.hard_exclude' => ['api/lbs/*'],
            'owner_api_log.include' => ['*']]);
        $this->app->forgetInstance(OwnerApiLogSettings::class);
        $this->app->bind(OwnerApiLogSettings::class, static function (): never {
            throw new \LogicException('Statically excluded requests must not resolve runtime settings');
        });
        $resolver = Mockery::mock();
        $resolver->shouldNotReceive('getMany');
        $resolver->shouldNotReceive('get');
        $this->app->instance(GlobalConfigRuntimeResolver::class, $resolver);
        Bus::fake([LogJob::class]);

        $request = Request::create('/api/lbs/location_data?token=fake-token', 'POST');
        $this->assertFalse($this->logs('/api/lbs/location_data', 'POST'));
        $response = (new OwnerApiLog())->handle($request,
            static fn () => response()->json(['accepted' => true]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['accepted']);
        $this->assertFalse($response->headers->has('request-id'));
        Bus::assertNothingDispatched();
    }

    public function test_real_settings_batch_runtime_reads_use_deployment_whitelist_when_no_override_exists(): void
    {
        config(['app.log.owner_api' => true, 'owner_api_log.include' => ['api/tp/locator']]);
        $resolver = Mockery::mock();
        $resolver->shouldReceive('getMany')->once()->with([
            'owner_api_log.enabled' => true,
            'owner_api_log.rules' => '',
        ])->andReturnUsing(static fn (array $defaults): array => $defaults);
        $resolver->shouldNotReceive('get');
        $this->app->instance(GlobalConfigRuntimeResolver::class, $resolver);

        $settings = (new OwnerApiLogSettings())->forRequest();
        $this->assertTrue($settings['enabled']);
        $this->assertSame(['api/tp/locator'], $settings['include']);
    }

    public function test_real_settings_preserve_explicit_disabled_collection_and_per_rule_switches(): void
    {
        config(['app.log.owner_api' => true]);
        $resolver = Mockery::mock();
        $resolver->shouldReceive('getMany')->once()->andReturn([
            'owner_api_log.enabled' => false,
            'owner_api_log.rules' => json_encode([
                ['path' => 'api/tp/locator', 'enabled' => true],
                ['path' => 'api/tp/locator/*', 'enabled' => false],
            ], JSON_THROW_ON_ERROR),
        ]);
        $resolver->shouldNotReceive('get');
        $this->app->instance(GlobalConfigRuntimeResolver::class, $resolver);

        $settings = (new OwnerApiLogSettings())->forRequest();
        $this->assertFalse($settings['enabled']);
        $this->assertSame(['api/tp/locator'], $settings['include']);
    }

    public function test_real_settings_empty_or_invalid_rule_overrides_fail_closed_instead_of_using_legacy_allow_all(): void
    {
        config(['owner_api_log.filter_mode' => 'blacklist', 'owner_api_log.exclude' => []]);
        $resolver = Mockery::mock();
        foreach (['[]', [], '{invalid-json'] as $rules) {
            $resolver->shouldReceive('getMany')->once()->ordered()->andReturn([
                'owner_api_log.enabled' => true, 'owner_api_log.rules' => $rules,
            ]);
        }
        $this->app->instance(GlobalConfigRuntimeResolver::class, $resolver);
        foreach (range(1, 3) as $_) {
            $settings = (new OwnerApiLogSettings())->forRequest();
            $this->assertSame([], $settings['include']);
        }
    }

    public function test_real_settings_retention_uses_cached_resolver_with_configured_fallback(): void
    {
        config(['owner_api_log.retention_days' => 14]);
        $resolver = Mockery::mock();
        $resolver->shouldReceive('get')->once()->with('owner_api_log.retention_days', 14)->andReturn(3);
        $resolver->shouldNotReceive('getMany');
        $this->app->instance(GlobalConfigRuntimeResolver::class, $resolver);
        $this->assertSame(3, (new OwnerApiLogSettings())->retentionDays());
    }

    public function test_payload_redacts_nested_headers_body_and_url_before_storage(): void
    {
        $payload = new OwnerApiLogPayload();
        $source = ['Authorization' => ['fake-bearer'], 'Cookie' => ['fake-cookie'],
            'signature_nonce' => 'fake-nonce', 'nested' => ['app_secret' => 'fake-secret',
                'custom_token' => 'fake-token', 'name' => 'keep-name'], 'third_slug' => '45C671000141'];
        $decoded = json_decode($payload->json($source), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('[REDACTED]', $decoded['Authorization']);
        $this->assertSame('[REDACTED]', $decoded['Cookie']);
        $this->assertSame('[REDACTED]', $decoded['signature_nonce']);
        $this->assertSame('[REDACTED]', $decoded['nested']['app_secret']);
        $this->assertSame('[REDACTED]', $decoded['nested']['custom_token']);
        $this->assertSame('keep-name', $decoded['nested']['name']);
        $this->assertSame('45C671000141', $decoded['third_slug']);
        $this->assertSame(['fake-bearer'], $source['Authorization']);

        $url = $payload->url('https://example.test/api/tp/locator?token=fake-token&third_slug=45C671000141');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('[REDACTED]', $query['token']);
        $this->assertSame('45C671000141', $query['third_slug']);
        $this->assertStringNotContainsString('fake-token', $url);
    }

    public function test_payload_limits_bytes_without_cutting_utf8_sequences(): void
    {
        config(['owner_api_log.max_text_bytes' => 256]);
        $payload = new OwnerApiLogPayload();
        foreach ([$payload->text(str_repeat('日志', 200)),
            $payload->json(['body' => str_repeat('日志', 200)]),
            $payload->url('https://example.test/api?body=' . str_repeat('a', 400))] as $value) {
            $this->assertLessThanOrEqual(256, strlen($value));
            $this->assertStringEndsWith('...[truncated]', $value);
            $this->assertTrue(mb_check_encoding($value, 'UTF-8'));
        }
        $this->assertSame('small', $payload->text('small'));
    }

    public function test_middleware_dispatches_paired_sanitized_logs_and_preserves_business_response(): void
    {
        config(['owner_api_log.include' => ['api/tp/locator']]);
        Bus::fake([LogJob::class]);
        $request = Request::create('/api/tp/locator?third_slug=45C671000141&token=fake-token');
        $request->headers->set('X-UUID', 'test-project');
        $request->headers->set('Authorization', 'fake-authorization');
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');

        $response = (new OwnerApiLog())->handle($request, function (Request $incoming) {
            $this->assertNotEmpty($incoming->header('request-id'));
            return response()->json(['data' => [], 'access_token' => 'fake-response-token']);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fake-response-token', $response->getData(true)['access_token']);
        $logs = [];
        Bus::assertDispatched(LogJob::class, function (LogJob $job) use (&$logs): bool {
            $this->assertSame('logOwnerApi', (new ReflectionProperty($job, 'type'))->getValue($job));
            $logs[] = (new ReflectionProperty($job, 'content'))->getValue($job);
            return true;
        });
        Bus::assertDispatchedTimes(LogJob::class, 2);
        $this->assertCount(2, $logs);
        $this->assertSame(['请求', '响应'], array_column($logs, 'action'));
        $this->assertSame($logs[0]['request_id'], $logs[1]['request_id']);
        $this->assertSame($request->header('request-id'), $response->headers->get('request-id'));
        $this->assertSame($logs[0]['request_id'], $response->headers->get('request-id'));
        $this->assertSame(200, $logs[1]['status']);
        $this->assertStringNotContainsString('fake-authorization', $logs[0]['header']);
        $this->assertStringNotContainsString('fake-token', $logs[0]['input']);
        $this->assertStringNotContainsString('fake-token', $logs[0]['url']);
        $this->assertStringNotContainsString('fake-response-token', $logs[1]['input']);
    }

    public function test_unlisted_request_skips_both_log_jobs(): void
    {
        config(['owner_api_log.include' => []]);
        Bus::fake([LogJob::class]);
        $response = (new OwnerApiLog())->handle(Request::create('/api/tp/locator'),
            static fn () => response()->json(['data' => []]));
        $this->assertSame(200, $response->getStatusCode());
        Bus::assertNothingDispatched();
    }

    public function test_database_logger_switch_and_field_allowlist(): void
    {
        config(['app.db_log' => false]);
        (new LoggerDb())->logOwnerApi($this->row());
        $this->assertSame(0, DB::table('owner_api_logs')->count());

        config(['app.db_log' => true]);
        (new LoggerDb())->logOwnerApi($this->row() + ['untrusted_column' => 'ignore']);
        $stored = DB::table('owner_api_logs')->first();
        $this->assertSame('test-request', $stored->request_id);
        $this->assertSame('test-project', $stored->uuid);
        $this->assertSame('GET', $stored->method);
        $this->assertSame('请求', $stored->action);
        $this->assertSame('{"data":[]}', $stored->input);
        $this->assertNotNull($stored->created_at);
    }

    public function test_database_logger_propagates_write_failures_to_existing_job_retry(): void
    {
        Schema::drop('owner_api_logs');
        $this->expectException(QueryException::class);
        (new LoggerDb())->logOwnerApi($this->row());
    }

    public function test_cleanup_defaults_to_seven_days_and_keeps_exact_boundary_and_other_logs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00'));
        config(['app.log.owner_api' => false, 'app.db_log' => false]);
        $this->seedAt(['2026-11-09 11:59:59', '2026-11-09 12:00:00', '2026-11-16 11:00:00']);
        Schema::create('third_api_logs', static function (Blueprint $table): void {
            $table->increments('id');
            $table->timestamp('created_at');
        });
        DB::table('third_api_logs')->insert(['created_at' => '2025-01-01 00:00:00']);

        $tester = $this->prune();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Deleted: 1', $tester->getDisplay());
        $this->assertSame(['2026-11-09 12:00:00', '2026-11-16 11:00:00'],
            DB::table('owner_api_logs')->orderBy('id')->pluck('created_at')->all());
        $this->assertSame(1, DB::table('third_api_logs')->count());
    }

    public function test_cleanup_dry_run_counts_without_deleting_and_uses_configured_retention(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00'));
        config(['owner_api_log.retention_days' => 2]);
        $this->seedAt(['2026-11-13 12:00:00', '2026-11-14 12:00:00']);
        $tester = $this->prune(['--dry-run' => true]);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Expired: 1', $tester->getDisplay());
        $this->assertSame(2, DB::table('owner_api_logs')->count());
        $this->assertSame(0, $this->prune()->getStatusCode());
        $this->assertSame(1, DB::table('owner_api_logs')->count());
    }

    public function test_cleanup_deletes_expired_rows_in_bounded_batches(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00'));
        config(['owner_api_log.cleanup_batch_size' => 2]);
        $this->seedAt(array_fill(0, 5, '2026-11-01 00:00:00'));
        DB::connection()->enableQueryLog();
        $tester = $this->prune();
        $deletes = array_values(array_filter(DB::connection()->getQueryLog(),
            static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'delete')));
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertCount(3, $deletes);
        foreach ($deletes as $query) {
            // 一个截止值绑定参数，加上最多两个选中主键。
            $this->assertLessThanOrEqual(3, count($query['bindings']));
        }
        $this->assertStringContainsString('Deleted: 5', $tester->getDisplay());
        $this->assertSame(0, DB::table('owner_api_logs')->count());
    }

    public function test_cleanup_rejects_invalid_retention_or_batch_configuration_without_deleting(): void
    {
        $this->seedAt(['2025-01-01 00:00:00']);
        foreach ([['owner_api_log.retention_days', 0], ['owner_api_log.retention_days', 'invalid'],
            ['owner_api_log.retention_days', 36501], ['owner_api_log.cleanup_batch_size', 0],
            ['owner_api_log.cleanup_batch_size', 5001]] as [$key, $value]) {
            config(['owner_api_log.retention_days' => 7, 'owner_api_log.cleanup_batch_size' => 500, $key => $value]);
            $this->assertSame(2, $this->prune()->getStatusCode());
            $this->assertSame(1, DB::table('owner_api_logs')->count());
        }
    }

    public function test_scheduler_registers_daily_cleanup_even_when_collection_is_disabled(): void
    {
        config(['app.log.owner_api' => false]);
        $schedule = new Schedule('Asia/Shanghai');
        $this->scheduleCleanup($schedule);
        $this->assertCount(1, $schedule->events());
        $event = $schedule->events()[0];
        $this->assertSame('20 4 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $this->assertStringContainsString('tool:prune_owner_api_logs', $event->command);

        config(['owner_api_log.cleanup_enabled' => false]);
        $disabled = new Schedule('Asia/Shanghai');
        $this->scheduleCleanup($disabled);
        $this->assertCount(0, $disabled->events());
    }

    public function test_scheduler_supports_configured_time_and_rejects_invalid_time(): void
    {
        config(['owner_api_log.cleanup_at' => '02:35']);
        $schedule = new Schedule('Asia/Shanghai');
        $this->scheduleCleanup($schedule);
        $this->assertSame('35 2 * * *', $schedule->events()[0]->expression);
        config(['owner_api_log.cleanup_at' => '25:99']);
        $this->expectException(\InvalidArgumentException::class);
        $this->scheduleCleanup(new Schedule('Asia/Shanghai'));
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 2) . '/migrations/2026_11_16_000001_create_owner_api_logs_table.php';
    }

    private function logs(string $url, string $method = 'GET'): bool
    {
        return (new ReflectionMethod(OwnerApiLog::class, 'shouldLogOperation'))
            ->invoke(new OwnerApiLog(), Request::create($url, $method));
    }

    private function row(): array
    {
        return ['request_id' => 'test-request', 'uuid' => 'test-project', 'action' => '请求',
            'method' => 'GET', 'url' => '/api/tp/locator', 'request_ip' => '127.0.0.1',
            'user_id' => 0, 'header' => '{}', 'input' => '{"data":[]}'];
    }

    private function seedAt(array $timestamps): void
    {
        foreach ($timestamps as $timestamp) {
            DB::table('owner_api_logs')->insert($this->row() + ['created_at' => $timestamp, 'updated_at' => $timestamp]);
        }
    }

    private function prune(array $input = []): CommandTester
    {
        $command = new PruneOwnerApiLogsCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute($input);
        return $tester;
    }

    private function scheduleCleanup(Schedule $schedule): void
    {
        (new ReflectionMethod(ToolServiceProvider::class, 'scheduleOwnerApiLogCleanup'))
            ->invoke(new ToolServiceProvider($this->app), $schedule);
    }
}
