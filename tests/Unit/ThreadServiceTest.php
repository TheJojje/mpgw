<?php

declare(strict_types=1);

namespace Mpgw\Tests\Unit;

use Mpgw\Service\ThreadService;
use PHPUnit\Framework\TestCase;

final class ThreadServiceTest extends TestCase
{
    private function events(string ...$events): array
    {
        $out = [];
        foreach ($events as $i => $e) {
            $out[] = ['id' => $i + 1, 'event' => $e];
        }
        return $out;
    }

    public function testOpenThenCloseIsOneClosedThread(): void
    {
        $r = ThreadService::assign($this->events('open', 'close'));
        $this->assertSame([1, 1, 'closed', null], array_values($r[1]));
        $this->assertSame([1, 2, 'closed', null], array_values($r[2]));
    }

    public function testRebookingAfterCloseStartsNewThread(): void
    {
        $r = ThreadService::assign($this->events('open', 'close', 'open', 'close'));
        $this->assertSame(1, $r[1]['thread_no']);
        $this->assertSame(1, $r[2]['thread_no']);
        $this->assertSame(2, $r[3]['thread_no']);
        $this->assertSame(2, $r[4]['thread_no']);
        $this->assertSame('closed', $r[3]['state']);
        $this->assertNull($r[4]['anomaly']);
    }

    public function testRebookingStaysOpenWhenSecondCancellationMissing(): void
    {
        $r = ThreadService::assign($this->events('open', 'close', 'open'));
        $this->assertSame('closed', $r[1]['state']);
        $this->assertSame('open', $r[3]['state']);
    }

    public function testCloseWithoutOpenIsAnomaly(): void
    {
        $r = ThreadService::assign($this->events('close'));
        $this->assertSame(ThreadService::ANOMALY_CLOSE_WITHOUT_OPEN, $r[1]['anomaly']);
        $this->assertSame('closed', $r[1]['state']);
    }

    public function testDuplicateCloseAndOpenAreAnomaliesInSameThread(): void
    {
        $r = ThreadService::assign($this->events('open', 'open', 'close', 'close'));
        $this->assertSame(ThreadService::ANOMALY_DUPLICATE_OPEN, $r[2]['anomaly']);
        $this->assertSame(ThreadService::ANOMALY_DUPLICATE_CLOSE, $r[4]['anomaly']);
        $this->assertSame(1, $r[4]['thread_no']);
        $this->assertSame(4, $r[4]['sequence']);
    }

    public function testResolveBuildsCaseInsensitiveKeyAndEvent(): void
    {
        $app = [
            'id' => 2,
            'thread_key_fields' => 'guest_name, apartment_number',
            'thread_event_field' => 'event_type',
            'thread_open_values' => 'Bokning',
            'thread_close_values' => 'Avbokning',
        ];
        $a = ThreadService::resolve($app, ['guest_name' => 'Anna  Andersson', 'apartment_number' => '101', 'event_type' => 'bokning']);
        $b = ThreadService::resolve($app, ['guest_name' => 'anna andersson', 'apartment_number' => '101', 'event_type' => 'Avbokning']);
        $this->assertSame('open', $a['event']);
        $this->assertSame('close', $b['event']);
        $this->assertSame($a['key'], $b['key']);
    }

    public function testResolveReturnsNullWhenUnconfiguredOrFieldMissing(): void
    {
        $this->assertNull(ThreadService::resolve(['id' => 1], ['a' => 'x']));
        $app = ['id' => 1, 'thread_key_fields' => 'a', 'thread_event_field' => 'e', 'thread_open_values' => 'o', 'thread_close_values' => 'c'];
        $this->assertNull(ThreadService::resolve($app, ['e' => 'o']));
        $this->assertNull(ThreadService::resolve($app, ['a' => 'x', 'e' => 'okänd']));
    }
}
