<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\RoleRepository;
use Mpgw\Infrastructure\Db\Repository\UserRepository;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\AuthorizationService;
use Mpgw\Support\FlashMessage;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UsersController
{
    public function __construct(
        private readonly ViewRenderer $view,
        private readonly PDO $db,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly AuditLogger $audit,
        private readonly AuthorizationService $authz,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $showAll = ($request->getQueryParams()['visa'] ?? null) === 'alla';

        $stmt = $this->db->query(
            'SELECT u.* FROM users u ' . ($showAll ? '' : 'WHERE u.is_active = 1') . ' ORDER BY u.name'
        );
        $users = $stmt->fetchAll();
        foreach ($users as &$user) {
            $user['roles'] = $this->users->rolesForUser((int) $user['id']);
        }
        unset($user);

        return $this->view->render($request, $response, 'users/index.twig', [
            'users' => $users,
            'show_all' => $showAll,
        ]);
    }

    public function newForm(Request $request, Response $response): Response
    {
        return $this->view->render($request, $response, 'users/new.twig', [
            'roles' => $this->roles->allActive(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $roleIds = array_map('intval', (array) ($data['role_ids'] ?? []));

        $error = $this->validate($name, $email, $password, requirePassword: true);
        if ($error !== null) {
            return $this->view->render($request, $response->withStatus(422), 'users/new.twig', [
                'roles' => $this->roles->allActive(),
                'error' => $error,
                'old' => $data,
            ]);
        }

        $userId = $this->users->create($name, $email, password_hash($password, PASSWORD_DEFAULT));
        $this->users->setRoles($userId, $roleIds);

        $this->audit->log(
            'users',
            'created',
            $this->currentUserId(),
            'user',
            $userId,
            "Användaren {$name} skapades",
            ['name' => $name, 'email' => $email, 'role_ids' => $roleIds, 'is_active' => true],
        );

        FlashMessage::success("Användaren {$name} skapades.");

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $user = $this->users->find($userId);
        if ($user === null) {
            return $response->withStatus(404);
        }

        return $this->view->render($request, $response, 'users/edit.twig', [
            'user' => $user,
            'roles' => $this->roles->allActive(),
            'user_role_ids' => $this->users->roleIdsForUser($userId),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $user = $this->users->find($userId);
        if ($user === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $roleIds = array_map('intval', (array) ($data['role_ids'] ?? []));

        $error = $this->validate($name, $email, $password, requirePassword: false, excludeId: $userId);
        if ($error !== null) {
            return $this->view->render($request, $response->withStatus(422), 'users/edit.twig', [
                'user' => $user,
                'roles' => $this->roles->allActive(),
                'user_role_ids' => $roleIds,
                'error' => $error,
            ]);
        }

        $this->users->update($userId, $name, $email);
        if ($password !== '') {
            $this->users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
        }
        $this->users->setRoles($userId, $roleIds);

        // Om en admin redigerar sitt EGET konto ska ny roll/rattighet galla
        // direkt i den har sessionen, utan att behova logga in igen.
        if ($userId === $this->currentUserId()) {
            $this->authz->refreshCurrentSession();
        }

        $this->audit->log(
            'users',
            'updated',
            $this->currentUserId(),
            'user',
            $userId,
            "Användaren {$name} uppdaterades",
            ['name' => $name, 'email' => $email, 'role_ids' => $roleIds, 'is_active' => (bool) $user['is_active']],
        );

        FlashMessage::success("Användaren {$name} uppdaterades.");

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $user = $this->users->find($userId);
        if ($user === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        if ($userId === $this->currentUserId()) {
            return $this->view->render($request, $response->withStatus(422), 'users/index.twig', [
                'users' => $this->usersWithRoles(),
                'show_all' => false,
                'error' => 'Du kan inte inaktivera ditt eget konto.',
            ]);
        }

        if ($this->wouldRemoveLastAdministrator($userId)) {
            return $this->view->render($request, $response->withStatus(422), 'users/index.twig', [
                'users' => $this->usersWithRoles(),
                'show_all' => false,
                'error' => 'Går inte att inaktivera - det är den sista aktiva administratören.',
            ]);
        }

        $this->users->deactivate($userId);

        $this->audit->log(
            'users',
            'deactivated',
            $this->currentUserId(),
            'user',
            $userId,
            "Användaren {$user['name']} inaktiverades",
            ['name' => $user['name'], 'email' => $user['email'], 'is_active' => false],
        );

        FlashMessage::success("Användaren {$user['name']} togs bort.");

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    public function reactivate(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $user = $this->users->find($userId);
        if ($user === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $this->users->reactivate($userId);

        $this->audit->log(
            'users',
            'reactivated',
            $this->currentUserId(),
            'user',
            $userId,
            "Användaren {$user['name']} återaktiverades",
            ['name' => $user['name'], 'email' => $user['email'], 'is_active' => true],
        );

        FlashMessage::success("Användaren {$user['name']} återaktiverades.");

        return $response->withHeader('Location', '/admin/users?visa=alla')->withStatus(302);
    }

    private function validate(string $name, string $email, string $password, bool $requirePassword, ?int $excludeId = null): ?string
    {
        if ($name === '') {
            return 'Namn krävs.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Giltig e-postadress krävs.';
        }
        if ($this->users->emailExists($email, $excludeId)) {
            return 'E-postadressen används redan av en annan användare.';
        }
        if ($requirePassword && strlen($password) < 8) {
            return 'Lösenord krävs (minst 8 tecken).';
        }
        if ($password !== '' && strlen($password) < 8) {
            return 'Lösenordet måste vara minst 8 tecken.';
        }
        return null;
    }

    private function wouldRemoveLastAdministrator(int $userId): bool
    {
        $administratorRole = $this->roles->findBySlug('administrator');
        if ($administratorRole === null) {
            return false;
        }

        $roleIds = $this->users->roleIdsForUser($userId);
        if (!in_array((int) $administratorRole['id'], $roleIds, true)) {
            return false;
        }

        return $this->users->countActiveByRoleSlug('administrator') <= 1;
    }

    /** @return array<int,array<string,mixed>> */
    private function usersWithRoles(): array
    {
        $users = $this->users->allActive();
        foreach ($users as &$user) {
            $user['roles'] = $this->users->rolesForUser((int) $user['id']);
        }
        unset($user);
        return $users;
    }

    private function currentUserId(): ?int
    {
        return !empty($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
    }
}
