<?php

function getNow() {
    return (new DateTime('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s');
}

function getSignatureFromRequest($parsedBody) {
    return htmlspecialchars(trim($parsedBody['signature'] ?? ''), ENT_QUOTES);
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
