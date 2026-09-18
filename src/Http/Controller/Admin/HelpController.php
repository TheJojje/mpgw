<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Statisk hjalp-/dokumentationssida (kom igang-guide + fullstandig
 * API-referens). Inget databasberoende - allt innehall skrivs direkt i
 * help/index.twig sa det alltid foljer koden, ingen separat fil att
 * glomma bort att uppdatera. Synlig for alla inloggade admins oavsett
 * roll (ingen PermissionMiddleware pa denna route), precis som
 * Oversikt-sidan.
 */
final class HelpController
{
    public function __construct(private readonly ViewRenderer $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($request, $response, 'help/index.twig');
    }
}
