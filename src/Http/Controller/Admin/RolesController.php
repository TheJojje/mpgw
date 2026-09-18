<?php

declare(strict_types=1);

namespace Mpgw\Http\Controller\Admin;

use Mpgw\Http\Middleware\CsrfMiddleware;
use Mpgw\Http\View\ViewRenderer;
use Mpgw\Infrastructure\Db\Repository\PermissionRepository;
use Mpgw\Infrastructure\Db\Repository\RoleRepository;
use Mpgw\Infrastructure\Db\Repository\UserRepository;
use Mpgw\Service\AuditLogger;
use Mpgw\Service\AuthorizationService;
use Mpgw\Support\FlashMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class RolesController
{
    /** Rattigheter Administratör-rollen aldrig far forlora - annars kan alla admins lasas ute. */
    private const PROTECTED_ADMIN_PERMISSIONS = ['roles.manage', 'users.manage'];

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly RoleRepository $roles,
        private readonly PermissionRepository $permissions,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        private readonly AuthorizationService $authz,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($request, $response, 'roles/index.twig', [
            'roles' => $this->roles->allWithPermissionCount(),
        ]);
    }

    public function newForm(Request $request, Response $response): Response
    {
        return $this->view->render($request, $response, 'roles/new.twig', [
            'permission_groups' => $this->permissions->allGroupedByModule(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? '')) ?: null;
        $permissionIds = array_map('intval', (array) ($data['permission_ids'] ?? []));
        $slug = $this->slugify($name);

        if ($name === '') {
            return $this->view->render($request, $response->withStatus(422), 'roles/new.twig', [
                'permission_groups' => $this->permissions->allGroupedByModule(),
                'error' => 'Namn krävs.',
            ]);
        }
        if ($slug === '' || $this->roles->slugExists($slug)) {
            return $this->view->render($request, $response->withStatus(422), 'roles/new.twig', [
                'permission_groups' => $this->permissions->allGroupedByModule(),
                'error' => 'En roll med det namnet finns redan.',
            ]);
        }

        $roleId = $this->roles->create($name, $slug, $description);
        $this->roles->setPermissions($roleId, $permissionIds);

        $this->audit->log(
            'roles',
            'created',
            $this->currentUserId(),
            'role',
            $roleId,
            "Rollen {$name} skapades",
            ['name' => $name, 'permissions' => $this->roles->permissionSlugsForRole($roleId)],
        );

        FlashMessage::success("Rollen {$name} skapades.");

        return $response->withHeader('Location', '/admin/roles')->withStatus(302);
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $roleId = (int) $args['id'];
        $role = $this->roles->find($roleId);
        if ($role === null) {
            return $response->withStatus(404);
        }

        return $this->view->render($request, $response, 'roles/edit.twig', [
            'role' => $role,
            'permission_groups' => $this->permissions->allGroupedByModule(),
            'role_permission_ids' => $this->roles->permissionIdsForRole($roleId),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $roleId = (int) $args['id'];
        $role = $this->roles->find($roleId);
        if ($role === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? '')) ?: null;
        $permissionIds = array_map('intval', (array) ($data['permission_ids'] ?? []));

        if ($name === '') {
            return $this->view->render($request, $response->withStatus(422), 'roles/edit.twig', [
                'role' => $role,
                'permission_groups' => $this->permissions->allGroupedByModule(),
                'role_permission_ids' => $permissionIds,
                'error' => 'Namn krävs.',
            ]);
        }

        if ($role['slug'] === 'administrator' && $this->wouldLoseProtectedPermission($permissionIds)) {
            return $this->view->render($request, $response->withStatus(422), 'roles/edit.twig', [
                'role' => $role,
                'permission_groups' => $this->permissions->allGroupedByModule(),
                'role_permission_ids' => $this->roles->permissionIdsForRole($roleId),
                'error' => 'Administratör-rollen måste alltid ha rättigheterna "roles.manage" och "users.manage" - annars kan alla administratörer låsas ute.',
            ]);
        }

        $this->roles->update($roleId, $name, $description);
        $this->roles->setPermissions($roleId, $permissionIds);
        $this->authz->refreshCurrentSession();

        $this->audit->log(
            'roles',
            'updated',
            $this->currentUserId(),
            'role',
            $roleId,
            "Rollen {$name} uppdaterades",
            ['name' => $name, 'permissions' => $this->roles->permissionSlugsForRole($roleId)],
        );

        FlashMessage::success("Rollen {$name} uppdaterades.");

        return $response->withHeader('Location', '/admin/roles')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $roleId = (int) $args['id'];
        $role = $this->roles->find($roleId);
        if ($role === null) {
            return $response->withStatus(404);
        }

        $data = (array) $request->getParsedBody();
        if (!CsrfMiddleware::verify($data['csrf_token'] ?? null)) {
            return $response->withStatus(400);
        }

        if ((bool) $role['is_system']) {
            return $this->view->render($request, $response->withStatus(422), 'roles/index.twig', [
                'roles' => $this->roles->allWithPermissionCount(),
                'error' => 'Systemrollen "' . $role['name'] . '" kan inte raderas.',
            ]);
        }

        $this->roles->delete($roleId);

        $this->audit->log(
            'roles',
            'deleted',
            $this->currentUserId(),
            'role',
            $roleId,
            "Rollen {$role['name']} raderades",
            ['name' => $role['name']],
        );

        FlashMessage::success("Rollen {$role['name']} togs bort.");

        return $response->withHeader('Location', '/admin/roles')->withStatus(302);
    }

    /** @param array<int,int> $newPermissionIds */
    private function wouldLoseProtectedPermission(array $newPermissionIds): bool
    {
        $allPermissions = $this->permissions->all();
        $idsBySlug = array_column($allPermissions, 'id', 'slug');

        foreach (self::PROTECTED_ADMIN_PERMISSIONS as $slug) {
            if (isset($idsBySlug[$slug]) && !in_array((int) $idsBySlug[$slug], $newPermissionIds, true)) {
                return true;
            }
        }
        return false;
    }

    private function currentUserId(): ?int
    {
        return !empty($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = (string) preg_replace('/[åä]/u', 'a', $slug);
        $slug = (string) preg_replace('/[ö]/u', 'o', $slug);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}
