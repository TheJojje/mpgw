# Mail Parser Gateway (mpgw)

Hamtar mail via IMAP, later en manniska interaktivt bygga extraktionsregler
genom att markera text i ett exempelmail, kor reglerna automatiskt mot
framtida mail, och exponerar resultatet via ett nyckelskyddat API.

## Forutsattningar

- PHP 8.2+ med `pdo_mysql`, `sodium`, `imap` (kor `php -m | grep imap` -
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

# Skapa en adminanvandare (finns inget UI for detta i MVP):
php -r "echo password_hash('DITT-LOSENORD', PASSWORD_DEFAULT), PHP_EOL;"
mysql -u ... -p ... -e "INSERT INTO users (email, password_hash) VALUES ('din@mail.se', '<hash fran ovan>');"
```

## Kora lokalt

```bash
php -S 127.0.0.1:8811 -t public
```

Ga till http://127.0.0.1:8811/admin/login.

## Cron (IMAP-polling)

```
*/2 * * * * /usr/bin/php8.3 /var/www/html/apps/int/mpgw/bin/poll-mailboxes.php >> /var/www/html/apps/int/mpgw/var/log/poll.log 2>&1
```

## Tester

```bash
vendor/bin/phpunit tests/Unit
```

## Hamta data via API:t

Varje API-nyckel ar knuten till exakt en applikation (valjs nar nyckeln
skapas under **Admin > API-nycklar > Ny nyckel**) och kan bara lasa data
for den applikationen. API:t nas i produktion pa **https://mp.larssons.space/api/v1/...**
(`bin/fetch-records.php` anvander den URL:en som standard -
`--base-url=http://127.0.0.1:8811` for att kora mot en lokal dev-server
istallet).

mpgw tar sjalv ingen stallning till vad ett mail *betyder* - varje record
i API-svaret ar bara rubrik + de falt en mall extraherat, det ar upp till
klienten att tolka datat utifran sitt eget anvandningsfall (t.ex. avgora
om ett mail ar en bokning eller en avbokning genom att titta pa rubriken
eller ett extraherat falt).

`bin/fetch-records.php` ar en fristaende exempelklient (bara cURL, ingen
kod delad med applikationen) som paginerar igenom `/api/v1/records` och
skriver resultatet till fil:

```bash
# JSON, inklusive extraherade falt
php bin/fetch-records.php --key=mpgw_xxx --out=records.json --with-fields

# CSV, filtrerat pa status
php bin/fetch-records.php --key=mpgw_xxx --out=records.csv --status=success --with-fields
```

Kor `php bin/fetch-records.php` utan argument for full flagg-lista.

## Sa har bygger du en mall

1. **Applikationer > Ny applikation** - registrera namn + IMAP-uppgifter
   for avsandarens mailbox.
2. Pa applikationssidan: **Ny mall** - ange fran-adress/amne-villkor for
   att matcha ratt mailtyp (forsta matchande mall i prioritetsordning
   anvands).
3. I mallbyggaren: klistra in ett riktigt exempelmail (eller valj ett
   redan hamtat mail fran listan) och tryck **Normalisera & visa**.
4. Markera en textbit i den normaliserade texten - du far forslag pa
   regex-monster (generaliserat/literalt), med live-test mot samma
   mail. Justera monstret fritt vid behov.
5. **Spara regel.** Upprepa for alla falt du vill extrahera.
6. Nar cron kor `bin/poll-mailboxes.php` matchas nya mail mot mallen och
   fatlen sparas automatiskt i `extracted_fields`.

## MVP-omfang (nuvarande status)

Byggt: applikations/mailbox-registrering (krypterade IMAP-uppgifter), IMAP-
polling, ratt-mail-lagring, HTML->text-normalisering, mallmatchning,
interaktiv mallbyggare med regex-forslag och live-test, regelmotor,
mailhistorik, nyckelskyddat las-API med scopes och rate limiting.

**Inte byggt an** (se planen, iteration 2-4): automatiska format-change-
larm (tabellerna `alerts`/`ingest_log` finns och loggas till, men ingen
`AlertEvaluator`/`AlertNotifier` kor an), GDPR-purge av gamla rattmail,
multi-user-roller.
