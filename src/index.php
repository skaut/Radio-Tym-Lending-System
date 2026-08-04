<?php
// Slim 3 and Monolog 1 emit a lot of deprecation notices on PHP 8.4. If those reach
// the output, PHP can no longer send HTTP headers - which silently breaks every
// redirect (lend, return, QR scan) and the Basic Auth challenge. So we keep them out
// of the output and only write them to a log.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__.'/../logs/php-error.log');
// Safety net in case the hosting turns display_errors back on: while an output
// buffer is open, headers can still be sent.
ob_start();

// FIX PRO LEBEDAHOSTING: Zabrání Slim frameworku, aby svévolně odřezával složku /src z URL adres
$_SERVER['SCRIPT_NAME'] = '/index.php';

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

// INSTALLATION CHECKS
// Without these, both cases below end as a blank 500 with no explanation - and they
// are by far the two most common mistakes after an FTP upload. Say what to fix instead.

function installationError(string $title, string $detail): never
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>RTLS - instalace není dokončená</h1>';
    echo '<h2>'.htmlspecialchars($title, ENT_QUOTES).'</h2>';
    echo '<p>'.$detail.'</p>';
    exit;
}

if (!is_readable($projectRoot.'/.env')) {
    installationError(
        'Chybí soubor .env',
        'Zkopíruj na serveru <code>.env.example</code> na <code>.env</code> (do stejné složky jako <code>index.php</code>) a vyplň <code>AUTH_USER</code> a <code>AUTH_PASS</code>.'
    );
}

// The database file is not in git (it holds real borrower names), so on a fresh
// install it simply is not there yet. That is fine - it gets created from
// src/schema.sql on the first connection, as long as the directory is writable.
if (!file_exists($databasePath)) {
    if (!is_writable(dirname($databasePath))) {
        installationError(
            'Databázi nejde vytvořit',
            'Soubor <code>src/rtls.sqlite</code> zatím neexistuje a složka <code>src/</code> není zapisovatelná. '
            .'Nastav přes FTP složce <code>src/</code> práva <code>775</code> - databáze se pak vytvoří sama.'
        );
    }
} elseif (!is_writable($databasePath) || !is_writable(dirname($databasePath))) {
    installationError(
        'Databáze není zapisovatelná',
        'Nastav přes FTP práva <code>664</code> souboru <code>src/rtls.sqlite</code> a práva <code>775</code> složce <code>src/</code>. '
        .'Zapisovatelná musí být i složka, protože SQLite si vedle databáze vytváří dočasné soubory.'
    );
}

if (!is_writable($logPath) || !is_writable(dirname($logPath))) {
    installationError(
        'Složka logs/ není zapisovatelná',
        'Nastav přes FTP práva <code>664</code> souboru <code>logs/rtls.log</code> a práva <code>775</code> složce <code>logs/</code>.'
    );
}


// LOAD ENVS

$dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
$dotenv->load();

// Refuse to start without credentials rather than booting an unprotected admin.
// Everything behind Basic Auth - the radio list, management, /log, /dbadmin - would
// otherwise be wide open, and nothing on the page would say so.
if (($_ENV['AUTH_USER'] ?? '') === '' || ($_ENV['AUTH_PASS'] ?? '') === '') {
    installationError(
        'Chybí přihlašovací údaje',
        'V souboru <code>.env</code> musí být vyplněné <code>AUTH_USER</code> i <code>AUTH_PASS</code>. '
        .'Bez nich by byla celá administrace přístupná komukoliv, takže se aplikace radši nespustí.'
    );
}

function hasValidBasicAuthCredentials(): bool
{
    $credentials = readBasicAuthCredentials();

    return basicAuthCredentialsMatch(
        $_ENV['AUTH_USER'] ?? '',
        $_ENV['AUTH_PASS'] ?? '',
        $credentials['user'],
        $credentials['pass']
    );
}

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
        $logger->addInfo('Radio with ID '.$radio['radioId'].' is set as charged and ready.', ['action' => 'auto-ready', 'radioId' => $radio['radioId']]);
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

