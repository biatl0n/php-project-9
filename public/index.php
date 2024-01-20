<?php

require __DIR__ . '/../vendor/autoload.php';

use Hexlet\Code\Connection;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Carbon\Carbon;
use GuzzleHttp\Client;
use DiDom\Document;
use Illuminate\Support\Optional;

session_start();

try {
    $pdo = Connection::get()->connect();
} catch (\PDOException $e) {
    echo $e->getMessage();
    exit;
}

//Проверяем существование таблицы urls и созадём её если отсутствует
$checkSQLurls = "select exists (select from pg_tables where schemaname = 'public' and tablename = 'urls');";
$checkSQLurl_checks = "select exists (select from pg_tables where schemaname = 'public' and tablename = 'url_checks');";
$checkResultUrls = $pdo->query($checkSQLurls)->fetchColumn();
$checkResultUrl_checks = $pdo->query($checkSQLurl_checks)->fetchColumn();
if (!$checkResultUrls || !$checkResultUrl_checks) {
    try {
        $pdo->query("DROP TABLE public.urls;");
        $pdo->query("DROP TABLE public.url_checks;");
    } catch (\PDOException $e) {
        $message = $e->getMessage();
    }

    $file = '../database.sql';
    try {
        if (!file_exists($file)) {
            throw new Exception("DB file is missing: $file");
        } else {
            $lines = file($file);
            if (is_array($lines) && count($lines) > 0) {
                foreach ($lines as $line) {
                    $sql = $line;
                    $stmt = $pdo->query($sql);
                }
            }
        }
    } catch (Exception $e) {
        echo $e->getMessage();
        exit;
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

$app->get('/urls', function ($request, $response, array $args) use ($pdo) {
    $messages = $this->get('flash')->getMessages();
    $allSites = $pdo->query("select urls.id, name, url_checks.status_code, url_checks.created_at as max_created_at
                                from urls
                                left join url_checks on url_checks.url_id = urls.id 
                                where url_checks.created_at = (
                                    select max (created_at)
                                        from url_checks
                                        where url_checks.url_id = urls.id
                                ) or url_checks.created_at is null
                                order by urls.id desc;")->fetchAll();
    $params = [
        'allSites' => $allSites,
        'messages' => $messages
    ];
    return $this->get('view')->render($response, 'showAll.phtml', $params);
})->setName('sites');

$app->get('/urls/{id}', function ($request, $response, array $args) use ($pdo) {
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
    $name = $request->getParsedBodyParam('url');
    $name = htmlspecialchars($name['name']);
    $errorText = [];
    $v = new Valitron\Validator(['website' => $name]);
    $v->rules(['url' => ['website']]);
    if ($name === '') {
        $errorText[] = 'URL не должен быть пустым';
    } elseif (!$v->validate()) {
        $errorText[] = 'Некорректный URL';
    }

    if (count($errorText) > 0) {
        $params = [
            'name' => $name,
            'error' => implode(", ", $errorText)
        ];
        return $this->get('view')->render($response, 'index.phtml', $params)->withStatus(422);
    }

    $parsedUrl = parse_url($name);
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
    $stmtSiteInfo = $pdo->prepare("SELECT * FROM urls WHERE id= ?");
    $stmtSiteInfo->execute([$args['id']]);
    $siteInfo = $stmtSiteInfo->fetch();
    $opts = ['connect_timeout' => 3];
    $client = new Client();
    try {
        $resp = $client->get($siteInfo['name'], $opts);
        $statusCode = $resp->getStatusCode();
    } catch (GuzzleHttp\Exception\ConnectException $e) {
        $this->get('flash')->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
        $url = $router->urlFor('site', ['id' => $args['id']]);
        return $response->withRedirect($url, 302);
    }

    $document = $document = new Document($siteInfo['name'], true);
    $stmtHead = Optional($document->first('head'));
    $stmtBody = Optional($document)->first('body');
    $stmtTitle = optional($stmtHead)->first('title');
    $title = strip_tags(optional($stmtTitle)->__toString());
    $stmtH1 = optional($stmtBody)->first('h1');
    $h1 = strip_tags(optional($stmtH1)->__toString());
    $stmtDescription = optional($stmtHead)->find('meta[name=description]');
    if (count($stmtDescription) > 0) {
        $description = optional($stmtDescription[0])->getAttribute('content');
    } else {
        $description = "";
    }

    $stmtCheck = $pdo->prepare("INSERT INTO url_checks (url_id, created_at, status_code, title, h1, description)
                                    VALUES (?, ?, ?, ?, ?, ?)");
    $stmtCheck->execute([$args['id'], Carbon::now(), $statusCode, $title, $h1, $description]);
    $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    $url = $router->urlFor('site', ['id' => $args['id']]);
    return $response->withRedirect($url, 302);
})->setName('checks');

$app->run();
