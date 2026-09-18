<?php

declare(strict_types=1);

namespace Mpgw\Service;

use Mpgw\Infrastructure\Db\Repository\LogEventRepository;

/**
 * Raknar ut en falt-for-falt-diff for en log_events-rad genom att jamfora
 * dess content_params (JSON) med narmast foregaende log_events-rad for
 * samma (object_type, object_id) - inte via nagra separata old/new-
 * kolumner. Se LogsController::show.
 */
final class AuditDiffer
{
    public function __construct(private readonly LogEventRepository $logEvents)
    {
    }

    /**
     * @param array<string,mixed> $event en rad fran log_events (som array)
     * @return array<string,array{old:mixed,new:mixed}> falt som skiljer sig
     */
    public function diffForEvent(array $event): array
    {
        if (empty($event['object_type']) || empty($event['object_id'])) {
            return [];
        }

        $previous = $this->logEvents->previousForObject(
            (string) $event['object_type'],
            (int) $event['object_id'],
            (string) $event['created_at'],
            (int) $event['id'],
        );

        $newParams = $this->decodeParams($event['content_params'] ?? null);
        $oldParams = $previous !== null ? $this->decodeParams($previous['content_params'] ?? null) : [];

        $diff = [];
        $keys = array_unique([...array_keys($oldParams), ...array_keys($newParams)]);
        foreach ($keys as $key) {
            $old = $oldParams[$key] ?? null;
            $new = $newParams[$key] ?? null;
            if ($old !== $new) {
                $diff[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return $diff;
    }

    /** @return array<string,mixed> */
    private function decodeParams(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
