<?php
namespace App\Controllers;
use App\Core\Permissions;
use App\Repositories\UserManagementRepository;
use App\Services\AuditService;

class UserController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_users');
        $search = $_GET['search'] ?? null;
        echo json_encode((new UserManagementRepository())->findAllByTenant($claims['tenant_id'], $search));
    }

    public function store(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_users');
        $data = $this->getJsonBody();

        foreach (['email', 'password', 'role'] as $field) {
            if (empty($data[$field])) {
                http_response_code(422);
                echo json_encode(['error' => "Field '$field' is required"]);
                return;
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['error' => 'INVALID_EMAIL']);
            return;
        }

        if (strlen($data['password']) < 8) {
            http_response_code(422);
            echo json_encode(['error' => 'PASSWORD_TOO_SHORT']);
            return;
        }

        if (!in_array($data['role'], Permissions::ROLES, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'INVALID_ROLE']);
            return;
        }

        $repo = new UserManagementRepository();

        if ($repo->emailExists($data['email'])) {
            http_response_code(409);
            echo json_encode(['error' => 'EMAIL_TAKEN']);
            return;
        }

        $user = $repo->create($claims['tenant_id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'create_user', 'user', $user['id'], ['email' => $data['email'], 'role' => $data['role']]);

        http_response_code(201);
        echo json_encode($user);
    }

    public function update(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_users');
        $data = $this->getJsonBody();

        if (empty($data['id']) || empty($data['email']) || empty($data['role'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id, email and role are required']);
            return;
        }

        if (!in_array($data['role'], Permissions::ROLES, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'INVALID_ROLE']);
            return;
        }

        $repo = new UserManagementRepository();
        $user = $repo->findById($claims['tenant_id'], $data['id']);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        if ($repo->emailExists($data['email'], $data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'EMAIL_TAKEN']);
            return;
        }

        // Never let the last active admin lose admin rights or be disabled —
        // that would lock everyone out of user management permanently.
        $losingAdmin = $user['role'] === 'admin' &&
            ($data['role'] !== 'admin' || !($data['is_active'] ?? true));

        if ($losingAdmin && $repo->countAdmins($claims['tenant_id'], $data['id']) === 0) {
            http_response_code(409);
            echo json_encode(['error' => 'LAST_ADMIN']);
            return;
        }

        $repo->update($claims['tenant_id'], $data['id'], $data);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'update_user', 'user', $data['id'], ['email' => $data['email'], 'role' => $data['role']]);

        echo json_encode(['status' => 'updated']);
    }

    public function resetPassword(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_users');
        $data = $this->getJsonBody();

        if (empty($data['id']) || empty($data['password'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id and password are required']);
            return;
        }

        if (strlen($data['password']) < 8) {
            http_response_code(422);
            echo json_encode(['error' => 'PASSWORD_TOO_SHORT']);
            return;
        }

        $repo = new UserManagementRepository();
        if (!$repo->findById($claims['tenant_id'], $data['id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        $repo->changePassword($claims['tenant_id'], $data['id'], $data['password']);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'reset_password', 'user', $data['id']);

        echo json_encode(['status' => 'password_reset']);
    }

    public function destroy(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('manage_users');
        $data = $this->getJsonBody();

        if (empty($data['id'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id is required']);
            return;
        }

        if ($data['id'] === $claims['user_id']) {
            http_response_code(409);
            echo json_encode(['error' => 'CANNOT_DELETE_SELF']);
            return;
        }

        $repo = new UserManagementRepository();
        $user = $repo->findById($claims['tenant_id'], $data['id']);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        if ($user['role'] === 'admin' && $repo->countAdmins($claims['tenant_id'], $data['id']) === 0) {
            http_response_code(409);
            echo json_encode(['error' => 'LAST_ADMIN']);
            return;
        }

        // A user who has acted in the system is kept for the audit trail.
        if ($repo->hasActivity($data['id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'USER_HAS_ACTIVITY']);
            return;
        }

        $repo->delete($claims['tenant_id'], $data['id']);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'delete_user', 'user', $data['id'], ['email' => $user['email']]);

        echo json_encode(['status' => 'deleted']);
    }

    // ---------- The logged-in user's own profile ----------

    public function profile(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();

        $user = (new UserManagementRepository())->findById($claims['tenant_id'], $claims['user_id']);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        echo json_encode([
            'user' => $user,
            'permissions' => Permissions::forRole($user['role']),
        ]);
    }

    public function updateProfile(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        $repo = new UserManagementRepository();
        $user = $repo->findById($claims['tenant_id'], $claims['user_id']);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        $email = $data['email'] ?? $user['email'];

        if ($repo->emailExists($email, $claims['user_id'])) {
            http_response_code(409);
            echo json_encode(['error' => 'EMAIL_TAKEN']);
            return;
        }

        // A user editing their own profile cannot change their own role
        // or reactivate themselves — that stays an admin action.
        $repo->update($claims['tenant_id'], $claims['user_id'], [
            'email' => $email,
            'full_name' => $data['full_name'] ?? $user['full_name'],
            'phone' => $data['phone'] ?? $user['phone'],
            'role' => $user['role'],
            'is_active' => $user['is_active'],
        ]);

        AuditService::log($claims['tenant_id'], $claims['user_id'], 'update_profile', 'user', $claims['user_id']);
        echo json_encode(['status' => 'updated']);
    }

    public function changeOwnPassword(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['current_password']) || empty($data['new_password'])) {
            http_response_code(422);
            echo json_encode(['error' => 'current_password and new_password are required']);
            return;
        }

        if (strlen($data['new_password']) < 8) {
            http_response_code(422);
            echo json_encode(['error' => 'PASSWORD_TOO_SHORT']);
            return;
        }

        $repo = new UserManagementRepository();

        if (!$repo->verifyPassword($claims['user_id'], $data['current_password'])) {
            http_response_code(401);
            echo json_encode(['error' => 'WRONG_PASSWORD']);
            return;
        }

        $repo->changePassword($claims['tenant_id'], $claims['user_id'], $data['new_password']);
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'change_password', 'user', $claims['user_id']);

        echo json_encode(['status' => 'password_changed']);
    }
}