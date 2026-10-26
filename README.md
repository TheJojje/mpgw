# Mail Parser Gateway (mpgw)

Hämtar mail via IMAP, låter en människa interaktivt bygga extraktionsregler
genom att markera text i ett exempelmail, kör reglerna automatiskt mot
framtida mail, och exponerar resultatet via ett nyckelskyddat API.

## Förutsättningar

- PHP 8.2+ med `pdo_mysql`, `sodium`, `imap` (kör `php -m | grep imap` -
  saknas den: `sudo apt install php8.3-imap` och starta om webbservern)
- MySQL/MariaDB
- Composer

## Installation

```bash
composer install
cp .env.example .env
# Fyll i DB_* och generera APP_SECRET_KEY:
php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"

php bin/migrate.php   # skapar databasen + alla tabeller

# Skapa en adminanvändare (finns inget UI för detta i MVP):
php -r "echo password_hash('DITT-LÖSENORD', PASSWORD_DEFAULT), PHP_EOL;"
mysql -u ... -p ... -e "INSERT INTO users (email, password_hash) VALUES ('din@mail.se', '<hash från ovan>');"
```

## Köra lokalt

```bash
php -S 127.0.0.1:8811 -t public
```

Gå till http://127.0.0.1:8811/admin/login.

## Cron (IMAP-polling)

```
*/2 * * * * /usr/bin/php8.3 /var/www/html/apps/int/mpgw/bin/poll-mailboxes.php >> /var/www/html/apps/int/mpgw/var/log/poll.log 2>&1
```

## Tester

```bash
vendor/bin/phpunit tests/Unit
```

## Hämta data via API:t

Varje API-nyckel är knuten till exakt en applikation (väljs när nyckeln
skapas under **Admin > API-nycklar > Ny nyckel**) och kan bara läsa data
för den applikationen. API:t nås i produktion på **https://mp.larssons.space/api/v1/...**
(`bin/fetch-records.php` använder den URL:en som standard -
`--base-url=http://127.0.0.1:8811` för att köra mot en lokal dev-server
istället).

mpgw tar själv ingen ställning till vad ett mail *betyder* - varje record
i API-svaret är bara rubrik + de fält en mall extraherat, det är upp till
klienten att tolka datat utifrån sitt eget användningsfall (t.ex. avgöra
om ett mail är en bokning eller en avbokning genom att titta på rubriken
eller ett extraherat fält).

`bin/fetch-records.php` är en fristående exempelklient (bara cURL, ingen
kod delad med applikationen) som paginerar igenom `/api/v1/records` och
skriver resultatet till fil:

```bash
# JSON, inklusive extraherade fält
php bin/fetch-records.php --key=mpgw_xxx --out=records.json --with-fields

# CSV, filtrerat på status
php bin/fetch-records.php --key=mpgw_xxx --out=records.csv --status=success --with-fields
```

Kör `php bin/fetch-records.php` utan argument för full flagg-lista.

## Så här bygger du en mall

1. **Applikationer > Ny applikation** - registrera namn + IMAP-uppgifter
   för avsändarens mailbox.
2. På applikationssidan: **Ny mall** - ange från-adress/ämne-villkor för
   att matcha rätt mailtyp (första matchande mall i prioritetsordning
   används).
3. I mallbyggaren: klistra in ett riktigt exempelmail (eller välj ett
   redan hämtat mail från listan) och tryck **Normalisera & visa**.
4. Markera en textbit i den normaliserade texten - du får förslag på
   regex-mönster (generaliserat/literalt), med live-test mot samma
   mail. Justera mönstret fritt vid behov.
5. **Spara regel.** Upprepa för alla fält du vill extrahera.
6. När cron kör `bin/poll-mailboxes.php` matchas nya mail mot mallen och
   fälten sparas automatiskt i `extracted_fields`.

## MVP-omfång (nuvarande status)

Byggt: applikations/mailbox-registrering (krypterade IMAP-uppgifter), IMAP-
polling, rått-mail-lagring, HTML->text-normalisering, mallmatchning,
interaktiv mallbyggare med regex-förslag och live-test, regelmotor,
mailhistorik, nyckelskyddat läs-API med scopes och rate limiting.

**Inte byggt än** (se planen, iteration 2-4): automatiska format-change-
larm (tabellerna `alerts`/`ingest_log` finns och loggas till, men ingen
`AlertEvaluator`/`AlertNotifier` kör än), GDPR-purge av gamla råmail,
multi-user-roller.
