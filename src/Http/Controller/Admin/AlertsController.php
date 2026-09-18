<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\AlertRepository;
use Mpgw\Service\AuditLogger;
use Mpgw\Support\FlashMessage;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AlertsController
{
    private const ALLOWED_SEVERITIES = ['info', 'warning', 'critical'];
    private const ALLOWED_STATUSES = ['open', 'acknowledged', 'resolved'];

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly PDO $db,
        private readonly AlertRepository $alerts,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $severity = in_array($params['severity'] ?? null, self::ALLOWED_SEVERITIES, true) ? $params['severity'] : null;
        $status = in_array($params['status'] ?? null, self::ALLOWED_STATUSES, true) ? $params['status'] : null;

        $where = [];
        $args = [];
        if ($severity !== null) {
            $where[] = 'a.severity = ?';
            $args[] = $severity;
        }
        if ($status !== null) {
            $where[] = 'a.status = ?';
            $args[] = $status;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare(
            'SELECT a.*, app.name AS application_name
             FROM alerts a
             LEFT JOIN applications app ON app.id = a.application_id'
            . $whereSql .
            ' ORDER BY a.created_at DESC LIMIT 100'
        );
        $stmt->execute($args);

        return $this->view->render($request, $response, 'alerts/index.twig', [
            'alerts' => $stmt->fetchAll(),
            'severity_filter' => $severity,
            'status_filter' => $status,
        ]);
    }

    public function acknowledge(Request $request, Response $response, array $args): Response
    {
        $alertId = (int) $args['id'];
        $alert = $this->alerts->find($alertId);
        if ($alert === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->alerts->acknowledge($alertId);

        $this->audit->log('alerts', 'acknowledged', $this->currentUserId(), 'alert', $alertId, 'Larm kvitterades', [
            'type' => $alert['type'],
            'severity' => $alert['severity'],
        ]);

        FlashMessage::success('Larmet kvitterades.');

        return $response->withHeader('Location', '/admin/alerts')->withStatus(302);
    }

    public function resolve(Request $request, Response $response, array $args): Response
    {
        $alertId = (int) $args['id'];
        $alert = $this->alerts->find($alertId);
        if ($alert === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->alerts->resolve($alertId);

        $this->audit->log('alerts', 'resolved', $this->currentUserId(), 'alert', $alertId, 'Larm löstes', [
            'type' => $alert['type'],
            'severity' => $alert['severity'],
        ]);

        FlashMessage::success('Larmet löstes.');

        return $response->withHeader('Location', '/admin/alerts')->withStatus(302);
    }

    private function currentUserId(): ?int
    {
        return !empty($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
    }
}
