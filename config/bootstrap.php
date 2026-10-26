<?php

declare(strict_types=1);

use DI\Container;
use Mpgw\Http\Controller\Admin\AlertsController;
use Mpgw\Http\Controller\Admin\ApiKeysController;
use Mpgw\Http\Controller\Admin\AuthController;
use Mpgw\Http\Controller\Admin\OidcController;
use Mpgw\Service\OidcRoleMapper;
use Mpgw\Http\Controller\Admin\DashboardController;
use Mpgw\Http\Controller\Admin\EmailsController;
use Mpgw\Http\Controller\Admin\HelpController;
use Mpgw\Http\Controller\Admin\LogsController;
use Mpgw\Http\Controller\Admin\MailboxesController;
use Mpgw\Http\Controller\Admin\RolesController;
use Mpgw\Http\Controller\Admin\ApplicationsController;
use Mpgw\Http\Controller\Admin\TemplatesController;
use Mpgw\Http\Controller\Admin\UsersController;
use Mpgw\Http\Controller\Api\MetaController;
use Mpgw\Http\Controller\Api\RecordsController;
use Mpgw\Http\Middleware\AdminAuthMiddleware;
use Mpgw\Http\Middleware\ApiKeyAuthMiddleware;
use Mpgw\Http\Middleware\RateLimitMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\PdoConnectionFactory;
use Mpgw\Infrastructure\Db\Repository\AlertRepository;
use Mpgw\Infrastructure\Db\Repository\ApiKeyRepository;
use Mpgw\Infrastructure\Db\Repository\ExtractedRecordRepository;
use Mpgw\Infrastructure\Db\Repository\ExtractionRuleRepository;
use Mpgw\Infrastructure\Db\Repository\IngestLogRepository;
use Mpgw\Infrastructure\Db\Repository\LogEventRepository;
use Mpgw\Infrastructure\Db\Repository\MailboxRepository;
use Mpgw\Infrastructure\Db\Repository\PermissionRepository;
use Mpgw\Infrastructure\Db\Repository\DeletedEmailRepository;
use Mpgw\Infrastructure\Db\Repository\RawEmailRepository;
use Mpgw\Infrastructure\Db\Repository\RoleRepository;
use Mpgw\Infrastructure\Db\Repository\ApplicationRepository;
use Mpgw\Infrastructure\Db\Repository\TemplateRepository;
use Mpgw\Infrastructure\Db\Repository\UserRepository;
use Mpgw\Infrastructure\Imap\WebklexImapClient;
use Mpgw\Service\AuditDiffer;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\AuthorizationService;
use Mpgw\Service\CredentialCipher;
use Mpgw\Service\HtmlToTextNormalizer;
use Mpgw\Service\LoginThrottle;
use Mpgw\Service\MailboxPoller;
use Mpgw\Service\RegexRuleTester;
use Mpgw\Service\RegexSuggester;
use Mpgw\Service\RetentionService;
use Mpgw\Service\RuleEngine;
use Mpgw\Service\TemplateMatcher;
use Mpgw\Service\ThreadService;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';

$container = new Container();

$container->set('config', $config);

$container->set(PDO::class, fn () => PdoConnectionFactory::create($config['db']));

$container->set(CredentialCipher::class, fn () => new CredentialCipher($config['app']['secret_key']));

$container->set(AlertRepository::class, fn (Container $c) => new AlertRepository($c->get(PDO::class)));
$container->set(ApiKeyRepository::class, fn (Container $c) => new ApiKeyRepository($c->get(PDO::class)));
$container->set(ApplicationRepository::class, fn (Container $c) => new ApplicationRepository($c->get(PDO::class)));
$container->set(MailboxRepository::class, fn (Container $c) => new MailboxRepository($c->get(PDO::class)));
$container->set(RawEmailRepository::class, fn (Container $c) => new RawEmailRepository($c->get(PDO::class), $c->get(CredentialCipher::class)));
$container->set(DeletedEmailRepository::class, fn (Container $c) => new DeletedEmailRepository($c->get(PDO::class)));
$container->set(TemplateRepository::class, fn (Container $c) => new TemplateRepository($c->get(PDO::class)));
$container->set(ExtractionRuleRepository::class, fn (Container $c) => new ExtractionRuleRepository($c->get(PDO::class)));
$container->set(ExtractedRecordRepository::class, fn (Container $c) => new ExtractedRecordRepository($c->get(PDO::class)));
$container->set(IngestLogRepository::class, fn (Container $c) => new IngestLogRepository($c->get(PDO::class)));

