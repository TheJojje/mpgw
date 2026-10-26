# Mail Parser Gateway (mpgw)

Hämtar mail via IMAP, låter en människa interaktivt bygga extraktionsregler
genom att markera text i ett exempelmail, kör reglerna automatiskt mot
framtida mail, och exponerar resultatet via ett nyckelskyddat, skrivskyddat API.

mpgw tolkar inte vad ett mail *betyder*. Varje post i API:t är bara de fält en
mall extraherat; det är upp till konsumenten att tolka dem (t.ex. avgöra om ett
mail är en bokning eller en avbokning). Valfria **trådar** kan koppla ihop poster
som hör till samma ärende, utan att mpgw vet något om domänen.

## Funktioner

- **Applikationer** med egna mailboxar (IMAP-lösenord krypterade i databasen), mallar och regler.
- **Mallbyggare:** markera text i ett exempelmail och få regex-förslag med live-test. Regler kan läsa
  brödtext, ämnesrad eller mail-headers (t.ex. `Date:` för när mailet skickades).
- **Pollning:** cron hämtar nya mail (eller *Polla nu* på mailboxen i admin). Rått mail lagras krypterat.
- **Trådar:** per applikation konfigureras vilka fält som identifierar ett ärende och vilka värden som
  öppnar/stänger det. Avvikelser (t.ex. avbokning utan bokning) ger larm.
- **Larm:** delvis matchning, trådavvikelser, mail utan mall, brevlåda som inte går att nå. Kvittera i admin.
- **Mailhistorik:** sök, filtrera, se tråd, parsa om ett mail med nuvarande regler, radera (raderade mail
  importeras inte om).
- **Gallring:** retention per applikation för extraherad data och för råmail.
- **Användare, roller och behörigheter** (RBAC), audit-logg, inloggningsspärr, SSO via OIDC (authentik).
- **Nyckelskyddat API** med scopes och rate limiting. Fullständig referens finns i admin under
  *Hjälp & API*.

## Förutsättningar

- PHP 8.2+ med `pdo_mysql`, `sodium`, `openssl`, `mbstring`, `iconv`, `libxml`, `zip` och `fileinfo`
  (IMAP-biblioteket `webklex/php-imap` pratar protokollet själv, så `ext-imap` behövs inte)
- MySQL/MariaDB
- Composer

## Installation

```bash
composer install
cp .env.example .env
# Fyll i DB_* och generera APP_SECRET_KEY:
php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"

php bin/migrate.php   # skapar databasen + alla tabeller (kan köras om, är idempotent)
```

**Spara `APP_SECRET_KEY` säkert.** Den krypterar IMAP-lösenord och råmail. Förloras den går de inte att
läsa, och byts den måste alla mailboxlösenord matas in på nytt. Den ligger bara i `.env`, aldrig i databasen.

### Första administratören

Antingen via **SSO** (se `OIDC_*` i `.env.example`; medlemmar i `OIDC_ADMIN_GROUP` blir administratörer),
eller lokalt:

```bash
php -r "echo password_hash('DITT-LÖSENORD', PASSWORD_DEFAULT), PHP_EOL;"
mysql -u ... -p ... -e "
  INSERT INTO users (email, name, password_hash) VALUES ('din@mail.se', 'Ditt namn', '<hash från ovan>');
  INSERT INTO users_user_roles (user_id, role_id)
    SELECT LAST_INSERT_ID(), id FROM users_roles WHERE slug = 'administrator';"
```

Fler användare skapas sedan under *Användare* i admin.

## Köra lokalt

```bash
php -S 127.0.0.1:8811 -t public
```

Gå till http://127.0.0.1:8811/admin/login. I produktion kräver `APP_ENV=production` att
`APP_DEBUG=false` och `SESSION_SECURE=true` (appen vägrar annars starta).

## Cron

