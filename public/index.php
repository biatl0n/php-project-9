<?php

require __DIR__ . '/../vendor/autoload.php';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

$container = new Container();
AppFactory::setContainer($container);

$container->set('view', function () {
    return Twig::create(__DIR__ . '/../templates');
});

$app = AppFactory::create();
$app->add(TwigMiddleware::createFromContainer($app));
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$header = __DIR__ . "/../templates/header.phtml";

$app->get('/', function ($request, $response) {
    return $this->get('view')->render($response, 'index.phtml');
})->setName('index');

$app->run();
