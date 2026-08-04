<?php

function getNow() {
    return (new DateTime('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s');
}

function getSignatureFromRequest($parsedBody) {
    return htmlspecialchars(trim($parsedBody['signature'] ?? ''), ENT_QUOTES);
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

    $userMatches = hash_equals($expectedUser, $user);
    $passMatches = hash_equals($expectedPass, $pass);

    return $userMatches && $passMatches;
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

function parseLogData($rawLog, $limit, $dateFrom = null, $dateTo = null, $level = null, $action = null) {
    $lines = array_filter(explode(PHP_EOL, trim($rawLog)), fn($line) => $line !== '');
    $entries = [];
    $levels = [];
    $actions = [];

    foreach ($lines as $line) {
        $entry = parseLogLine($line);
        $entries[] = $entry;

        if ($entry['level'] !== '' && !in_array($entry['level'], $levels, true)) {
            $levels[] = $entry['level'];
        }
        if ($entry['action'] !== '' && !in_array($entry['action'], $actions, true)) {
            $actions[] = $entry['action'];
        }
    }

    $filtered = array_filter($entries, function ($e) use ($dateFrom, $dateTo, $level, $action) {
        if ($dateFrom && $e['date'] !== '' && $e['date'] < $dateFrom) {
            return false;
        }
        if ($dateTo && $e['date'] !== '' && $e['date'] > $dateTo) {
            return false;
        }
        if ($level && $e['level'] !== $level) {
            return false;
        }
        if ($action && $e['action'] !== $action) {
            return false;
        }
        return true;
    });

    $filtered = array_slice(array_values($filtered), -$limit);
    sort($levels);
    sort($actions);

    return [
        'entries' => array_reverse($filtered),
        'levels' => $levels,
        'actions' => $actions,
    ];
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
