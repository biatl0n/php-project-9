<?php

require __DIR__ . '/../vendor/autoload.php';

use Hexlet\Code\Connection;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Carbon\Carbon;

session_start();

try {
    $pdo = Connection::get()->connect();
} catch (\PDOException $e) {
    echo $e->getMessage();
    exit;
}

//Проверяем существование таблицы urls и созадём её если отсутствует
$checkSQL = "select exists (select from pg_tables where schemaname = 'public' and tablename = 'urls');";
$checkResult = $pdo->query($checkSQL)->fetchColumn();
if (!$checkResult) {
    try {
        $pdo->query("DROP TABLE public.urls;");
        $pdo->query("DROP TABLE public.url_checks;");
    } catch (\PDOException $e) { }
    $lines = file('../database.sql');
    foreach ($lines as $line) {
        $sql = $line;
        $stmt = $pdo->query($sql);
    }
}
//_______________________________________________________

$container = new Container();
AppFactory::setContainer($container);

$container->set('view', function () {
    return Twig::create(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::create();
$app->add(TwigMiddleware::createFromContainer($app));
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$router = $app->getRouteCollector()->getRouteParser();

$errorMiddleware->setErrorHandler(\Slim\Exception\HttpNotFoundException::class, function () {
    $response = new \Slim\Psr7\Response();
    return $this->get('view')->render($response, '404.phtml')->withStatus(404);
});

$app->get('/', function ($request, $response) {
    return $this->get('view')->render($response, 'index.phtml');
})->setName('home');

$app->get('/urls', function ($request, $response, array $args) use ($router, $pdo) {
    $messages = $this->get('flash')->getMessages();
    $allSites = $pdo->query("select urls.id, urls.name, max(url_checks.created_at) as max_created_at
	                            from urls 
	                            left join url_checks on urls.id=url_checks.url_id
	                            group by urls.id ORDER BY id DESC;"
    )->fetchAll();
    $params = [
        'allSites' => $allSites,
        'messages' => $messages
    ];
    return $this->get('view')->render($response, 'showAll.phtml', $params);
})->setName('sites');

$app->get('/urls/{id}', function ($request, $response, array $args) use ($router, $pdo) {
    $messages = $this->get('flash')->getMessages();
    $id = (int)$args['id'];
    $stmtSiteInfo = $pdo->prepare("SELECT * FROM urls WHERE id= ?");
    $stmtSiteInfo->execute([$id]);
    if ($stmtSiteInfo->rowCount() < 1) {
        throw new \Slim\Exception\HttpNotFoundException($request);
    }
    $siteInfo = $stmtSiteInfo->fetch();
 
    $stmtSiteChecks = $pdo->prepare("SELECT * FROM url_checks WHERE url_id= ? ORDER BY id DESC");
    $stmtSiteChecks->execute([$id]);
    $siteChecks = $stmtSiteChecks->fetchAll();
    
    $params = [
        'id' => $siteInfo['id'],
        'name' => $siteInfo['name'],
        'created_at' => $siteInfo['created_at'],
        'messages' => $messages,
        'siteChecks' => $siteChecks
    ];
    return $this->get('view')->render($response, 'show.phtml', $params);
})->setName('site');

$app->post('/urls', function ($request, $response) use ($router, $pdo) {
    $name =  $request->getParsedBodyParam('url');
    $errorText = [];
    $v = new Valitron\Validator(['website' => $name['name']]);
    $v->rules(['url' => ['website']]);
    if ($name['name'] === '') {
        $errorText[] = 'URL не должен быть пустым';
    } elseif (!$v->validate()) {
        $errorText[] = 'Некорректный URL';
    }

    if (count($errorText) > 0) {
        $params = [
            'name' => $name['name'],
            'error' => implode(", ", $errorText)
        ];
        return $this->get('view')->render($response, 'index.phtml', $params)->withStatus(422);
    }

    $parsedUrl = parse_url($name['name']);
    $siteName = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";
    $stmtCountID = $pdo->prepare("SELECT COUNT(id) from urls where name = ?");
    $stmtCountID->execute([$siteName]);
    $isExists = $stmtCountID->fetchColumn() > 0 ? true : false;
    if (!$isExists) {
        $stmtSite = $pdo->prepare("INSERT INTO urls(name, created_at) VALUES (?, ?)");
        $stmtSite->execute([$siteName, Carbon::now()]);
        $last = $pdo->lastInsertId();
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    } else {
        $stmtId = $pdo->prepare("SELECT id FROM urls WHERE name = ?");
        $stmtId->execute([$siteName]);
        $last = $stmtId->fetchColumn();
        $this->get('flash')->addMessage('success', 'Страница уже существует ');
    }
    $url = $router->urlFor('site', ['id' => $last]);
    return $response->withRedirect($url, 302);
});

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router, $pdo) {
    $stmtCheck = $pdo->prepare("INSERT INTO url_checks (url_id, created_at) VALUES (?, ?)");
    $stmtCheck->execute([$args['id'], Carbon::now()]);
    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    $url = $router->urlFor('site', ['id' => $args['id']]);
    return $response->withRedirect($url, 302);
})->setName('checks');

$app->run();
