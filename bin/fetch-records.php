#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fristaende API-klient for MPGW:s las-API. Anvander bara cURL mot
 * HTTP-endpointerna (X-API-Key-header) - ingen kod delas med
 * applikationen, sa den fungerar som ett realistiskt exempel pa hur en
 * extern konsument skulle hamta ut data.
 *
 * Paginerar igenom /api/v1/records och skriver resultatet till en fil
 * som JSON (array av record-objekt, inklusive falt) eller CSV
 * (ett falt per kolumn, unionen av alla falt-namn som forekommer).
 *
 * Anvandning:
 *   bin/fetch-records.php --key=mpgw_xxx --out=records.json
 *   bin/fetch-records.php --key=mpgw_xxx --out=records.csv --format=csv
 *   bin/fetch-records.php --key=mpgw_xxx --out=records.json --status=success
 *   bin/fetch-records.php --key=mpgw_xxx --out=records.json --base-url=http://127.0.0.1:8811   # mot lokal dev-server
 *
 * Flaggor:
 *   --key=...          API-nyckel (kravs). Kan ocksa anges via env MPGW_API_KEY.
 *   --out=...           Filsokvag att skriva till (kravs).
 *   --base-url=...      Bas-URL till API:t (default https://mp.larssons.space).
 *   --format=json|csv   Utdataformat (default json).
 *   --status=...         Filtrera pa status (success|partial|failed).
 *   --template-id=...    Filtrera pa mall-id.
 *   --per-page=...        Sidstorlek mot API:t (default 200, max som API:t tillater).
 *   --with-fields          Hamta aven extraherade falt (fields) - skickas som
 *                           include=fields i samma listanrop (inga extra anrop per record).
 *   --include-raw           Hamta aven raw_body/raw_headers per record (kraver
 *                            scope records:read:raw, gor ett extra anrop per record).
 */

final class MpgwApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /** @return array<string,mixed> */
    public function get(string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $this->apiKey, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP-anrop misslyckades ({$url}): {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Ogiltigt JSON-svar fran {$url} (HTTP {$status}): {$body}");
        }

        if ($status >= 400) {
            $message = $decoded['error']['message'] ?? "HTTP {$status}";
            throw new RuntimeException("API-fel mot {$path}: {$message} (HTTP {$status})");
        }

        return $decoded;
    }

    /**
     * Hamtar samtliga sidor av /records for givna filter och returnerar
     * hela listan som en platt array.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchAllRecords(array $filters = [], int $perPage = 200): array
    {
        $all = [];
        $page = 1;

        do {
            $result = $this->get('/api/v1/records', $filters + ['page' => $page, 'per_page' => $perPage]);
            $data = $result['data'] ?? [];
            $all = array_merge($all, $data);

            $meta = $result['meta'] ?? [];
            $total = (int) ($meta['total'] ?? count($all));
            $page++;
        } while (count($all) < $total && $data !== []);

        return $all;
    }

    /** @return array<string,mixed> */
    public function fetchRecord(string $uuid): array
    {
        return $this->get("/api/v1/records/{$uuid}")['data'] ?? [];
    }

    /** @return array<string,mixed> */
    public function fetchRecordRaw(string $uuid): array
    {
        return $this->get("/api/v1/records/{$uuid}/raw")['data'] ?? [];
    }
}

/**
 * @param array<int,string> $argv
 * @return array<string,string>
 */
function parseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $args[$key] = $value;
    }
    return $args;
}

function writeJson(array $records, string $path): void
{
    $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("Kunde inte skriva till {$path}");
    }
}

function writeCsv(array $records, string $path): void
{
    // Kolumner: de fasta falten + unionen av alla dynamiska "fields"-namn
    // som forekommer i datasetet (extraction_rules skiljer sig per mall).
    $fixedColumns = ['id', 'application', 'template', 'status', 'match_rate', 'processed_at'];
    $fieldColumns = [];
    foreach ($records as $record) {
        foreach (array_keys($record['fields'] ?? []) as $fieldName) {
            $fieldColumns[$fieldName] = true;
        }
    }
    $fieldColumns = array_keys($fieldColumns);
    sort($fieldColumns);
    $columns = array_merge($fixedColumns, $fieldColumns);

    $fh = fopen($path, 'w');
    if ($fh === false) {
        throw new RuntimeException("Kunde inte skriva till {$path}");
    }

    fputcsv($fh, $columns);
    foreach ($records as $record) {
        $row = [];
        foreach ($fixedColumns as $col) {
            $row[] = $record[$col] ?? '';
        }
        foreach ($fieldColumns as $col) {
            $row[] = $record['fields'][$col] ?? '';
        }
        fputcsv($fh, $row);
    }
    fclose($fh);
}

// ---------------------------------------------------------------------

$args = parseArgs($argv);

$apiKey = $args['key'] ?? getenv('MPGW_API_KEY') ?: null;
$outPath = $args['out'] ?? null;

if ($apiKey === null || $outPath === null) {
    fwrite(STDERR, "Anvandning: bin/fetch-records.php --key=mpgw_xxx --out=records.json [--format=json|csv] [--base-url=...] [--status=...] [--template-id=...] [--with-fields] [--include-raw]\n");
    exit(1);
}

$baseUrl = $args['base-url'] ?? 'https://mp.larssons.space';
$format = $args['format'] ?? (str_ends_with(strtolower($outPath), '.csv') ? 'csv' : 'json');
$perPage = isset($args['per-page']) ? max(1, min(200, (int) $args['per-page'])) : 200;
$withFields = array_key_exists('with-fields', $args);
$includeRaw = array_key_exists('include-raw', $args);

$filters = [];
if (isset($args['status'])) {
    $filters['status'] = $args['status'];
}
if (isset($args['template-id'])) {
    $filters['template_id'] = $args['template-id'];
}

$client = new MpgwApiClient($baseUrl, $apiKey);

printf('[%s] Hamtar records fran %s...' . PHP_EOL, date('c'), $baseUrl);

try {
    if ($withFields) {
        $filters['include'] = 'fields';
    }
    $records = $client->fetchAllRecords($filters, $perPage);

    // Ratt mail ar medvetet inte med i listan (PII) och kraver ett anrop per record.
    if ($includeRaw) {
        foreach ($records as $i => $record) {
            $records[$i]['raw'] = $client->fetchRecordRaw($record['id']);
        }
    }

    match ($format) {
        'csv' => writeCsv($records, $outPath),
        'json' => writeJson($records, $outPath),
        default => throw new RuntimeException("Okant format: {$format} (anvand json eller csv)"),
    };
} catch (RuntimeException $e) {
    fwrite(STDERR, '[' . date('c') . "] Fel: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}

printf('[%s] Klart: %d record(s) skrivna till %s (%s).' . PHP_EOL, date('c'), count($records), $outPath, $format);
