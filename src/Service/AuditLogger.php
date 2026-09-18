<?php

declare(strict_types=1);

namespace Mpgw\Service;

use Mpgw\Infrastructure\Db\Repository\LogEventRepository;

/**
 * Skriver andringshandelser till log_events. Sparar bara det NYA
 * tillstandet som JSON (content_params) - se AuditDiffer for hur
 * fore/efter-diff beraknas vid visning istallet for att lagras separat.
 */
final class AuditLogger
{
    public function __construct(private readonly LogEventRepository $logEvents)
    {
    }

    /** @param array<string,mixed> $contentParams */
    public function log(
        string $module,
        string $action,
        ?int $actorUserId,
        ?string $objectType,
        ?int $objectId,
        string $content,
        array $contentParams = [],
        string $level = 'info',
        string $source = 'ui',
    ): void {
        $this->logEvents->insert($module, $action, $level, $source, $actorUserId, $objectType, $objectId, $content, $contentParams);
    }
}
