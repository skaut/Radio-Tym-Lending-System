<?php

function getNow() {
    return (new DateTime('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s');
}
