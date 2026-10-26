<?php

declare(strict_types=1);

namespace Mpgw\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Sakerhetsheaders pa alla svar. CSP begransas medvetet till direktiv som inte
 * kraver att inline-skript/-stilar skrivs om (adminvyn anvander inline-skript);
 * form-action utelamnas eftersom det ocksa stoppar omdirigeringar efter POST
 * (t.ex. SSO-utloggning mot authentik).
 */
final class SecurityHeadersMiddleware implements Middleware
{
    public function __construct(private readonly bool $secureCookies)
    {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; object-src 'none'");

        if ($this->secureCookies) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=15552000');
        }

        return $response;
    }
}
