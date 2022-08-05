# RTLS

### Systém pro vypůjčování rádií na skautských akcích

## Install

`$ git clone https://github.com/skaut/Radio-Tym-Lending-System.git`

`$ composer update`

navštívit URL `[server]/rtls/src`, popř. nasměrovat vrituál do této složky

Nastavit permissions `rtls.sqlite` writable pro nginx.

## Use

Menu nahoře nabízí přehled všech vypůjčených rádií, přidání nového stroje a pohled do celkového logu.
Online demo na webu výše

## Techs

**Databáze** - je pouitá SQlite, aby byla instalace co nejjednodušší. Při použití více různých sad rádíí stačí soubor s databází `src/rtls.sqlite` nahrazovat. Doporučuji mít základní stav rádií před akcí uložený a vždy před začátkem ho použít znovu. Je také možnost zálohováním tohoto souboru dělat zálohy jednotlivých akcí a potom je archivovat, takže bude možné dozadu dohledat, co se s jednotlivými rádii dělo.

**Server** - jde použít Nginx nebo Apache2 (potom je dobré nastavit virtuální složku do projektové složky `src/`, aby přístup do aplikace měl hezkou URL.

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
