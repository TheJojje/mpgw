<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\AlertRepository;
use Mpgw\Service\AlertPresenter;
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

    private const TYPE_GROUPS = [
        'partial' => ['label' => 'Delvis matchning', 'types' => ['low_match_rate', 'rule_zero_hits']],
        'threads' => ['label' => 'Trådar', 'types' => ['thread_anomaly']],
        'mailbox' => ['label' => 'Brevlåda', 'types' => ['imap_connection_failed', 'no_template_match']],
    ];

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $status = in_array($params['status'] ?? null, self::ALLOWED_STATUSES, true) ? $params['status'] : 'open';
        if (($params['status'] ?? null) === 'all') {
            $status = null;
        }
        $group = isset(self::TYPE_GROUPS[$params['type'] ?? '']) ? $params['type'] : null;

        // Antal per status for segmentknapparna (respekterar typfiltret).
        $typeSql = '';
        $typeArgs = [];
        if ($group !== null) {
            $types = self::TYPE_GROUPS[$group]['types'];
            $typeSql = ' AND a.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            $typeArgs = $types;
        }
        $counts = ['open' => 0, 'acknowledged' => 0, 'resolved' => 0];
        $countStmt = $this->db->prepare('SELECT a.status, COUNT(*) FROM alerts a WHERE 1=1' . $typeSql . ' GROUP BY a.status');
        $countStmt->execute($typeArgs);
        foreach ($countStmt->fetchAll(PDO::FETCH_KEY_PAIR) as $s => $n) {
            $counts[$s] = (int) $n;
        }

        $where = 'WHERE 1=1' . $typeSql;
        $args = $typeArgs;
        if ($status !== null) {
            $where .= ' AND a.status = ?';
            $args[] = $status;
        }

        $stmt = $this->db->prepare(
            'SELECT a.*, app.name AS application_name,
                    re.uuid AS raw_email_uuid, re.subject AS raw_email_subject, t.name AS template_name
             FROM alerts a
             LEFT JOIN applications app ON app.id = a.application_id
             LEFT JOIN raw_emails re ON re.id = a.raw_email_id
             LEFT JOIN templates t ON t.id = a.template_id '
            . $where .
            ' ORDER BY a.created_at DESC LIMIT 200'
        );
        $stmt->execute($args);
        $alerts = array_map(static fn (array $a): array => $a + AlertPresenter::describe($a), $stmt->fetchAll());

        return $this->view->render($request, $response, 'alerts/index.twig', [
            'alerts' => $alerts,
            'status_filter' => $status ?? 'all',
            'type_filter' => $group,
            'type_groups' => self::TYPE_GROUPS,
            'counts' => $counts + ['all' => array_sum($counts)],
        ]);
    }

    /** Kvittera flera larm samtidigt (ids[] fran listan). */
    public function acknowledgeMany(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $done = 0;
        foreach ((array) ($data['ids'] ?? []) as $id) {
            $alertId = (int) $id;
            if ($alertId > 0 && $this->alerts->acknowledge($alertId)) {
                $done++;
                $this->audit->log('alerts', 'acknowledged', $this->currentUserId(), 'alert', $alertId, 'Larm kvitterades', []);
            }
        }

        if ($done > 0) {
            FlashMessage::success($done === 1 ? 'Larmet kvitterades.' : "{$done} larm kvitterades.");
        }

        return $response->withHeader('Location', '/admin/alerts')->withStatus(302);
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
