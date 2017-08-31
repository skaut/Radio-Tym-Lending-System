<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require '../vendor/autoload.php';


// CONFIGURATION

$config['displayErrorDetails'] = true;
$config['addContentLengthHeader'] = false;
$config['db']['sqliteDbName'] = 'rtls.sqlite';

$app = new \Slim\App(['settings' => $config]);
$container = $app->getContainer();


// DEPENDENCIES

$container['logger'] = function ($c) {
	$logger = new \Monolog\Logger('fileLogger');
	$file_handler = new \Monolog\Handler\StreamHandler("../logs/rtls.log");
	$logger->pushHandler($file_handler);
	return $logger;
};

$container['db'] = function ($c) {
	$db = $c['settings']['db'];
	$pdo = new PDO('sqlite:'.$db['sqliteDbName']);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
	
	return $pdo;
};

$container['view'] = new \Slim\Views\PhpRenderer("../templates/");


// MIDDLEWARE

$app->add(function (Request $request, Response $response, $next) {
	$response = $next($request, $response);
	
	return $response;
});


// ROUTES

$app->get('/phpinfo', function (Request $request, Response $response) {
	return $response->getBody()->write(phpinfo());
});

$app->get('/new-radio', function (Request $request, Response $response) {
	return $response = $this->view->render($response, 'new-radio.phtml', ['router' => $this->router]);
})->setName('new-radio');

$app->post('/add-new-radio', function (Request $request, Response $response) {
	$parsedBody = $request->getParsedBody();
	
	$query = $this->db->prepare('INSERT INTO `radios` (`radioId`, `name`, `status`, `last-returned-time`, `last-borrower`) VALUES (?, ?, ?, ?, ?)');
	$query->execute([
			htmlspecialchars($parsedBody['radioId'], ENT_QUOTES),
			htmlspecialchars($parsedBody['name'], ENT_QUOTES),
			'ready',
			date('Y-m-d H:i:s'),
			NULL,
		]
	);
	$this->logger->addInfo('Added radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES));
	
	return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('add-new-radio');

$app->post('/lend-radio', function (Request $request, Response $response) {
	$parsedBody = $request->getParsedBody();
	$id = htmlspecialchars($parsedBody['id'], ENT_QUOTES);
	$radioId = htmlspecialchars($parsedBody['radioId'], ENT_QUOTES);
	
	$query = $this->db->prepare('UPDATE `radios` SET `status` = ? WHERE `id` = ?');
	$query->execute(['lent', $id]);
	
	$this->logger->addInfo('Lent radio with ID '.$radioId);
	
	return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('lend-radio');

$app->post('/return-radio', function (Request $request, Response $response) {
	$parsedBody = $request->getParsedBody();
	$id = htmlspecialchars($parsedBody['id'], ENT_QUOTES);
	$radioId = htmlspecialchars($parsedBody['radioId'], ENT_QUOTES);
	
	$query = $this->db->prepare('UPDATE `radios` SET `status` = ? WHERE `id` = ?');
	$query->execute(['returned', $id]);
	
	$this->logger->addInfo('Returned radio with ID '.$radioId);
	
	return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('return-radio');

$app->get('/log', function (Request $request, Response $response) {
	$logData = file_get_contents('../logs/rtls.log');
	
	return $response = $this->view->render($response, 'log.phtml', ['router' => $this->router, 'log' => explode(PHP_EOL, $logData)]);
})->setName('log');

$app->get('/', function (Request $request, Response $response) {
	//get items from DB
	$query = $this->db->query('SELECT `id`,`radioId`, `name`, `status`, `last-returned-time`, `last-borrower` FROM `radios`');
	$radios = $query->fetchAll();
	//get right link based by status
	foreach ($radios as &$r) {
		switch ($r['status']) {
			case 'ready':
				//lend available
				$r['link'] = $this->router->pathFor('lend-radio');
				$r['linkLabel'] = 'Vypůjčit';
				break;
			case 'lent':
				//return available
				$r['link'] = $this->router->pathFor('return-radio');
				$r['linkLabel'] = 'Vrátit';
				break;
			case 'returned':
				//lend available (but with exceptions?)
				$r['link'] = $this->router->pathFor('lend-radio');
				$r['linkLabel'] = 'Vypůjčit (nenabito!)';
				break;
		}
	}
	
	return $response = $this->view->render($response, 'radio-list.phtml', ['router' => $this->router, 'radios' => $radios]);
})->setName('radio-list');


// FIRE!
$app->run();