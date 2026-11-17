<?php

declare(strict_types=1);

namespace Mallto\Tool\Tests\Unit;

use DOMDocument;
use DOMXPath;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Mallto\Admin\Data\Administrator;
use Mallto\Tool\Controller\Admin\OwnerApiLogController;
use Mallto\Tool\Domain\Log\OwnerApiLogViewer;
use Mockery;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Tests\TestCase;

final class OwnerApiLogViewerTest extends TestCase
{
    private string $originalDefaultConnection;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = DB::getDefaultConnection();
        config([
            'database.connections.owner_api_log_viewer_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'session.driver' => 'array',
        ]);
        DB::purge('owner_api_log_viewer_testing');
        DB::setDefaultConnection('owner_api_log_viewer_testing');
        (require __DIR__ . '/../../migrations/2026_11_16_000001_create_owner_api_logs_table.php')->up();
        Schema::create('subjects', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('uuid')->nullable();
            $table->string('path')->nullable();
        });
        DB::table('subjects')->insert([
            ['id' => 1, 'uuid' => 'project-a', 'path' => ''],
            ['id' => 2, 'uuid' => 'project-a-child', 'path' => '.1.'],
            ['id' => 3, 'uuid' => 'project-b', 'path' => ''],
            ['id' => 4, 'uuid' => 'project-b-child', 'path' => '.3.'],
            ['id' => 5, 'uuid' => 'foreign-project', 'path' => ''],
            ['id' => 6, 'uuid' => '0', 'path' => '.1.'],
            ['id' => 7, 'uuid' => '', 'path' => '.1.'],
        ]);
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        view()->share('errors', new ViewErrorBag());
    }

    protected function tearDown(): void
    {
        DB::disconnect('owner_api_log_viewer_testing');
        DB::setDefaultConnection($this->originalDefaultConnection);
        DB::purge('owner_api_log_viewer_testing');
        parent::tearDown();
    }

    public function test_owner_can_read_all_records_including_unknown_or_global_uuid(): void
    {
        foreach (['project-a', 'foreign-project', null, '0', ''] as $uuid) {
            $this->log(['uuid' => $uuid]);
        }
        $this->assertSame(5, (new OwnerApiLogViewer())->query($this->admin(true))->count());
    }

    public function test_non_owner_sees_own_descendants_and_managed_subjects_but_no_foreign_or_global_records(): void
    {
        foreach (['project-a', 'project-a-child', 'project-b', 'project-b-child', 'foreign-project', null, '0', ''] as $uuid) {
            $this->log(['uuid' => $uuid]);
        }
        $admin = $this->admin(false, 1, [3]);
        $viewer = new OwnerApiLogViewer();
        $this->assertEqualsCanonicalizing(['project-a', 'project-a-child', 'project-b', 'project-b-child'],
            $viewer->query($admin)->pluck('uuid')->all());
        $this->assertSame(0, $viewer->query($admin, ['uuid' => 'foreign-project'])->count());
        $this->assertSame(0, $viewer->query($admin, ['uuid' => '0'])->count());
        $this->assertEqualsCanonicalizing(['project-a', 'project-a-child'],
            $viewer->query($this->admin(false, 1))->pluck('uuid')->all());
    }

    public function test_non_owner_without_valid_subject_roots_cannot_read_records(): void
    {
        $this->log();
        $this->assertSame(0, (new OwnerApiLogViewer())->query($this->admin(false, null, ['invalid', 0, -1]))->count());
    }

    public function test_filters_match_exact_ids_action_method_status_time_and_literal_url_characters(): void
    {
        $id = $this->log(['request_id' => 'matching-request', 'action' => '响应', 'status' => 200,
            'url' => 'https://example.test/api/tp/locator?name=a%_!b', 'created_at' => '2026-11-16 10:00:00']);
        $this->log(['request_id' => 'matching-request-extra', 'action' => '响应', 'status' => 200,
            'url' => 'https://example.test/api/tp/locator?name=aXXb', 'created_at' => '2026-11-16 10:00:00']);
        $this->log(['request_id' => 'matching-request', 'action' => '请求', 'status' => null,
            'url' => 'https://example.test/api/tp/locator?name=a%_!b', 'created_at' => '2026-11-16 10:00:00']);
        $this->log(['request_id' => 'matching-request', 'action' => '响应', 'status' => 500,
            'url' => 'https://example.test/api/tp/locator?name=a%_!b', 'created_at' => '2026-11-16 09:59:59']);
        $filters = ['request_id' => 'matching-request', 'uuid' => 'project-a', 'action' => '响应',
            'method' => 'GET', 'status' => '200', 'url' => 'a%_!b',
            'created_from' => '2026-11-16 10:00:00', 'created_to' => '2026-11-16 10:00:00'];
        $viewer = new OwnerApiLogViewer();
        $this->assertSame([$id], $viewer->query($this->admin(true), $filters)->pluck('id')->all());
        $this->assertCount(3, $viewer->query($this->admin(true), ['url' => 'a%_!b'])->get());
        $this->assertSame(0, $viewer->query($this->admin(true), ['method' => 'DELETE'])->count());
    }

    public function test_index_paginates_twenty_records_in_time_order_keeps_filters_and_does_not_select_payloads(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $this->log(['request_id' => 'paged-request', 'input' => 'PRIVATE_PAYLOAD_' . $i]);
        }
        $older = $this->log(['request_id' => 'paged-request', 'created_at' => '2026-11-15 00:00:00']);
        $request = $this->request(['request_id' => 'paged-request', 'method' => 'GET']);
        DB::enableQueryLog();
        $html = $this->pageHtml($this->admin(true), '数据库记录',
            fn () => (new OwnerApiLogController())->index($request, new OwnerApiLogViewer()));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $xpath = $this->xpath($html);
        $this->assertSame(20, $xpath->query('//tbody/tr')->length);
        $this->assertSame('22', trim($xpath->query('//tbody/tr[1]/td[1]')->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//tbody/tr/td[1][text()="' . $older . '"]')->length);
        $next = $xpath->query('//a[@rel="next"]')->item(0)->getAttribute('href');
        parse_str((string) parse_url($next, PHP_URL_QUERY), $nextFilters);
        $this->assertSame('paged-request', $nextFilters['request_id']);
        $this->assertSame('GET', $nextFilters['method']);
        $this->assertSame('2', $nextFilters['page']);
        $this->assertStringNotContainsString('PRIVATE_PAYLOAD_', $html);
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertStringContainsString('count(', strtolower($sql));
        $this->assertStringNotContainsString('"input"', $sql);
        $this->assertStringNotContainsString('"header"', $sql);
        $this->assertStringContainsString('limit 20', strtolower($sql));
        $this->assertSame(1, $xpath->query('//ul[contains(@class,"pagination")]//a[text()="2"]')->length);
        $this->assertSame(1, $xpath->query('//select[contains(@class,"per-page")]')->length);
        $this->assertSame(0, $xpath->query('//a[contains(@href,"/edit")]|//a[contains(@class,"grid-row-delete")]|//input[contains(@class,"grid-row-checkbox")]')->length);
    }

    public function test_index_displays_backend_notice_and_safe_empty_state(): void
    {
        config(['app.log.logger' => 'Mallto\\Tool\\Domain\\Log\\LoggerAliyun', 'app.db_log' => false]);
        $request = $this->request(['url' => '"><script>alert(1)</script>']);
        $html = $this->pageHtml($this->admin(true), '数据库记录',
            fn () => (new OwnerApiLogController())->index($request, new OwnerApiLogViewer()));
        $this->assertStringContainsString('当前部署默认未启用数据库日志后端', $html);
        $this->assertSame(1, $this->xpath($html)->query('//td[contains(@class,"empty-grid")]')->length);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_grid_page_numbers_and_page_size_preserve_project_scope_and_filters(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->log(['request_id' => 'paged-request']);
        }
        $this->log(['request_id' => 'another-request']);
        $this->log(['request_id' => 'paged-request', 'uuid' => 'foreign-project']);
        foreach ([[20, 2, 20, '35'], [50, 1, 50, '55'], [50, 2, 5, '5']] as [$perPage, $page, $count, $firstId]) {
            $request = $this->request(['request_id' => 'paged-request', 'per_page' => $perPage, 'page' => $page]);
            $html = $this->pageHtml($this->admin(false, 1), '数据库记录',
                fn () => (new OwnerApiLogController())->index($request, new OwnerApiLogViewer()));
            $xpath = $this->xpath($html);
            $this->assertSame($count, $xpath->query('//tbody/tr')->length);
            $this->assertSame($firstId, trim($xpath->query('//tbody/tr[1]/td[1]')->item(0)->textContent));
            $this->assertSame((string) $page, trim($xpath->query('//ul[contains(@class,"pagination")]/li[contains(@class,"active")]/span')->item(0)->textContent));
            $this->assertSame((string) $perPage, trim($xpath->query('//select[contains(@class,"per-page")]/option[@selected]')->item(0)->textContent));
            $this->assertStringNotContainsString('foreign-project', $html);
            $this->assertStringNotContainsString('another-request', $html);
        }
    }

    public function test_index_rejects_invalid_filters_before_querying_logs(): void
    {
        foreach (['status' => 99, 'method' => 'TRACE', 'action' => 'delete', 'page' => 0,
            'created_from' => 'not-a-date', 'request_id' => str_repeat('x', 65), 'per_page' => 1000] as $key => $value) {
            try {
                (new OwnerApiLogController())->index($this->request([$key => $value]), new OwnerApiLogViewer());
                $this->fail('Expected validation to reject ' . $key);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }
    }

    public function test_index_rejects_direct_grid_export_requests(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionCode(0);
        (new OwnerApiLogController())->index($this->request(['_export_' => 'all']), new OwnerApiLogViewer());
    }

    public function test_index_accepts_end_only_time_filter_and_rejects_reversed_range(): void
    {
        $this->log(['created_at' => '2026-11-16 10:00:00']);
        $request = $this->request(['created_to' => '2026-11-16 10:00:00']);
        $html = $this->pageHtml($this->admin(true), '数据库记录',
            fn () => (new OwnerApiLogController())->index($request, new OwnerApiLogViewer()));
        $this->assertSame(1, $this->xpath($html)->query('//tbody/tr/td[1]')->length);
        try {
            (new OwnerApiLogController())->index($this->request([
                'created_from' => '2026-11-17 00:00:00', 'created_to' => '2026-11-16 00:00:00',
            ]), new OwnerApiLogViewer());
            $this->fail('Expected a reversed range to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('created_to', $exception->errors());
        }
    }

    public function test_show_pairs_same_request_and_uuid_only_and_escapes_all_payload_html(): void
    {
        $id = $this->log(['request_id' => 'paired-request', 'header' => '{"x-test":"<script>alert(1)</script>"}',
            'input' => '{"query":"<b>request marker</b>"}', 'url' => 'https://example.test/<svg onload=alert(1)>']);
        $this->log(['request_id' => 'paired-request', 'action' => '响应', 'status' => 200,
            'input' => '{"message":"response marker","data":[1]}']);
        $this->log(['request_id' => 'paired-request', 'uuid' => 'project-a-child', 'input' => 'OTHER_VISIBLE_UUID']);
        $this->log(['request_id' => 'paired-request', 'uuid' => 'foreign-project', 'input' => 'FOREIGN_UUID']);
        $this->log(['request_id' => 'other-request', 'input' => 'OTHER_REQUEST']);
        $html = $this->pageHtml($this->admin(false, 1), '请求与响应详情',
            fn () => (new OwnerApiLogController())->show($id, new OwnerApiLogViewer()));
        $this->assertStringContainsString('response marker', $html);
        $this->assertStringContainsString('&lt;b&gt;request marker&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;svg onload=alert(1)&gt;', $html);
        foreach (['<script>', '<b>request marker</b>', '<svg ', 'FOREIGN_UUID', 'OTHER_VISIBLE_UUID', 'OTHER_REQUEST'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertSame(2, $this->xpath($html)->query('//pre[preceding-sibling::h4[text()="请求头"]]')->length / 2);
    }

    public function test_show_denies_direct_foreign_record_access(): void
    {
        $id = $this->log(['uuid' => 'foreign-project']);
        Admin::shouldReceive('user')->once()->andReturn($this->admin(false, 1));
        $this->expectException(ModelNotFoundException::class);
        (new OwnerApiLogController())->show($id, new OwnerApiLogViewer());
    }

    public function test_show_preserves_truncated_text_and_includes_selected_record_with_twenty_record_limit(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $id = $this->log(['request_id' => 'retries', 'input' => '{"partial":' . $i . '...[truncated]']);
        }
        $html = $this->pageHtml($this->admin(true), '请求与响应详情',
            fn () => (new OwnerApiLogController())->show($id, new OwnerApiLogViewer()));
        $this->assertSame(20, $this->xpath($html)->query('//h3[contains(text(), "记录 #")]')->length);
        $this->assertStringContainsString('记录 #' . $id, $html);
        $this->assertStringContainsString('25...[truncated]', $html);
    }

    public function test_payload_formatter_handles_json_and_raw_or_missing_values(): void
    {
        $this->assertSame("{\n    \"message\": \"中文\",\n    \"url\": \"https://example.test\"\n}",
            OwnerApiLogViewer::formatPayload('{"message":"中文","url":"https://example.test"}'));
        $this->assertSame('{"partial":...[truncated]', OwnerApiLogViewer::formatPayload('{"partial":...[truncated]'));
        $this->assertSame('plain text', OwnerApiLogViewer::formatPayload('plain text'));
        $this->assertSame('—', OwnerApiLogViewer::formatPayload(null));
        $this->assertSame('null', OwnerApiLogViewer::formatPayload('null'));
    }

    public function test_payload_formatter_preserves_object_shape_numeric_precision_and_string_tokens(): void
    {
        $cases = [
            '{}' => '{}',
            '{"0":"zero","1":"one"}' => "{\n    \"0\": \"zero\",\n    \"1\": \"one\"\n}",
            '{"id":1844674407370955161612345,"ratio":0.123456789012345678901234567890,"scientific":1.234567890123456789e+100}' =>
                "{\n    \"id\": 1844674407370955161612345,\n    \"ratio\": 0.123456789012345678901234567890,\n    \"scientific\": 1.234567890123456789e+100\n}",
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, OwnerApiLogViewer::formatPayload($input));
        }
        $payload = <<<'JSON'
{"message":"space  stays; braces { [ ] }; quote \" and slash \\ end","nested":[{},{"0":"x"}]}
JSON;
        $expected = <<<'JSON'
{
    "message": "space  stays; braces { [ ] }; quote \" and slash \\ end",
    "nested": [
        {},
        {
            "0": "x"
        }
    ]
}
JSON;
        $this->assertSame($expected, OwnerApiLogViewer::formatPayload($payload));
    }

    public function test_routes_are_admin_protected_read_only_and_keep_existing_owner_logs(): void
    {
        $routes = $this->app['router']->getRoutes();
        foreach (['index', 'show'] as $action) {
            $route = $routes->getByName('owner_api_logs.' . $action);
            $this->assertNotNull($route);
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            foreach (['web', 'adminE_base', 'adminE.auto_permission'] as $middleware) {
                $this->assertContains($middleware, $route->gatherMiddleware());
            }
            $this->assertStringContainsString(OwnerApiLogController::class, $route->getActionName());
        }
        foreach (['store', 'create', 'edit', 'update', 'destroy'] as $action) {
            $this->assertNull($routes->getByName('owner_api_logs.' . $action));
        }
        $this->assertNotNull($routes->getByName('owner_logs.index'));
        foreach (['POST' => '/admin/owner_api_logs', 'PATCH' => '/admin/owner_api_logs/1', 'DELETE' => '/admin/owner_api_logs/1'] as $method => $path) {
            try {
                $routes->match(Request::create($path, $method));
                $this->fail('Unexpected writable route for ' . $method);
            } catch (MethodNotAllowedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function admin(bool $owner, ?int $subjectId = 1, array $managed = []): Administrator
    {
        $admin = Mockery::mock(Administrator::class)->makePartial();
        $admin->shouldReceive('isOwner')->andReturn($owner);
        $admin->subject_id = $subjectId;
        $admin->manager_subject_ids = $managed;
        return $admin;
    }

    private function log(array $attributes = []): int
    {
        return (int) DB::table('owner_api_logs')->insertGetId(array_replace([
            'request_id' => 'request-' . bin2hex(random_bytes(8)), 'uuid' => 'project-a',
            'action' => '请求', 'method' => 'GET', 'url' => 'https://example.test/api/tp/locator',
            'header' => '{"signature":"[REDACTED]"}', 'input' => '{"page":1}', 'status' => null,
            'created_at' => '2026-11-16 10:00:00', 'updated_at' => '2026-11-16 10:00:00',
        ], $attributes));
    }

    private function request(array $filters): Request
    {
        $request = Request::create('/admin/owner_api_logs', 'GET', $filters);
        $request->setLaravelSession($this->app['session']->driver());
        $this->app->instance('request', $request);
        return $request;
    }

    private function pageHtml(Administrator $admin, string $description, callable $callback): string
    {
        $html = '';
        Admin::shouldReceive('user')->andReturn($admin);
        Admin::shouldReceive('script')->andReturnNull();
        $content = Mockery::mock(Content::class);
        $content->shouldReceive('header')->once()->with('入站接口日志');
        $content->shouldReceive('description')->once()->with($description);
        $content->shouldReceive('body')->once()->andReturnUsing(function (string $body) use (&$html): void {
            $html = $body;
        });
        Admin::shouldReceive('content')->once()->andReturnUsing(static function (callable $build) use ($content) {
            $build($content);
            return $content;
        });
        $callback();
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
