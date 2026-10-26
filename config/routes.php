<?php

declare(strict_types=1);

use Mpgw\Http\Controller\Admin\AlertsController;
use Mpgw\Http\Controller\Admin\ApiKeysController;
use Mpgw\Http\Controller\Admin\ApplicationsController;
use Mpgw\Http\Controller\Admin\AuthController;
use Mpgw\Http\Controller\Admin\OidcController;
use Mpgw\Http\Controller\Admin\DashboardController;
use Mpgw\Http\Controller\Admin\EmailsController;
use Mpgw\Http\Controller\Admin\HelpController;
use Mpgw\Http\Controller\Admin\LogsController;
use Mpgw\Http\Controller\Admin\MailboxesController;
use Mpgw\Http\Controller\Admin\RolesController;
use Mpgw\Http\Controller\Admin\TemplatesController;
use Mpgw\Http\Controller\Admin\UsersController;
use Mpgw\Http\Controller\Api\MetaController;
use Mpgw\Http\Controller\Api\RecordsController;
use Mpgw\Http\Middleware\AdminAuthMiddleware;
use Mpgw\Http\Middleware\ApiKeyAuthMiddleware;
use Mpgw\Http\Middleware\PermissionMiddleware;
use Mpgw\Http\Middleware\RateLimitMiddleware;
use Mpgw\Http\Middleware\ScopeMiddleware;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\AuthorizationService;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $container = $app->getContainer();

    // ------------------------------------------------------------------
    // Adminvy (server-renderad, sessionsbaserad inloggning)
    // ------------------------------------------------------------------
    $app->get('/', fn ($req, $res) => $res->withHeader('Location', '/admin')->withStatus(302));

    $app->get('/admin/login', [AuthController::class, 'showLogin']);
    $app->post('/admin/login', [AuthController::class, 'login']);
    $app->post('/admin/logout', [AuthController::class, 'logout']);

    $app->get('/auth/oidc/login', [OidcController::class, 'login']);
    $app->get('/auth/oidc/callback', [OidcController::class, 'callback']);

    $app->group('/admin', function (RouteCollectorProxy $group) use ($container): void {
        /** @var AuthorizationService $authz */
        $authz = $container->get(AuthorizationService::class);
        $perm = static fn (string $slug) => new PermissionMiddleware($authz, $slug);

        $group->get('', [DashboardController::class, 'index']);
        $group->get('/help', [HelpController::class, 'index']);

        $group->get('/applications', [ApplicationsController::class, 'index'])->add($perm('applications.view'));
        $group->get('/applications/new', [ApplicationsController::class, 'newForm'])->add($perm('applications.manage'));
        $group->post('/applications', [ApplicationsController::class, 'create'])->add($perm('applications.manage'));
        $group->get('/applications/{id}', [ApplicationsController::class, 'show'])->add($perm('applications.view'));
        $group->get('/applications/{id}/edit', [ApplicationsController::class, 'editForm'])->add($perm('applications.manage'));
        $group->post('/applications/{id}/edit', [ApplicationsController::class, 'update'])->add($perm('applications.manage'));
        $group->post('/applications/{id}/delete', [ApplicationsController::class, 'delete'])->add($perm('applications.manage'));
        $group->post('/applications/{id}/reactivate', [ApplicationsController::class, 'reactivate'])->add($perm('applications.manage'));
        $group->get('/applications/{id}/templates/new', [ApplicationsController::class, 'newTemplateForm'])->add($perm('applications.manage'));
        $group->post('/applications/{id}/templates', [ApplicationsController::class, 'createTemplate'])->add($perm('applications.manage'));
        $group->post('/applications/{id}/templates/{templateId}/delete', [ApplicationsController::class, 'deleteTemplate'])->add($perm('applications.manage'));
        $group->post('/applications/{id}/templates/{templateId}/reactivate', [ApplicationsController::class, 'reactivateTemplate'])->add($perm('applications.manage'));

        $group->get('/mailboxes/{id}/edit', [MailboxesController::class, 'editForm'])->add($perm('applications.manage'));
        $group->post('/mailboxes/{id}/edit', [MailboxesController::class, 'update'])->add($perm('applications.manage'));
        $group->post('/mailboxes/{id}/poll', [MailboxesController::class, 'poll'])->add($perm('applications.manage'));
        $group->post('/mailboxes/{id}/test', [MailboxesController::class, 'testConnection'])->add($perm('applications.manage'));
        $group->post('/mailboxes/{id}/delete', [MailboxesController::class, 'delete'])->add($perm('applications.manage'));

        $group->get('/templates/{id}', [TemplatesController::class, 'edit'])->add($perm('applications.view'));
        $group->post('/templates/{id}/edit-fields', [TemplatesController::class, 'updateFields'])->add($perm('applications.manage'));
        $group->post('/templates/{id}/rules', [TemplatesController::class, 'createRule'])->add($perm('applications.manage'));
        $group->put('/templates/{id}/rules/{ruleId}', [TemplatesController::class, 'updateRule'])->add($perm('applications.manage'));
        $group->delete('/templates/{id}/rules/{ruleId}', [TemplatesController::class, 'deleteRule'])->add($perm('applications.manage'));

        $group->post('/api/normalize-preview', [TemplatesController::class, 'normalizePreview'])->add($perm('applications.view'));
        $group->post('/api/suggest-pattern', [TemplatesController::class, 'suggestPattern'])->add($perm('applications.view'));
        $group->post('/api/test-pattern', [TemplatesController::class, 'testPattern'])->add($perm('applications.view'));

        $group->get('/emails', [EmailsController::class, 'index'])->add($perm('emails.view'));
        $group->get('/emails/{uuid}', [EmailsController::class, 'show'])->add($perm('emails.view'));
        $group->post('/emails/{uuid}/reparse', [EmailsController::class, 'reparse'])->add($perm('emails.manage'));
        $group->post('/emails/{uuid}/delete', [EmailsController::class, 'delete'])->add($perm('emails.manage'));

        $group->get('/alerts', [AlertsController::class, 'index'])->add($perm('alerts.view'));
        $group->post('/alerts/acknowledge', [AlertsController::class, 'acknowledgeMany'])->add($perm('alerts.manage'));
        $group->post('/alerts/{id}/acknowledge', [AlertsController::class, 'acknowledge'])->add($perm('alerts.manage'));
        $group->post('/alerts/{id}/resolve', [AlertsController::class, 'resolve'])->add($perm('alerts.manage'));

        $group->get('/api-keys', [ApiKeysController::class, 'index'])->add($perm('api_keys.view'));
        $group->get('/api-keys/new', [ApiKeysController::class, 'newForm'])->add($perm('api_keys.manage'));
        $group->post('/api-keys', [ApiKeysController::class, 'create'])->add($perm('api_keys.manage'));
        $group->get('/api-keys/{id}/edit', [ApiKeysController::class, 'editForm'])->add($perm('api_keys.manage'));
        $group->post('/api-keys/{id}/edit', [ApiKeysController::class, 'update'])->add($perm('api_keys.manage'));
        $group->post('/api-keys/{id}/delete', [ApiKeysController::class, 'delete'])->add($perm('api_keys.manage'));
        $group->post('/api-keys/{id}/reactivate', [ApiKeysController::class, 'reactivate'])->add($perm('api_keys.manage'));

        $group->get('/users', [UsersController::class, 'index'])->add($perm('users.view'));
        $group->get('/users/new', [UsersController::class, 'newForm'])->add($perm('users.manage'));
        $group->post('/users', [UsersController::class, 'create'])->add($perm('users.manage'));
        $group->get('/users/{id}/edit', [UsersController::class, 'editForm'])->add($perm('users.manage'));
        $group->post('/users/{id}/edit', [UsersController::class, 'update'])->add($perm('users.manage'));
        $group->post('/users/{id}/delete', [UsersController::class, 'delete'])->add($perm('users.manage'));
        $group->post('/users/{id}/reactivate', [UsersController::class, 'reactivate'])->add($perm('users.manage'));

        $group->get('/roles', [RolesController::class, 'index'])->add($perm('roles.view'));
        $group->get('/roles/new', [RolesController::class, 'newForm'])->add($perm('roles.manage'));
        $group->post('/roles', [RolesController::class, 'create'])->add($perm('roles.manage'));
        $group->get('/roles/{id}/edit', [RolesController::class, 'editForm'])->add($perm('roles.manage'));
        $group->post('/roles/{id}/edit', [RolesController::class, 'update'])->add($perm('roles.manage'));
        $group->post('/roles/{id}/delete', [RolesController::class, 'delete'])->add($perm('roles.manage'));

        $group->get('/logs', [LogsController::class, 'index'])->add($perm('logs.view'));
        $group->get('/logs/{id}', [LogsController::class, 'show'])->add($perm('logs.view'));
    })->add($container->get(AdminAuthMiddleware::class));

    // ------------------------------------------------------------------
    // Externt API (nyckelskyddat)
    // ------------------------------------------------------------------
    $app->get('/api/v1/health', [MetaController::class, 'health']);

    // ScopeMiddleware injicerar AuditLogger (den loggar bade avvisade
    // scope-kontroller och lyckade anrops slutresultat, se klassens
    // docblock for varfor det maste ske just har) - byggs via containern
    // istallet for new ScopeMiddleware(...) direkt.
    $scope = static fn (string $requiredScope) => new ScopeMiddleware($requiredScope, $container->get(AuditLogger::class));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($scope): void {
        $group->get('/records', [RecordsController::class, 'index'])
            ->add($scope('records:read'));
        $group->get('/records/{uuid}', [RecordsController::class, 'show'])
            ->add($scope('records:read'));
        $group->get('/records/{uuid}/raw', [RecordsController::class, 'raw'])
            ->add($scope('records:read')); // extra scope-koll (records:read:raw) sker i controllern

        $group->get('/applications', [MetaController::class, 'applications'])
            ->add($scope('templates:read'));
        $group->get('/templates', [MetaController::class, 'templates'])
            ->add($scope('templates:read'));
        $group->get('/alerts', [MetaController::class, 'alerts'])
            ->add($scope('alerts:read'));
    })
        ->add($container->get(RateLimitMiddleware::class))
        ->add($container->get(ApiKeyAuthMiddleware::class));
};
