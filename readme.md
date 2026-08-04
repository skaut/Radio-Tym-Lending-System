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

Zkopírovat `.env.example` na `.env` a vyplnit `AUTH_USER` / `AUTH_PASS`. Bez `.env` se aplikace
nespustí a vypíše, co chybí.

## Lokální běh přes Docker

`$ docker-compose up -d`

Repo se mountuje do kontejneru jako volume, takže `vendor/` musí existovat i na hostu. Pokud
hostitelský PHP nemá verzi 8.4 (`composer install` na hostu selže na `Root composer.json requires
php ^8.4`), spusť Composer rovnou v kontejneru, který má správnou verzi PHP:

`$ docker compose exec app composer install`

Kontejner běží jako `www-data`, ale bind-mount zachovává vlastníka souborů z hosta - pokud UID
hostitelského uživatele neodpovídá `www-data` (uid 33) v kontejneru, `775`/`664` z FTP návodu výše
nestačí (`www-data` spadá do "other", ne do vlastnící skupiny). Pro lokální Docker vývoj je
nejjednodušší nastavit:

`$ chmod 777 src logs && chmod 666 src/rtls.sqlite logs/rtls.log`

`docker-compose.yml` mapuje porty `80` i `443`, ale kontejner neterminuje TLS - navštívit
`http://localhost/` (ne `https://`), jinak prohlížeč skončí na chybě handshake
(`PR_END_OF_FILE_ERROR` ve Firefoxu).

## Nasazení na sdílený hosting přes FTP

Hosting nemá Composer, takže se nahrává i složka `vendor/`. Postup:

1. Lokálně `$ composer install --no-dev --optimize-autoloader`
2. Nahrát přes FTP do kořene webu (na Lebeda hostingu složka `/www`):
   `index.php`, `.htaccess`, `src/`, `templates/`, `public/`, `dbadmin/`, `vendor/`, `logs/`, `.env.example`
3. Na serveru zkopírovat `.env.example` na `.env` a vyplnit `AUTH_USER`, `AUTH_PASS` a případně `API_TOKEN`.
4. Nastavit práva: `775` pro složky `src/` a `logs/`, `664` pro `src/rtls.sqlite` a `logs/rtls.log`.
   Zapisovatelná musí být i složka `src/`, protože si SQLite vedle databáze zakládá dočasné soubory.
5. Otevřít `/` - přihlášení je Basic Auth podle `.env`. Rádia se naplní v `/management-radio`
   (jednotlivě nebo hromadným importem ve formátu `ID;Název` po řádcích).

Nenahrávat `deploy/`, `Dockerfile`, `docker-compose.yml`, `.git/` ani `.idea/` - na hostingu nejsou k ničemu.

`.htaccess` blokuje přímé stažení `.env`, databáze (včetně SQLite souborů `-journal`/`-wal`/`-shm`),
logů a souborů Composeru. Kdyby hosting `.htaccess` ignoroval, byly by tyhle soubory veřejně čitelné -
což znamená vyzrazené heslo do administrace.

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
