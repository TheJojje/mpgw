<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\LogEventRepository;
use Mpgw\Infrastructure\Db\Repository\UserRepository;
use Mpgw\Service\AuditDiffer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LogsController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly LogEventRepository $logEvents,
        private readonly AuditDiffer $differ,
        private readonly UserRepository $users,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $module = $query['modul'] ?? null;
        $actorUserId = isset($query['anvandare']) && $query['anvandare'] !== '' ? (int) $query['anvandare'] : null;
        $page = max(1, (int) ($query['sida'] ?? 1));

        $filters = ['module' => $module ?: null, 'actor_user_id' => $actorUserId];

        $events = $this->logEvents->paginate($filters, $page, self::PER_PAGE);
        $total = $this->logEvents->count($filters);

        return $this->view->render($request, $response, 'logs/index.twig', [
            'events' => $events,
            'modules' => $this->logEvents->distinctModules(),
            'users' => $this->users->all(),
            'selected_module' => $module,
            'selected_actor_user_id' => $actorUserId,
            'page' => $page,
            'total_pages' => (int) max(1, ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $event = $this->logEvents->find((int) $args['id']);
        if ($event === null) {
            return $response->withStatus(404);
        }

        $contentParams = [];
        if (!empty($event['content_params'])) {
            $decoded = json_decode((string) $event['content_params'], true);
            $contentParams = is_array($decoded) ? $decoded : [];
        }

        return $this->view->render($request, $response, 'logs/show.twig', [
            'event' => $event,
            'diff' => $this->differ->diffForEvent($event),
            'content_params' => $contentParams,
            'timeline' => (!empty($event['object_type']) && !empty($event['object_id']))
                ? $this->logEvents->recentForObject((string) $event['object_type'], (int) $event['object_id'])
                : [],
        ]);
    }
}
