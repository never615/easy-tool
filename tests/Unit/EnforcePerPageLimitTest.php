<?php

namespace Mallto\Tool\Tests\Unit;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mallto\Tool\Middleware\EnforcePerPageLimit;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class EnforcePerPageLimitTest extends TestCase
{
    public function test_provider_registers_the_middleware_with_the_global_http_kernel(): void
    {
        $kernel = $this->app->make(HttpKernelContract::class);

        $this->assertInstanceOf(HttpKernel::class, $kernel);
        $this->assertTrue($kernel->hasMiddleware(EnforcePerPageLimit::class));
    }

    public function test_allows_the_global_maximum(): void
    {
        $this->assertSame('passed', $this->handle('/admin/api/locator', [
            'per_page' => 1000,
        ])->getContent());
    }

    public function test_rejects_every_numeric_value_above_the_global_maximum(): void
    {
        $this->app['config']->set('easy_tool.pagination.per_page_max', 9999);

        foreach ([1001, 9999] as $perPage) {
            try {
                $this->handle('/admin/api/locator', ['per_page' => $perPage]);
                $this->fail("Expected per_page={$perPage} to be rejected.");
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
                $this->assertArrayHasKey('per_page', $exception->errors());
            }
        }
    }

    public function test_global_http_stack_returns_422_validation_responses(): void
    {
        $this->getJson('/admin/api/tags?per_page=1001')
            ->assertUnprocessable()
            ->assertJsonStructure(['error', 'message']);

        $this->withHeader('protocol_version', '2')
            ->getJson('/admin/api/tags?per_page=1001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_bypasses_mongodb_and_mixed_history_paths(): void
    {
        $this->app['config']->set('easy_tool.pagination.per_page_limit_excluded_paths', []);

        foreach ([
            '/api/lbs/location/histories',
            '/api/lbs/location/histories/date/list',
            '/admin/api/lbs/history_locator',
            '/api/lbs/history_locator',
            '/api/tp/lbs/history_locator',
        ] as $path) {
            $this->assertSame('passed', $this->handle($path, ['per_page' => 9999])->getContent());
        }
    }

    public function test_preserves_zero_for_place_door_compatibility(): void
    {
        $this->assertSame('passed', $this->handle('/admin/api/place_door', [
            'per_page' => 0,
        ])->getContent());
    }

    public function test_ignores_other_pagination_parameter_names(): void
    {
        $this->assertSame('passed', $this->handle('/admin/api/locator', [
            'rows_per_page' => 9999,
        ])->getContent());
        $this->assertSame('passed', $this->handle('/admin/api/locator', [
            'perPage' => 9999,
        ])->getContent());
    }

    private function handle(string $path, array $parameters): Response
    {
        $request = Request::create($path, 'GET', $parameters);

        return (new EnforcePerPageLimit())->handle(
            $request,
            static fn (Request $request): Response => new Response('passed')
        );
    }
}
