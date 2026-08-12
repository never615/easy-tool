<?php

declare(strict_types=1);

namespace Mallto\Tool\Tests\Unit;

use Closure;
use Illuminate\Http\Request;
use Mallto\Tool\Middleware\AuthenticateSignWithReferrer;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AuthenticateSignWithReferrerForceCheckTest extends TestCase
{
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
    }
}

final class RecordingAuthenticateSignWithReferrer extends AuthenticateSignWithReferrer
{
    public int $checkCalls = 0;

    protected function check(Request $request, Closure $next): Response
    {
        $this->checkCalls++;

        return new Response('signature-check', 418);
    }
}
