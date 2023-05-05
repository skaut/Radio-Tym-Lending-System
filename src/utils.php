<?php

function getNow() {
    return (new DateTime('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s');
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