// Show error details (including stack traces) only when DEBUG=true in .env - otherwise
// anonymous visitors of the public /public/{radioId} page would see them too.
$config['displayErrorDetails'] = filter_var($_ENV['DEBUG'] ?? 'false', FILTER_VALIDATE_BOOL);
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

    // Fresh install: PDO just created an empty file, so lay down the schema.
    $columns = $pdo->query('PRAGMA table_info(`radios`)')->fetchAll();
    if ($columns === []) {
        $pdo->exec(file_get_contents(__DIR__.'/schema.sql'));
        $columns = $pdo->query('PRAGMA table_info(`radios`)')->fetchAll();
    }

    $columnNames = array_column($columns, 'name');

    if (!in_array('channel', $columnNames, true)) {
        $pdo->exec('ALTER TABLE `radios` ADD COLUMN `channel` TEXT');
    }
    
    return $pdo;
};

$container['view'] = new \Slim\Views\PhpRenderer($templatesPath);


// MIDDLEWARE

// AUTH

// Basic Auth sends the password on every request, so over plain HTTP it is readable
// by anyone on the network. Hosts without TLS still exist, so this defaults to the
// current behaviour (off) rather than locking anyone out - but turn it on wherever
// HTTPS is available. localhost stays exempt so local development keeps working.
$requireHttps = filter_var($_ENV['AUTH_REQUIRE_HTTPS'] ?? 'false', FILTER_VALIDATE_BOOL);

$app->add(new Tuupola\Middleware\HttpBasicAuthentication([
    'secure' => $requireHttps,
    'relaxed' => ['localhost', '127.0.0.1', '::1'],
    'error' => function ($response, $arguments) use ($container) {
        // Only log attempts where credentials were actually submitted - every
        // first, credential-less request to a protected page also lands here
        // (the browser retries with Basic Auth only after the 401 challenge),
        // and logging those would just be noise.
        $user = $arguments['params']['user'] ?? null;
        if ($user !== null) {
            $escapedUser = htmlspecialchars($user, ENT_QUOTES);
            $container->get('logger')->addWarning(
                'Failed login attempt for user "'.$escapedUser.'".',
                ['action' => 'auth-fail', 'user' => $escapedUser]
            );
        }
    },
    'rules' => [
        new class implements \Tuupola\Middleware\HttpBasicAuthentication\RuleInterface {
            public function __invoke(\Psr\Http\Message\ServerRequestInterface $request): bool {
                // Read the raw URL straight from the browser (e.g. /src/B2) - Slim's own
                // path can be rewritten by the hosting before it gets here.
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                $path = parse_url($uri, PHP_URL_PATH) ?? '';

                // 1. QR-code entry point. Scanning it must work for guests; the route
                //    itself decides where to send them.
                if (preg_match('#/src/[a-zA-Z0-9_-]+/?$#', $path)) {
                    return false;
                }

                // 2. Public status page a guest lands on after scanning.
                if (preg_match('#/public/[a-zA-Z0-9_-]+/?$#', $path)) {
                    return false;
                }

                // 3. Static assets, so CSS and JS load on the public page.
                if (preg_match('#\.(css|js|ico|png|jpg|svg)$#i', $path)) {
                    return false;
                }

                // 4. The voice-assistant endpoint, which authenticates itself with
                //    X-API-Token. Whitelisted by exact path, NOT as the whole /api/
                //    prefix: a prefix rule silently publishes every future /api route,
                //    and a new endpoint added without its own token check would be
                //    unauthenticated with nothing to hint at it.
                if (preg_match('#/api/voice-command/?$#', $path)) {
                    return false;
                }

                // Everything else requires authentication.
                return true;
            }
        }
    ],
    'users' => [
        $_ENV['AUTH_USER'] => $_ENV['AUTH_PASS'],
    ]
]));

