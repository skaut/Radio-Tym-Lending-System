<?php

use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\QRCode;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/utils.php';

$projectRoot = dirname(__DIR__);
$databasePath = __DIR__.'/rtls.sqlite';
$logPath = $projectRoot.'/logs/rtls.log';
$templatesPath = $projectRoot.'/templates/';


// LOAD ENVS

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/../');
$dotenv->load();

function isAsyncRequest(Request $request): bool
{
    $requestedWith = $request->getHeaderLine('X-Requested-With');
    $accept = $request->getHeaderLine('Accept');

    return strcasecmp($requestedWith, 'XMLHttpRequest') === 0 || stripos($accept, 'application/json') !== false;
}

function jsonResponse(Response $response, array $payload, int $status = 200): Response
{
    $response = $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $response;
}

function getStatusDictionary(): array
{
    return [
        'lent' => 'Vypůjčeno',
        'charging' => 'Nabíjí se',
        'ready' => 'Ready',
    ];
}

function isChargingComplete(array $radio): bool
{
    if (($radio['status'] ?? '') !== 'charging' || empty($radio['last-action-time'])) {
        return false;
    }

    return strtotime($radio['last-action-time'].' +2 hours') <= strtotime(getNow());
}

function normalizeRadioState(PDO $db, Monolog\Logger $logger, array $radio): array
{
    if (isChargingComplete($radio)) {
        $query = $db->prepare('UPDATE `radios` SET `status` = "ready" WHERE `id` = ?');
        $query->execute([$radio['id']]);
        $logger->addInfo('Radio with ID '.$radio['radioId'].' is set as charged and ready.');
        $radio['status'] = 'ready';
    }

    return $radio;
}

function fetchRadioByColumn(PDO $db, Monolog\Logger $logger, string $column, $value): ?array
{
    $allowedColumns = ['id', 'radioId'];
    if (!in_array($column, $allowedColumns, true)) {
        throw new InvalidArgumentException('Unsupported lookup column.');
    }

    $query = $db->prepare(sprintf(
        'SELECT `id`,`radioId`,`name`,`status`,`last-action-time`,`channel`,`last-borrower` FROM `radios` WHERE `%s` = ?',
        $column
    ));
    $query->execute([$value]);
    $radio = $query->fetch();

    if (!$radio) {
        return null;
    }

    return normalizeRadioState($db, $logger, $radio);
}

function getRadioCounts(PDO $db): array
{
    return [
        'lent' => (int)$db->query('SELECT COUNT(`id`) as count FROM `radios` WHERE status = "lent"')->fetch()['count'],
        'notLent' => (int)$db->query('SELECT COUNT(`id`) as count FROM `radios` WHERE status = "ready" OR status = "charging"')->fetch()['count'],
    ];
}

function buildRadioPayload(array $radio): array
{
    $statusDictionary = getStatusDictionary();
    $timerSeconds = null;

    if ($radio['status'] === 'charging') {
        $timerSeconds = max(0, strtotime($radio['last-action-time'].' +2 hours') - strtotime(getNow()));
    }

    return [
        'id' => (int)$radio['id'],
        'radioId' => $radio['radioId'],
        'name' => $radio['name'],
        'status' => $radio['status'],
        'statusLabel' => $statusDictionary[$radio['status']] ?? $radio['status'],
        'lastActionTime' => $radio['last-action-time'],
        'lastActionTimeDisplay' => date_create($radio['last-action-time'])->format('H:i:s d/m/y'),
        'channel' => (string)($radio['channel'] ?? ''),
        'lastBorrower' => (string)($radio['last-borrower'] ?? ''),
        'timerSeconds' => $timerSeconds,
        'nextAction' => $radio['status'] === 'lent' ? 'return' : 'lend',
    ];
}


// CONFIGURATION

$config['displayErrorDetails'] = true;
$config['addContentLengthHeader'] = false;
$config['db']['sqliteDbName'] = $databasePath;
$config['logPath'] = $logPath;

$app = new \Slim\App(['settings' => $config]);
$container = $app->getContainer();


// DEPENDENCIES

$container['logger'] = function ($c) {
	$logger = new \Monolog\Logger('fileLogger');
	$file_handler = new \Monolog\Handler\StreamHandler($c['settings']['logPath']);
	$logger->pushHandler($file_handler);
	return $logger;
};

