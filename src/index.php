<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/utils.php';

// LOAD ENVS

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/../');
$dotenv->load();


// CONFIGURATION

$config['displayErrorDetails'] = true;
$config['addContentLengthHeader'] = false;
$config['db']['sqliteDbName'] = 'rtls.sqlite';

$app = new \Slim\App(['settings' => $config]);
$container = $app->getContainer();


// DEPENDENCIES

$container['logger'] = function ($c) {
	$logger = new \Monolog\Logger('fileLogger');
	$file_handler = new \Monolog\Handler\StreamHandler('../logs/rtls.log');
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

$container['view'] = new \Slim\Views\PhpRenderer('../templates/');

// MIDDLEWARE
// AUTH
$app->add(new Tuupola\Middleware\HttpBasicAuthentication([
    'users' => [
        $_ENV['AUTH_USER'] => $_ENV['AUTH_PASS'],
    ]
]));

// ROUTES

$app->get('/phpinfo', function (Request $request, Response $response) {
	return $response->getBody()->write(phpinfo());
});

$app->get('/new-radio', function (Request $request, Response $response) {
	return $response = $this->view->render($response, 'new-radio.phtml', ['router' => $this->router]);
})->setName('new-radio');

$app->post('/add-new-radio', function (Request $request, Response $response) {
	$parsedBody = $request->getParsedBody();
	
	$query = $this->db->prepare('INSERT INTO `radios` (`radioId`, `name`, `status`, `last-action-time`, `last-borrower`) VALUES (?, ?, ?, ?, ?)');
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

$app->post('/update-channel', function (Request $request, Response $response) {
	$parsedBody = $request->getParsedBody();
	$query = $this->db->prepare('UPDATE `radios` SET `channel` = ? WHERE `id` = ?');
	$query->execute([
            htmlspecialchars($parsedBody['channel'], ENT_QUOTES),
            htmlspecialchars($parsedBody['radioId'], ENT_QUOTES),
		]
	);
	$this->logger->addInfo('Changed channel for radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES));

	return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('update-channel');

$app->post('/radio-action/{action}', function (Request $request, Response $response, $args) {
	$argumentAction = htmlspecialchars($args['action'], ENT_QUOTES);
	$parsedBody = $request->getParsedBody();
	$id = htmlspecialchars($parsedBody['id'], ENT_QUOTES);
	$radioId = htmlspecialchars($parsedBody['radioId'], ENT_QUOTES);

    switch ($argumentAction) {
		case 'lend':
			$borrower = htmlspecialchars($parsedBody['borrower'], ENT_QUOTES);
			$lastBorrower = htmlspecialchars($parsedBody['last-borrower'], ENT_QUOTES);
            if (empty($borrower) && !empty($lastBorrower)) {
                $borrower = $lastBorrower;
            }
			$query = $this->db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ?, `last-borrower` = ? WHERE `id` = ?');
			$query->execute(['lent', getNow(), $borrower, $id]);
			$this->logger->addInfo('Radio with ID '.$radioId.' is lent to '.$borrower.'.');
			break;
		case 'return':
			$query = $this->db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ? WHERE `id` = ?');
			$query->execute(['charging', getNow(), $id]);
			$this->logger->addInfo('Radio with ID '.$radioId.' is returned.');
			break;
		case 'charged':
			$query = $this->db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ? WHERE `id` = ?');
			$query->execute(['ready', getNow(), $id]);
			$this->logger->addInfo('Radio with ID '.$radioId.' is set as fully charged.');
			break;
		default:
			throw new Exception('Unknown radio-action argument');
	}
	
	return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('radio-action');

$app->get('/log', function (Request $request, Response $response) {
	$logData = file_get_contents('../logs/rtls.log');
	
	return $this->view->render($response, 'log.phtml', ['router' => $this->router, 'log' => explode(PHP_EOL, $logData)]);
})->setName('log');

$app->get('/', function (Request $request, Response $response) {
	//get items from DB
	$query = $this->db->query('SELECT `id`,`radioId`, `name`, `status`, `last-action-time`, `channel`, `last-borrower` FROM `radios` ORDER BY `status`="ready" DESC, status="charging" DESC, status="lent" DESC, `last-action-time` ASC');
	$radios = $query->fetchAll();
	$formTemplatesDirectory = 'radio-list-form-templates/';
	
	//get right link based by status
	foreach ($radios as &$r) {
		switch ($r['status']) {
			case 'ready':
				//lend available
				$r['formTemplateLink'] = $formTemplatesDirectory.'lend.phtml';
				break;
			case 'lent':
				//return available
				$r['formTemplateLink'] = $formTemplatesDirectory.'return.phtml';
				break;
			case 'charging':
				//lend available (but with alert) - or change status to ready
                $query = $this->db->prepare('UPDATE `radios` SET `status` = "ready" WHERE `id` = ?');
                $query->execute([$r['id']]);
                $this->logger->addInfo('Radio with ID '.$r['radioId'].' is set as charged and ready.');
                
                $r['formTemplateLink'] = $formTemplatesDirectory.'lend.phtml';
				break;
		}
	}
    unset($r);

    $channels = range(1, 16);
	
	$statusDictionary = [
		'lent' => 'Vypůjčeno',
		'charging' => 'Nabíjí se',
		'ready' => 'Ready',
	];

    return $this->view->render($response, 'radio-list.phtml', [
        'router' => $this->router,
        'radios' => $radios,
        'channels' => $channels,
        'radioCounts' => [
            'lent' => $this->db->query('SELECT COUNT(`id`) as count FROM `radios` WHERE status = "lent"')->fetch()['count'],
            'notLent' => $this->db->query('SELECT COUNT(`id`) as count  FROM `radios` WHERE status = "ready" OR status = "charging"')->fetch()['count'],
        ],
        'statusDictionary' => $statusDictionary,
    ]);
})->setName('radio-list');


// FIRE!
try {
    $app->run();
} catch (Throwable $e) {
    echo 'Pardon, radio ztratilo spojení...';
    die;
}
