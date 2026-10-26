<?php

declare(strict_types=1);

namespace Mpgw\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Skyddar mot att API:t vaxer ifran dokumentationen: varje API-route i
 * config/routes.php och varje query-parameter RecordsController laser
 * maste vara beskriven i hjalpsidan (templates/admin/help/index.twig).
 */
final class ApiDocumentationTest extends TestCase
{
    private string $root;
    private string $help;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->help = (string) file_get_contents($this->root . '/templates/admin/help/index.twig');
    }

    public function testEveryApiRouteIsDocumented(): void
    {
        $routes = (string) file_get_contents($this->root . '/config/routes.php');
        $apiPart = substr($routes, (int) strpos($routes, 'Externt API'));

        preg_match_all("/->get\('(\/[^']*)'/", $apiPart, $m);
        $paths = array_unique($m[1]);
        $this->assertNotEmpty($paths, 'Hittade inga API-routes att kontrollera.');

        foreach ($paths as $path) {
            $full = str_starts_with($path, '/api/') ? $path : '/api/v1' . $path;
            $this->assertStringContainsString('GET ' . $full, $this->help, "API-routen GET {$full} saknas i hjälpsidan.");
        }
    }

    public function testEveryRecordsQueryParameterIsDocumented(): void
    {
        $source = (string) file_get_contents($this->root . '/src/Http/Controller/Api/RecordsController.php');
        preg_match_all("/\\\$params\['(\w+)'\]/", $source, $m);
        $params = array_unique($m[1]);
        $this->assertNotEmpty($params);

        foreach ($params as $param) {
            $this->assertStringContainsString('<code>' . $param, $this->help, "Query-parametern {$param} saknas i hjälpsidan.");
        }
    }

    public function testEveryRecordResponseKeyIsDocumented(): void
    {
        $source = (string) file_get_contents($this->root . '/src/Http/Controller/Api/RecordsController.php');
        $formatStart = (int) strpos($source, 'private function formatRecord');
        preg_match_all("/'(\w+)' =>/", substr($source, $formatStart), $m);

        foreach (array_unique($m[1]) as $key) {
            $this->assertStringContainsString('<code>' . $key . '</code>', $this->help, "Svarsfältet {$key} saknas i hjälpsidan.");
        }
    }

    public function testAlertTypesAreDocumented(): void
    {
        $migration = (string) file_get_contents($this->root . '/database/migrations/011_threads.sql');
        preg_match("/ENUM\(([^)]*)\)\s+NOT NULL;/", $migration, $m);
        preg_match_all("/'(\w+)'/", $m[1] ?? '', $types);
        $this->assertNotEmpty($types[1]);

        foreach ($types[1] as $type) {
            $this->assertStringContainsString('<code>' . $type . '</code>', $this->help, "Larmtypen {$type} saknas i hjälpsidan.");
        }
    }
}