$container['db'] = function ($c) {
	$db = $c['settings']['db'];
	$pdo = new PDO('sqlite:'.$db['sqliteDbName']);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $columns = $pdo->query('PRAGMA table_info(`radios`)')->fetchAll();
    $columnNames = array_column($columns, 'name');

    if (!in_array('channel', $columnNames, true)) {
        $pdo->exec('ALTER TABLE `radios` ADD COLUMN `channel` TEXT');
    }
	
	return $pdo;
};

$container['view'] = new \Slim\Views\PhpRenderer($templatesPath);


// MIDDLEWARE
// AUTH

$app->add(new Tuupola\Middleware\HttpBasicAuthentication([
    'secure' => false,
    'users' => [
        $_ENV['AUTH_USER'] => $_ENV['AUTH_PASS'],
    ]
]));


// ROUTES

$app->get('/phpinfo', function (Request $request, Response $response) {
	return $response->getBody()->write(phpinfo());
});

$app->get('/management-radio', function (Request $request, Response $response) {
    $query = $this->db->query('SELECT `id`,`radioId`, `name` FROM `radios` ORDER BY `radioId` ASC, `name` ASC');
    $radios = $query->fetchAll();

	return $this->view->render($response, 'management-radio.phtml', [
        'router' => $this->router,
        'radios' => $radios,
    ]);
})->setName('management-radio');