// ROUTES

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
    $this->logger->addInfo('Added radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES), ['action' => 'add', 'radioId' => htmlspecialchars($parsedBody['radioId'], ENT_QUOTES)]);
    
    return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('add-new-radio');

$app->post('/import-radio', function (Request $request, Response $response) {
    $importRadio = (string)($request->getParsedBody()['importRadio'] ?? '');
    // The textarea submits \r\n line endings, but PHP_EOL is just \n on Linux,
    // so split on any kind of line break.
    $explodedImportRadio = preg_split('/\R/', $importRadio);

    $query = $this->db->prepare('INSERT INTO `radios` (`radioId`, `name`, `status`, `last-action-time`, `last-borrower`) VALUES (?, ?, ?, ?, ?)');

    foreach ($explodedImportRadio as $singleRadio) {
        $radioData = explode(';', $singleRadio);
        // A line may not have both parts (blank line, missing semicolon) - skip those.
        $radioId = trim(htmlspecialchars($radioData[0] ?? '', ENT_QUOTES));
        $name = trim(htmlspecialchars($radioData[1] ?? '', ENT_QUOTES));

        if ($radioId === '' || $name === '') {
            continue;
        }

        $query->execute([
            $radioId,
            $name,
            'ready',
            getNow(),
            NULL,
        ]);
        $this->logger->addInfo('Added radio from import with ID '.$radioId, ['action' => 'import', 'radioId' => $radioId]);
    }

    return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('import-radio');

$app->post('/delete-radio', function (Request $request, Response $response) {
    $parsedBody = $request->getParsedBody();
    // The form in management-radio.phtml only sends `id`, never `radioId`.
    $id = htmlspecialchars($parsedBody['id'] ?? '', ENT_QUOTES);
    $query = $this->db->prepare('DELETE FROM `radios` WHERE `id` = ?');
    $query->execute([$id]);
    $this->logger->addInfo('Deleted radio with ID '.$id, ['action' => 'delete', 'radioId' => $id]);

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
    $this->logger->addInfo('Changed channel for radio with ID '.htmlspecialchars($parsedBody['radioId'], ENT_QUOTES), ['action' => 'channel', 'radioId' => htmlspecialchars($parsedBody['radioId'], ENT_QUOTES)]);

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
    $signature = getSignatureFromRequest($parsedBody);

    switch ($argumentAction) {
        case 'lend':
            $borrower = htmlspecialchars($parsedBody['borrower'], ENT_QUOTES);
            $lastBorrower = htmlspecialchars($parsedBody['last-borrower'], ENT_QUOTES);
            if (empty($borrower) && !empty($lastBorrower)) {
                $borrower = $lastBorrower;
            }
            $query = $this->db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ?, `last-borrower` = ? WHERE `id` = ?');
            $query->execute(['lent', getNow(), $borrower, $id]);
            $this->logger->addInfo('Radio with ID '.$radioId.' is lent to '.$borrower.' by '.$signature.'.', ['action' => 'lend', 'radioId' => $radioId, 'borrower' => $borrower, 'signature' => $signature]);
            break;
        case 'return':
            $query = $this->db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ? WHERE `id` = ?');
            $query->execute(['charging', getNow(), $id]);
            $this->logger->addInfo('Radio with ID '.$radioId.' is returned by '.$signature.'.', ['action' => 'return', 'radioId' => $radioId, 'signature' => $signature]);
            break;
        case 'charged':
            $query = $this->db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ? WHERE `id` = ?');
            $query->execute(['ready', getNow(), $id]);
            $this->logger->addInfo('Radio with ID '.$radioId.' is set as fully charged.', ['action' => 'charge-complete', 'radioId' => $radioId]);
            break;
        default:
            $this->logger->addWarning('Unknown radio-action "'.$argumentAction.'" for radio with ID '.$radioId.'.', ['action' => 'unknown-action', 'radioId' => $radioId]);
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
    $logLimit = 500;
    $logData = file_get_contents($this->settings['logPath']);
    $queryParams = $request->getQueryParams();

    if (!isset($queryParams['dateFrom']) && !isset($queryParams['dateTo'])) {
        $now = new DateTime('now', new DateTimeZone('Europe/Prague'));
        $dateTo = $now->format('Y-m-d');
        $dateFrom = $now->modify('-24 hours')->format('Y-m-d');
    } else {
        $dateFrom = $queryParams['dateFrom'] ?? '';
        $dateTo = $queryParams['dateTo'] ?? '';
    }
    $level = $queryParams['level'] ?? '';
    $action = $queryParams['action'] ?? '';

    $parsed = parseLogData($logData, $logLimit, $dateFrom, $dateTo, $level, $action);

    return $this->view->render($response, 'log.phtml', [
        'router' => $this->router,
        'log' => $parsed['entries'],
        'logLimit' => $logLimit,
        'logLevels' => $parsed['levels'],
        'logActions' => $parsed['actions'],
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'level' => $level,
        'action' => $action,
    ]);
})->setName('log');

$app->post('/fast-return', function (Request $request, Response $response) {
    $parsedBody = $request->getParsedBody();
    $radioId = htmlspecialchars($parsedBody['radioId'], ENT_QUOTES);
    $signature = getSignatureFromRequest($parsedBody);
    $query = $this->db->prepare('UPDATE `radios` SET `status` = "ready", `last-action-time` = ? WHERE `radioId` = ?');
    $query->execute([
        getNow(),
        $radioId,
    ]);
    $this->logger->addInfo('Radio with ID '.$radioId.' is fast-returned by '.$signature.'.', ['action' => 'fast-return', 'radioId' => $radioId, 'signature' => $signature]);

    return $response->withHeader('Location', $this->router->pathFor('radio-list'));
})->setName('fast-return');

$app->post('/fast-lent', function (Request $request, Response $response) {
    $parsedBody = $request->getParsedBody();
    $radioId = htmlspecialchars($parsedBody['radioId'], ENT_QUOTES);
    $borrower = htmlspecialchars($parsedBody['borrower'], ENT_QUOTES);
    $signature = getSignatureFromRequest($parsedBody);

    $query = $this->db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ?, `last-borrower` = ? WHERE `radioId` = ?');
    $query->execute([
        'lent',
        getNow(),
        $borrower,
        $radioId,
    ]);
    $this->logger->addInfo('Radio with ID '.$radioId.' is lent to '.$borrower.' by '.$signature.'.', ['action' => 'fast-lend', 'radioId' => $radioId, 'borrower' => $borrower, 'signature' => $signature]);

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
    $uri = $request->getUri();
    $host = $uri->getHost();
    $port = $uri->getPort();
    $baseUrl = sprintf(
        '%s://%s%s',
        $uri->getScheme(),
        $host,
        $port !== null ? ':'.$port : ''
    );

    return $this->view->render($response, 'qr.phtml', [
        'router' => $this->router,
        'base_uri' => $baseUrl,
        'radios' => $radios,
        'qr_options' => $options,
    ]);
})->setName('qr-generate');

$app->get('/src/{radioId}', function (Request $request, Response $response, array $args) {
    $radioId = trim((string)$args['radioId']);
    $radio = fetchRadioByColumn($this->db, $this->logger, 'radioId', $radioId);

    if (!$radio) {
        return $response->withStatus(404)->write('Radio not found.');
    }

    if (hasValidBasicAuthCredentials()) {
        return $response->withHeader('Location', $this->router->pathFor('radio-list').'?filter='.rawurlencode($radioId));
    }

    return $response->withHeader('Location', $this->router->pathFor('public-radio-info', ['radioId' => $radioId]));
})->setName('radio-scan-entry');

$app->get('/public/{radioId}', function (Request $request, Response $response, array $args) {
    $radioId = trim((string)$args['radioId']);
    $radio = fetchRadioByColumn($this->db, $this->logger, 'radioId', $radioId);

    if (!$radio) {
        return $response->withStatus(404)->write('Radio not found.');
    }

    return $this->view->render($response, 'public-radio-info.phtml', [
        'radio' => $radio,
        'statusDictionary' => getStatusDictionary(),
    ]);
})->setName('public-radio-info');

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
    $initialFilter = trim((string)($request->getQueryParams()['filter'] ?? ''));
    
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
        'initialFilter' => $initialFilter,
        'radioCounts' => getRadioCounts($this->db),
        'statusDictionary' => getStatusDictionary(),
    ]);
})->setName('radio-list');