$container->set(UserRepository::class, fn (Container $c) => new UserRepository($c->get(PDO::class)));
$container->set(RoleRepository::class, fn (Container $c) => new RoleRepository($c->get(PDO::class)));
$container->set(PermissionRepository::class, fn (Container $c) => new PermissionRepository($c->get(PDO::class)));
$container->set(LogEventRepository::class, fn (Container $c) => new LogEventRepository($c->get(PDO::class)));

$container->set(AuthorizationService::class, fn (Container $c) => new AuthorizationService(
    $c->get(RoleRepository::class),
    $c->get(UserRepository::class),
));
$container->set(AuditLogger::class, fn (Container $c) => new AuditLogger($c->get(LogEventRepository::class)));
$container->set(AuditDiffer::class, fn (Container $c) => new AuditDiffer($c->get(LogEventRepository::class)));
$container->set(LoginThrottle::class, fn (Container $c) => new LoginThrottle($c->get(PDO::class)));

$container->set(WebklexImapClient::class, fn () => new WebklexImapClient());
$container->set(HtmlToTextNormalizer::class, fn () => new HtmlToTextNormalizer());
$container->set(TemplateMatcher::class, fn () => new TemplateMatcher());
$container->set(RegexRuleTester::class, fn () => new RegexRuleTester());
$container->set(RegexSuggester::class, fn () => new RegexSuggester());
$container->set(RuleEngine::class, fn (Container $c) => new RuleEngine($c->get(RegexRuleTester::class)));

$container->set(RetentionService::class, fn (Container $c) => new RetentionService(
    $c->get(PDO::class),
    $c->get(ApplicationRepository::class),
    $c->get(AuditLogger::class),
));

$container->set(ThreadService::class, fn (Container $c) => new ThreadService(
    $c->get(PDO::class),
    $c->get(AlertRepository::class),
));

$container->set(MailboxPoller::class, fn (Container $c) => new MailboxPoller(
    $c->get(MailboxRepository::class),
    $c->get(RawEmailRepository::class),
    $c->get(TemplateRepository::class),
    $c->get(ExtractionRuleRepository::class),
    $c->get(ExtractedRecordRepository::class),
    $c->get(IngestLogRepository::class),
    $c->get(WebklexImapClient::class),
    $c->get(CredentialCipher::class),
    $c->get(HtmlToTextNormalizer::class),
    $c->get(TemplateMatcher::class),
    $c->get(RuleEngine::class),
    $c->get(AuditLogger::class),
    $c->get(DeletedEmailRepository::class),
    $c->get(AlertRepository::class),
    $c->get(ThreadService::class),
    $c->get(ApplicationRepository::class),
));

// --------------------------------------------------------------------
// Web (admin-UI + API): view-renderer, middleware, controllers
// --------------------------------------------------------------------
$container->set(ViewRenderer::class, function (Container $c) use ($config) {
    $view = new ViewRenderer(
        dirname(__DIR__) . '/templates/admin',
        $config['app']['debug'],
        $c->get(PDO::class),
        $c->get(AuthorizationService::class),
    );
    $view->addGlobal('oidc_enabled', $config['oidc']['enabled']);

    return $view;
});

$container->set(AdminAuthMiddleware::class, fn (Container $c) => new AdminAuthMiddleware($c->get(UserRepository::class), $c->get(AuthorizationService::class)));
$container->set(ApiKeyAuthMiddleware::class, fn (Container $c) => new ApiKeyAuthMiddleware($c->get(PDO::class), $c->get(AuditLogger::class)));
$container->set(RateLimitMiddleware::class, fn (Container $c) => new RateLimitMiddleware(
    $c->get(PDO::class),
    $config['api']['default_rate_limit_per_minute'],
    $c->get(AuditLogger::class),
));

