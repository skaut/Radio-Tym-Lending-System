# RTLS

### Systém pro vypůjčování rádií na skautských akcích

## Install

`$ git clone https://github.com/skaut/Radio-Tym-Lending-System.git`

`$ composer install`

Požadavky:
- PHP 8.4
- Composer
- PHP extensions `pdo_sqlite`, `mbstring` a `simplexml`

Pro lokální běh na Debian stable:

`$ sudo apt install php php-cli php-sqlite3 php-mbstring php-xml composer unzip`

Pak navštívit URL `[server]/`, popř. nasměrovat virtuál do kořene projektu.

Nastavit permissions `src/rtls.sqlite` a `logs/rtls.log` writable pro uživatele webserveru.

## Use

Menu nahoře nabízí přehled všech vypůjčených rádií, přidání nového stroje a pohled do celkového logu.
Online demo na webu výše

`/dbadmin` zpřístupňuje jednoduchý databázový admin nad SQLite přes Adminer. Je chráněný stejným Basic Auth jako zbytek aplikace.

## Techs

**Databáze** - je pouitá SQlite, aby byla instalace co nejjednodušší. Při použití více různých sad rádíí stačí soubor s databází `src/rtls.sqlite` nahrazovat. Doporučuji mít základní stav rádií před akcí uložený a vždy před začátkem ho použít znovu. Je také možnost zálohováním tohoto souboru dělat zálohy jednotlivých akcí a potom je archivovat, takže bude možné dozadu dohledat, co se s jednotlivými rádii dělo.

**Server** - jde použít Nginx nebo Apache2. Doporučené je nasměrovat virtuál do kořene projektu, aby byly dostupné i statické soubory z `public/`.

Lze také použít vestavěný PHP server spuštěním příkazu `$ php -S localhost:8000` se žádaným portem, potom ale aplikace nebude přístupná online (vhodné pro řešení na jednom PC, protože se potom nemusí řešit online zabezpečení).

### nginx config

Součástí repa je `.htaccess` file pro Apache, který není nginxem interpretován. Je třeba napsat si přepsání URL takto:
```
  location / {
    # ..
    try_files $uri $uri/ /src/index.php$args;
    # ..
  }
```