$app->post('/api/voice-command', function (Request $request, Response $response) {
    // 1. Bezpečnostní kontrola přes naši vlastní hlavičku
    $providedToken = $request->getHeaderLine('X-API-Token');
    $expectedToken = $_ENV['API_TOKEN'] ?? '';

    if (empty($_ENV['API_TOKEN']) || !hash_equals($expectedToken, $providedToken)) {
        $this->logger->addWarning('Voice command: Neoprávněný přístup (špatný token).', ['action' => 'voice-auth-fail']);
        return jsonResponse($response, ['error' => 'Unauthorized'], 401);
    }

    // 2. Načtení a vyčištění dat z Pythonu
    $parsedBody = $request->getParsedBody();
    $radioId = trim(htmlspecialchars($parsedBody['radio_id'] ?? '', ENT_QUOTES));
    $borrower = trim(htmlspecialchars($parsedBody['jmeno'] ?? '', ENT_QUOTES));
    $channel = trim(htmlspecialchars($parsedBody['kanal'] ?? '', ENT_QUOTES));

    if (empty($radioId) || empty($borrower) || empty($channel)) {
        $this->logger->addWarning('Voice command: Chybí povinná data (radio_id/jmeno/kanal).', ['action' => 'voice-missing-data', 'radioId' => $radioId]);
        return jsonResponse($response, ['error' => 'Missing data'], 400);
    }

    // 3. Kontrola, zda rádio s tímto ID vůbec v systému existuje
    $checkQuery = $this->db->prepare('SELECT `id` FROM `radios` WHERE `radioId` = ?');
    $checkQuery->execute([$radioId]);
    if (!$checkQuery->fetch()) {
        $this->logger->addWarning('Voice command: Rádio s ID ' . $radioId . ' nebylo v databázi nalezeno.', ['action' => 'voice-radio-not-found', 'radioId' => $radioId]);
        return jsonResponse($response, ['error' => 'Radio not found'], 404);
    }

    // 4. Uložení do databáze (Nastaví rádio jako vypůjčené, přiřadí člověka, kanál a aktuální čas)
    $query = $this->db->prepare('UPDATE `radios` SET `status` = ?, `last-action-time` = ?, `last-borrower` = ?, `channel` = ? WHERE `radioId` = ?');
    $query->execute([
        'lent',
        getNow(),
        $borrower,
        $channel,
        $radioId,
    ]);

    // 5. Zapsání do logu a odpověď Pythonu
    $this->logger->addInfo("Voice command: Rádio $radioId vypůjčeno pro $borrower na kanál $channel.", ['action' => 'voice-lend', 'radioId' => $radioId, 'borrower' => $borrower, 'channel' => $channel]);

    return jsonResponse($response, [
        'success' => true,
        'message' => "Rádio $radioId bylo přiřazeno uživateli $borrower na kanál $channel."
    ]);
})->setName('api-voice-command');

// FIRE!

try {
    $app->run();
} catch (Throwable $e) {
    echo 'Pardon, radio ztratilo spojení...';
    die;
}