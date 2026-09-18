<?php

declare(strict_types=1);

use Mpgw\Http\Middleware\AdminAuthMiddleware;
use Slim\Factory\AppFactory;

/** @var \DI\Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$config = $container->get('config');
$displayErrors = (bool) $config['app']['debug'];
$app->addErrorMiddleware($displayErrors, true, true);

session_name($config['session']['name']);
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $config['session']['secure'],
]);
session_start();

(require __DIR__ . '/../config/routes.php')($app);

$app->run();
