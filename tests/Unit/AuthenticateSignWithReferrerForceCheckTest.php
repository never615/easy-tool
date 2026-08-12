<?php

declare(strict_types=1);

namespace Mallto\Tool\Tests\Unit;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Middleware\AuthenticateSignWithReferrer;
use Mallto\Tool\Exception\SignException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AuthenticateSignWithReferrerForceCheckTest extends TestCase
{
    private string $originalDefaultConnection;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = DB::getDefaultConnection();
        config()->set('database.connections.force_auth_sign_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('force_auth_sign_testing');
        DB::setDefaultConnection('force_auth_sign_testing');
        Schema::create('app_secrets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('app_id')->unique();
            $table->string('app_secret')->nullable();
            $table->boolean('switch')->default(true);
            $table->timestamps();
        });
    }

    public function tearDown(): void
    {
        DB::disconnect('force_auth_sign_testing');
        DB::setDefaultConnection($this->originalDefaultConnection);
        DB::purge('force_auth_sign_testing');

        parent::tearDown();
    }

    public function test_local_environment_keeps_the_existing_bypass_by_default(): void
    {
        config()->set('app.env', 'local');
        config()->set('other.force_auth_sign_check', false);
        $middleware = new RecordingAuthenticateSignWithReferrer();

        $response = $middleware->handle(
            Request::create('/api/tp/location_result', 'POST'),
            static fn (): Response => new Response('next', 204)
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(0, $middleware->checkCalls);
    }

    public function test_force_check_runs_signature_boundary_before_local_and_referrer_bypasses(): void
    {
        config()->set('app.env', 'local');
        config()->set('other.force_auth_sign_check', true);
        $middleware = new RecordingAuthenticateSignWithReferrer();
        $request = Request::create('/api/tp/location_result', 'POST');
        $request->headers->set('Referer', 'https://allowed.example.test/map');
        $request->headers->set('User-Agent', 'okhttp/3.14.4');

        $response = $middleware->handle(
            $request,
            static fn (): Response => new Response('next', 204)
        );

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame('signature-check', $response->getContent());
        $this->assertSame(1, $middleware->checkCalls);
        $this->assertTrue($middleware->lastForced);
    }

    public function test_force_check_rejects_missing_app_id_even_when_the_regular_auth_switch_is_off(): void
    {
        config()->set('app.env', 'local');
        config()->set('other.auth_sign', false);
        config()->set('other.force_auth_sign_check', true);

        $this->expectException(SignException::class);
        $this->expectExceptionMessage('app_id 无效');

        (new AuthenticateSignWithReferrer())->handle(
            Request::create('/api/tp/location_result', 'POST'),
            static fn (): Response => new Response('must-not-pass', 204)
        );
    }
}

final class RecordingAuthenticateSignWithReferrer extends AuthenticateSignWithReferrer
{
    public int $checkCalls = 0;

    public bool $lastForced = false;

    protected function check(Request $request, Closure $next, bool $force = false): Response
    {
        $this->checkCalls++;
        $this->lastForced = $force;

        return new Response('signature-check', 418);
    }
}