$container->set(AuthController::class, fn (Container $c) => new AuthController(
    $c->get(ViewRenderer::class),
    $c->get(UserRepository::class),
    $c->get(AuthorizationService::class),
    $c->get(AuditLogger::class),
    $c->get(LoginThrottle::class),
    $config['oidc'],
));
$container->set(OidcRoleMapper::class, fn () => new OidcRoleMapper(
    $config['oidc']['access_group'],
    $config['oidc']['admin_group'],
    $config['oidc']['default_role'],
    $config['oidc']['admin_role'],
));
$container->set(OidcController::class, fn (Container $c) => new OidcController(
    $config['oidc'],
    $c->get(ViewRenderer::class),
    $c->get(UserRepository::class),
    $c->get(RoleRepository::class),
    $c->get(AuthorizationService::class),
    $c->get(AuditLogger::class),
    $c->get(OidcRoleMapper::class),
));
$container->set(DashboardController::class, fn (Container $c) => new DashboardController($c->get(ViewRenderer::class), $c->get(PDO::class)));
$container->set(HelpController::class, fn (Container $c) => new HelpController($c->get(ViewRenderer::class)));
$container->set(ApplicationsController::class, fn (Container $c) => new ApplicationsController(
    $c->get(ViewRenderer::class),
    $c->get(PDO::class),
    $c->get(ApplicationRepository::class),
    $c->get(MailboxRepository::class),
    $c->get(TemplateRepository::class),
    $c->get(CredentialCipher::class),
    $c->get(AuditLogger::class),
    $c->get(ThreadService::class),
));
$container->set(TemplatesController::class, fn (Container $c) => new TemplatesController(
    $c->get(ViewRenderer::class),
    $c->get(PDO::class),
    $c->get(TemplateRepository::class),
    $c->get(ApplicationRepository::class),
    $c->get(ExtractionRuleRepository::class),
    $c->get(RawEmailRepository::class),
    $c->get(RegexSuggester::class),
    $c->get(RegexRuleTester::class),
    $c->get(HtmlToTextNormalizer::class),
    $c->get(RuleEngine::class),
    $c->get(AuditLogger::class),
));
$container->set(MailboxesController::class, fn (Container $c) => new MailboxesController(
    $c->get(ViewRenderer::class),
    $c->get(MailboxRepository::class),
    $c->get(ApplicationRepository::class),
    $c->get(CredentialCipher::class),
    $c->get(WebklexImapClient::class),
    $c->get(IngestLogRepository::class),
    $c->get(AuditLogger::class),
    $c->get(MailboxPoller::class),
));
$container->set(EmailsController::class, fn (Container $c) => new EmailsController(
    $c->get(ViewRenderer::class),
    $c->get(PDO::class),
    $c->get(ExtractionRuleRepository::class),
    $c->get(RuleEngine::class),
    $c->get(ApplicationRepository::class),
    $c->get(RawEmailRepository::class),
    $c->get(DeletedEmailRepository::class),
    $c->get(AuditLogger::class),
    $c->get(TemplateRepository::class),
    $c->get(TemplateMatcher::class),
    $c->get(HtmlToTextNormalizer::class),
    $c->get(ExtractedRecordRepository::class),
    $c->get(IngestLogRepository::class),
    $c->get(ThreadService::class),
));
$container->set(AlertsController::class, fn (Container $c) => new AlertsController(
    $c->get(ViewRenderer::class),
    $c->get(PDO::class),
    $c->get(AlertRepository::class),
    $c->get(AuditLogger::class),
));
$container->set(ApiKeysController::class, fn (Container $c) => new ApiKeysController(
    $c->get(ViewRenderer::class),
    $c->get(PDO::class),
    $c->get(ApplicationRepository::class),
    $c->get(ApiKeyRepository::class),
    $c->get(AuditLogger::class),
));

$container->set(UsersController::class, fn (Container $c) => new UsersController(
    $c->get(ViewRenderer::class),
    $c->get(PDO::class),
    $c->get(UserRepository::class),
    $c->get(RoleRepository::class),
    $c->get(AuditLogger::class),
    $c->get(AuthorizationService::class),
));
$container->set(RolesController::class, fn (Container $c) => new RolesController(
    $c->get(ViewRenderer::class),
    $c->get(RoleRepository::class),
    $c->get(PermissionRepository::class),
    $c->get(UserRepository::class),
    $c->get(AuditLogger::class),
    $c->get(AuthorizationService::class),
));
$container->set(LogsController::class, fn (Container $c) => new LogsController(
    $c->get(ViewRenderer::class),
    $c->get(LogEventRepository::class),
    $c->get(AuditDiffer::class),
    $c->get(UserRepository::class),
));

$container->set(RecordsController::class, fn (Container $c) => new RecordsController($c->get(PDO::class), $c->get(RawEmailRepository::class)));
$container->set(MetaController::class, fn (Container $c) => new MetaController($c->get(PDO::class)));

return $container;
