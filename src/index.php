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


// ROUTES

$app->get('/phpinfo', function (Request $request, Response $response) {
	return $response->getBody()->write(phpinfo());
});

$app->get('/new-radio', function (Request $request, Response $response) {
	$response = $this->view->render($response, 'new-radio.phtml', ['router' => $this->router]);
	
	return $response;
})->setName('new-radio');

$app->post('/add-new-radio', function (Request $request, Response $response) {
	$parsedBody = $request->getParsedBody();
	
	$query = $this->db->prepare('INSERT INTO `radios` (`radioId`, `name`, `status`, `last-returned-time`, `last-borrower`) VALUES (?, ?, ?, ?, ?)');
	$query->execute([
			htmlspecialchars($parsedBody['radioId'], ENT_QUOTES),
			htmlspecialchars($parsedBody['name'], ENT_QUOTES),
			'ready',
			'NOW()',
			NULL,
		]
	);
	
	return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->
setName('add-new-radio');

$app->get('/', function (Request $request, Response $response) {
	$this->logger->addInfo('Main page accessed.');
	
	//get items from DB
	$query = $this->db->query('SELECT `radioId`, `name`, `status`, `last-returned-time`, `last-borrower` FROM `radios`');
	$radios = $query->fetchAll();
	
	//render main page
	$response = $this->view->render($response, 'radio-list.phtml', ['router' => $this->router, 'radios' => $radios]);
	return $response;
})->setName('radio-list');


// FIRE!

$container['logger']->addInfo("RTLS starting.");
$app->run();