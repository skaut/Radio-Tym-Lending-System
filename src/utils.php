<?php

function getNow() {
    return (new DateTime('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s');
}

function getSignatureFromRequest($parsedBody) {
    return htmlspecialchars(trim($parsedBody['signature'] ?? ''), ENT_QUOTES);
}

/**
 * Start the PHP session, but only when something actually needs it.
 *
 * Called lazily from csrfToken() and csrfTokenMatches() rather than at boot, so the
 * public pages a guest reaches by scanning a QR code never create a session file.
 * At a big event those scans vastly outnumber admin requests.
 *
 * Sessions are stored in logs/sessions/ when RTLS_SESSION_PATH points at a writable
 * directory (deploy.sh already creates and preserves it); otherwise PHP's default
 * save path is used, which still works - it just isn't preserved across deploys.
 */
function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (defined('RTLS_SESSION_PATH') && is_dir(RTLS_SESSION_PATH) && is_writable(RTLS_SESSION_PATH)) {
        session_save_path(RTLS_SESSION_PATH);
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
    ]);
    session_start();
}

/**
 * The CSRF token for this session, minted on first use.
 */
function csrfToken(): string
{
    startAppSession();

    if (empty($_SESSION['csrfToken'])) {
        $_SESSION['csrfToken'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrfToken'];
}

/**
 * Hidden form field carrying the CSRF token. Every mutating form needs one.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(csrfToken(), ENT_QUOTES).'">';
}

/**
 * Check a submitted CSRF token against the session.
 *
 * Deliberately does NOT mint a token when none exists yet: a request that arrives
 * before any form was ever rendered has nothing legitimate to prove, and minting
 * here would make the very first forged request succeed.
 */
function csrfTokenMatches(?string $submitted): bool
{
    startAppSession();

    $expected = $_SESSION['csrfToken'] ?? '';

    if ($expected === '' || $submitted === null || $submitted === '') {
        return false;
    }

    return hash_equals($expected, $submitted);
}

/**
 * Read the Basic Auth credentials the browser sent, as ['user' => ?string, 'pass' => ?string].
 *
 * Some hosts (Lebeda among them) never populate PHP_AUTH_USER/PHP_AUTH_PW, so fall
 * back to decoding the raw Authorization header. Both the app and /dbadmin use this
 * so the two cannot disagree about who is logged in.
 */
function readBasicAuthCredentials(): array
{
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;

    if ($user !== null) {
        return ['user' => $user, 'pass' => (string)$pass];
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($authHeader, 'basic ') !== 0) {
        return ['user' => null, 'pass' => null];
    }

    $decoded = base64_decode(substr($authHeader, 6), true);
    if ($decoded === false || !str_contains($decoded, ':')) {
        return ['user' => null, 'pass' => null];
    }

    [$user, $pass] = explode(':', $decoded, 2);

    return ['user' => $user, 'pass' => $pass];
}

/**
 * Compare submitted Basic Auth credentials against the expected pair.
 *
 * Fails closed: empty expected credentials never authenticate anything. Without that,
 * a missing or unreadable .env silently turns into "everyone is an admin". Comparison
 * is timing-safe.
 */
function basicAuthCredentialsMatch(string $expectedUser, string $expectedPass, ?string $user, ?string $pass): bool
{
    if ($expectedUser === '' || $expectedPass === '' || $user === null || $pass === null) {
        return false;
    }

    return hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
}

function parseLogLine($line) {
    $empty = ['date' => '', 'time' => '', 'level' => '', 'message' => $line, 'action' => '', 'radioId' => '', 'signature' => ''];

    if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\] \S+\.(\w+): (.*)$/s', $line, $header)) {
        return $empty;
    }

    // Monolog's LineFormatter always appends the JSON-encoded context and extra
    // arrays after the message, even when empty (as "[]"), so the tail of every
    // line is "<message> <context-json> <extra-json>".
    if (!preg_match('/^(.*) (\{.*\}|\[\]) (\{.*\}|\[\])$/s', $header[4], $body)) {
        return array_merge($empty, [
            'date' => $header[1],
            'time' => $header[2],
            'level' => $header[3],
            'message' => $header[4],
        ]);
    }

    $context = json_decode($body[2], true) ?? [];

    return [
        'date' => $header[1],
        'time' => $header[2],
        'level' => $header[3],
        'message' => $body[1],
        'action' => (string)($context['action'] ?? ''),
        'radioId' => (string)($context['radioId'] ?? ''),
        'signature' => (string)($context['signature'] ?? ''),
    ];
}

function parseLogLines($rawLog, $limit, $dateFrom = null, $dateTo = null, $level = null, $action = null) {
    $lines = array_filter(explode(PHP_EOL, trim($rawLog)), fn($line) => $line !== '');
    $entries = array_map('parseLogLine', $lines);

    if ($dateFrom) {
        $entries = array_filter($entries, fn($e) => $e['date'] === '' || $e['date'] >= $dateFrom);
    }
    if ($dateTo) {
        $entries = array_filter($entries, fn($e) => $e['date'] === '' || $e['date'] <= $dateTo);
    }
    if ($level) {
        $entries = array_filter($entries, fn($e) => $e['level'] === $level);
    }
    if ($action) {
        $entries = array_filter($entries, fn($e) => $e['action'] === $action);
    }

    $entries = array_slice(array_values($entries), -$limit);

    return array_reverse($entries);
}

function getLogLevels($rawLog) {
    preg_match_all('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \S+\.(\w+):/m', $rawLog, $matches);

    $levels = array_unique($matches[1]);
    sort($levels);

    return $levels;
}

function getLogActions($rawLog) {
    $lines = array_filter(explode(PHP_EOL, trim($rawLog)), fn($line) => $line !== '');
    $actions = array_unique(array_filter(array_map(fn($line) => parseLogLine($line)['action'], $lines)));
    sort($actions);

    return $actions;
}

function removeDiacritic($input) {
    $diacritic = [
        '/[áàâãªä]/u' => 'a',
        '/[ÁÀÂÃÄ]/u' => 'A',
        '/[čç]/u' => 'c',
        '/[ČÇ]/u' => 'C',
        '/[ď]/u' => 'd',
        '/[Ď]/u' => 'D',
        '/[éèêëéě]/u' => 'e',
        '/[ÉÈÊËÉĚ]/u' => 'E',
        '/[íìîï]/u' => 'i',
        '/[ÍÌÎÏ]/u' => 'I',
        '/[ňñ]/u' => 'n',
        '/[ŇÑ]/u' => 'N',
        '/[óòôõºö]/u' => 'o',
        '/[ÓÒÔÕÖ]/u' => 'O',
        '/[ř]/u' => 'r',
        '/[Ř]/u' => 'R',
        '/[š]/u' => 's',
        '/[Š]/u' => 'S',
        '/[ť]/u' => 't',
        '/[Ť]/u' => 'T',
        '/[úùûüúů]/u' => 'u',
        '/[ÚÙÛÜÚŮ]/u' => 'U',
        '/[ž]/u' => 'z',
        '/[Ž]/u' => 'Z',
    ];

    return preg_replace(array_keys($diacritic), array_values($diacritic), $input);
}