$app->post('/add-new-radio', function (Request $request, Response $response) {
	$parsedBody = $request->getParsedBody();
	
	$query = $this->db->prepare('INSERT INTO `radios` (`radioId`, `name`, `status`, `last-action-time`, `last-borrower`) VALUES (?, ?, ?, ?, ?)');
	$query->execute([
			htmlspecialchars($parsedBody['radioId'], ENT_QUOTES),
			htmlspecialchars($parsedBody['name'], ENT_QUOTES),
			'ready',
            getNow(),
			NULL,
		]
	);
	$this->logger->addInfo('Added radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES));
	
	return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('add-new-radio');

$app->post('/import-radio', function (Request $request, Response $response) {
	$importRadio = $request->getParsedBody()['importRadio'];
    $explodedImportRadio = explode(PHP_EOL, $importRadio);

    foreach ($explodedImportRadio as $singleRadio) {
        $radioData = explode(';', $singleRadio);
        
        $query = $this->db->prepare('INSERT INTO `radios` (`radioId`, `name`, `status`, `last-action-time`, `last-borrower`) VALUES (?, ?, ?, ?, ?)');
        [$radioId, $name] = $radioData;
        if (!empty($radioId) && !empty($name)) {
            $query->execute([
                trim(htmlspecialchars($radioId, ENT_QUOTES)),
                trim(htmlspecialchars($name, ENT_QUOTES)),
                'ready',
                getNow(),
                NULL,
            ]);
        }
        $this->logger->addInfo('Added radio from import with ID '.htmlspecialchars($importRadio['radioId'], ENT_QUOTES));
    }

	return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('import-radio');

$app->post('/delete-radio', function (Request $request, Response $response) {
    $parsedBody = $request->getParsedBody();
    $query = $this->db->prepare('DELETE FROM `radios` WHERE `id` = ?');
    $query->execute([htmlspecialchars($parsedBody['id'], ENT_QUOTES)],
    );
    $this->logger->addInfo('Deleted radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES));

    return $response->withHeader('Location', $this->router->pathFor('management-radio'));
})->setName('delete-radio');

$app->post('/update-channel', function (Request $request, Response $response) {
	$parsedBody = $request->getParsedBody();
	$query = $this->db->prepare('UPDATE `radios` SET `channel` = ? WHERE `id` = ?');
	$query->execute([
            htmlspecialchars($parsedBody['channel'], ENT_QUOTES),
            htmlspecialchars($parsedBody['radioId'], ENT_QUOTES),
		]
	);
	$this->logger->addInfo('Changed channel for radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES));

    if (isAsyncRequest($request)) {
        $radio = fetchRadioByColumn($this->db, $this->logger, 'id', htmlspecialchars($parsedBody['radioId'], ENT_QUOTES));

        return jsonResponse($response, [
            'success' => true,
            'message' => 'Kanál uložen.',
            'radio' => buildRadioPayload($radio),
            'counts' => getRadioCounts($this->db),
        ]);
    }

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

    if (isAsyncRequest($request)) {
        $radio = fetchRadioByColumn($this->db, $this->logger, 'id', $id);
        $message = match ($argumentAction) {
            'lend' => 'Vypůjčení uloženo.',
            'return' => 'Vrácení uloženo.',
            'charged' => 'Rádio označeno jako ready.',
            default => 'Změna uložena.',
        };

        return jsonResponse($response, [
            'success' => true,
            'message' => $message,
            'radio' => buildRadioPayload($radio),
            'counts' => getRadioCounts($this->db),
        ]);
    }
	
	return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('radio-action');

$app->get('/log', function (Request $request, Response $response) {
	$logData = file_get_contents($this->settings['logPath']);
	
	return $this->view->render($response, 'log.phtml', ['router' => $this->router, 'log' => explode(PHP_EOL, $logData)]);
})->setName('log');

$app->post('/fast-return', function (Request $request, Response $response) {
    $query = $this->db->prepare('UPDATE `radios` SET `status` = "ready", `last-action-time` = ? WHERE `radioId` = ?');
    $query->execute([
        getNow(),
        $request->getParsedBody()['radioId'],
    ]);

    return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('fast-return');

$app->post('/fast-lent', function (Request $request, Response $response) {
    $parsedBody = $request->getParsedBody();
    $radioId = htmlspecialchars($parsedBody['radioId'], ENT_QUOTES);
    $borrower = htmlspecialchars($parsedBody['borrower'], ENT_QUOTES);

    $query = $this->db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ?, `last-borrower` = ? WHERE `radioId` = ?');
    $query->execute([
        'lent',
        getNow(),
        $borrower,
        $radioId,
    ]);
    $this->logger->addInfo('Radio with ID '.$radioId.' is lent to '.$borrower.'.');

    return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('fast-lent');

$app->get('/qr-generate', function (Request $request, Response $response) {
    $query = $this->db->query('SELECT * FROM `radios`');
    $radios = $query->fetchAll();
    $options = new QROptions([
        'eccLevel' => 0,
        'outputType' => QRCode::OUTPUT_MARKUP_SVG,
        'imageBase64' => true,
    ]);
    $baseUrl = sprintf('%s://%s', $request->getUri()->getScheme(), $request->getUri()->getAuthority());

    return $this->view->render($response, 'qr.phtml', [
        'router' => $this->router,
        'base_uri' => $baseUrl,
        'radios' => $radios,
        'qr_options' => $options,
    ]);
})->setName('qr-generate');

$app->get('/{radioId}', function (Request $request, Response $response, array $args) {
    $radioId = $args['radioId'];
    $query = $this->db->prepare('SELECT * FROM `radios` WHERE `radioId` = ?');
    $query->execute([$radioId]);

    return $this->view->render($response, 'fast.phtml', ['router' => $this->router, 'r' => $query->fetch()]);
})->setName('fast');

$app->get('/', function (Request $request, Response $response) {
	//get items from DB
	$query = $this->db->query('SELECT `id`,`radioId`, `name`, `status`, `last-action-time`, `channel`, `last-borrower` FROM `radios` ORDER BY `last-action-time` DESC');
	$radios = $query->fetchAll();
	$formTemplatesDirectory = 'radio-list-form-templates/';
	
	//get right link based by status
	foreach ($radios as &$r) {
        $r = normalizeRadioState($this->db, $this->logger, $r);
		switch ($r['status']) {
			case 'ready':
			case 'charging':
                $r['formTemplateLink'] = $formTemplatesDirectory.'lend.phtml';
				break;
			case 'lent':
				$r['formTemplateLink'] = $formTemplatesDirectory.'return.phtml';
				break;
		}
	}
    unset($r);

    $channels = range(1, 16);

    return $this->view->render($response, 'radio-list.phtml', [
        'router' => $this->router,
        'radios' => $radios,
        'channels' => $channels,
        'radioCounts' => getRadioCounts($this->db),
        'statusDictionary' => getStatusDictionary(),
    ]);
})->setName('radio-list');


// FIRE!

try {
    $app->run();
} catch (Throwable $e) {
    echo 'Pardon, radio ztratilo spojení...';
    die;
}
