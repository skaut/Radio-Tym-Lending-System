<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$sqlitePath = realpath($projectRoot . '/src/rtls.sqlite') ?: $projectRoot . '/src/rtls.sqlite';
$sqliteDirectory = dirname($sqlitePath);

$dotenv = Dotenv::createImmutable($projectRoot);
$dotenv->safeLoad();

$expectedUser = $_ENV['AUTH_USER'] ?? '';
$expectedPass = $_ENV['AUTH_PASS'] ?? '';

$providedUser = $_SERVER['PHP_AUTH_USER'] ?? null;
$providedPass = $_SERVER['PHP_AUTH_PW'] ?? null;

if (
    $expectedUser !== ''
    && ($providedUser !== $expectedUser || $providedPass !== $expectedPass)
) {
    header('WWW-Authenticate: Basic realm="RTLS DB Admin"');
    header('HTTP/1.1 401 Unauthorized');
    echo 'Authentication required.';
    exit;
}

$writeChecks = [
    'db_exists' => file_exists($sqlitePath),
    'db_writable' => is_writable($sqlitePath),
    'dir_writable' => is_dir($sqliteDirectory) && is_writable($sqliteDirectory),
];

function adminer_object()
{
    class AdminerRtls extends Adminer\Adminer
    {
        public function name(): string
        {
            return 'RTLS DB Admin';
        }

        public function login($login, $password): bool|string
        {
            global $sqlitePath;

            $db = $_GET['db'] ?? $_POST['auth']['db'] ?? '';

            if ((isset($_GET['sqlite']) || (($_POST['auth']['driver'] ?? '') === 'sqlite')) && $db === $sqlitePath) {
                return true;
            }

            return 'Only the RTLS SQLite database is allowed on this endpoint.';
        }
    }

    return new AdminerRtls();
}

ob_start(static function (string $html) use ($sqlitePath, $sqliteDirectory, $writeChecks): string {
    $messages = [
        sprintf(
            "<p class='message'>Use <strong>SQLite</strong> and database file <code>%s</code>.</p>",
            htmlspecialchars($sqlitePath, ENT_QUOTES)
        ),
    ];

    if (!$writeChecks['db_exists']) {
        $messages[] = sprintf(
            "<p class='error'>SQLite file does not exist: <code>%s</code>.</p>",
            htmlspecialchars($sqlitePath, ENT_QUOTES)
        );
    } elseif (!$writeChecks['db_writable'] || !$writeChecks['dir_writable']) {
        $messages[] = sprintf(
            "<p class='error'>SQLite is not writable for the webserver. File writable: <strong>%s</strong>, directory writable: <strong>%s</strong>. Both are required because SQLite creates journal/WAL files next to the database in <code>%s</code>.</p>",
            $writeChecks['db_writable'] ? 'yes' : 'no',
            $writeChecks['dir_writable'] ? 'yes' : 'no',
            htmlspecialchars($sqliteDirectory, ENT_QUOTES)
        );
    }

    $html = str_replace('<h2>Login</h2>', '<h2>Login</h2>' . implode('', $messages), $html);
    $html = str_replace(
        '<option value="server" selected>MySQL / MariaDB<option value="sqlite">SQLite',
        '<option value="server">MySQL / MariaDB<option value="sqlite" selected>SQLite',
        $html
    );
    $html = str_replace(
        '<input name="auth[db]" value="" autocapitalize="off">',
        sprintf(
            '<input name="auth[db]" value="%s" autocapitalize="off">',
            htmlspecialchars($sqlitePath, ENT_QUOTES)
        ),
        $html
    );

    return $html;
});

require __DIR__ . '/adminer.php';