```
# Hämta nya mail (justera intervallet efter behov)
*/2 * * * * /usr/bin/php8.3 /sökväg/till/mpgw/bin/poll-mailboxes.php >> /sökväg/till/mpgw/var/log/poll.log 2>&1

# Gallring enligt respektive applikations retention-inställningar (en gång per dygn)
0 3 * * * /usr/bin/php8.3 /sökväg/till/mpgw/bin/cleanup-retention.php >> /sökväg/till/mpgw/var/log/retention.log 2>&1
```

## Kommandoradsverktyg (`bin/`)

| Skript | Vad det gör |
|---|---|
| `migrate.php` | Kör alla migreringar i `database/migrations/` (idempotent) |
| `poll-mailboxes.php` | Hämtar nya mail för alla aktiva mailboxar (cron) |
| `cleanup-retention.php` | Raderar gammal data enligt retention-inställningarna (cron) |
| `rebuild-threads.php [applikations-id]` | Räknar om trådar, t.ex. efter ändrade trådinställningar |
| `fetch-records.php` | Fristående exempelklient som hämtar poster från API:t till JSON/CSV |

## Så här bygger du en mall

1. **Applikationer > Ny applikation** - registrera namn + IMAP-uppgifter för avsändarens mailbox.
2. På applikationssidan, fliken **Mallar > Ny mall** - ange från-adress/ämne-villkor för att matcha rätt
   mailtyp (första matchande mall i prioritetsordning används).
3. I mallbyggaren: klistra in ett riktigt exempelmail (eller välj ett redan hämtat mail från listan) och
   tryck **Normalisera & visa**.
4. Markera en textbit i ämnesraden, brödtexten eller mail-headers - du får förslag på regex-mönster
   (generaliserat/literalt), med live-test mot samma mail. Justera mönstret fritt vid behov.
5. **Spara regel.** Upprepa för alla fält du vill extrahera.
6. När cron kör `bin/poll-mailboxes.php` matchas nya mail mot mallen och fälten sparas automatiskt.
   Ändrar du regler senare kan du köra om ett enskilt mail med **Parsa om** på mailets sida.

### Trådar (valfritt)

Under applikationens flik **Trådar**: välj nyckelfält (vad som avgör "samma ärende"), händelsefält och
vilka värden som öppnar respektive stänger. Poster får då `thread_id`, `thread_state` m.fl. i API:t.

## Hämta data via API:t

Varje API-nyckel är knuten till exakt en applikation (skapas under **API-nycklar > Ny nyckel**) och kan
bara läsa data för den. API:t ligger under `/api/v1/` och är fullt dokumenterat i admin under
**Hjälp & API** (endpoints, parametrar, svarsfält, trådar, tidszoner, fel, rate limiting). Ett test
(`ApiDocumentationTest`) ser till att varje route, query-parameter och svarsfält finns i dokumentationen.

Snabbexempel - alla poster med alla fält i ett anrop:

```bash
curl -H "X-API-Key: mpgw_xxx" \
  "https://mpgw.example.com/api/v1/records?include=fields&per_page=200"
```

`bin/fetch-records.php` är en fristående exempelklient (bara cURL, ingen kod delad med applikationen):

```bash
# JSON, inklusive extraherade fält
php bin/fetch-records.php --key=mpgw_xxx --out=records.json --with-fields

# CSV, filtrerat på status
php bin/fetch-records.php --key=mpgw_xxx --out=records.csv --status=success --with-fields

# mot din installation (adressen krävs; kan också sättas i env MPGW_BASE_URL)
php bin/fetch-records.php --key=mpgw_xxx --out=records.json --base-url=https://mpgw.example.com
```

Kör `php bin/fetch-records.php` utan argument för full flagg-lista.

## Tester

```bash
vendor/bin/phpunit tests
```

## Säkerhet

- IMAP-lösenord och råmail krypteras med `sodium_crypto_secretbox` (nyckel: `APP_SECRET_KEY`).
- API-nycklar lagras bara som SHA-256-hash och visas en enda gång vid skapande.
- Alla skrivande admin-anrop kräver CSRF-token och rätt behörighet; inloggning är spärrad efter upprepade fel.
- Rapportera sårbarheter privat till ägaren av repot i stället för att öppna ett publikt ärende.
