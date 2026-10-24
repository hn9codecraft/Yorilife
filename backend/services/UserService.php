<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Utils\HttpException;

/**
 * User management (Sprint 3 foundation): list, view, create, edit name/email,
 * change role, activate/disable. Every rule is enforced here on the server:
 *  - permission per operation (config/permissions.php);
 *  - only accounts/roles ranked below the actor (no privilege escalation);
 *  - nobody changes their own role or status.
 */
final class UserService
{
    private readonly UserRepository $users;
    private readonly RoleRepository $roles;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->roles = new RoleRepository();
    }

    /** @return list<array<string, mixed>> */
    public function list(array $actor): array
    {
        Authorization::authorize($actor, 'users.view');

        return $this->users->all();
    }

    public function get(array $actor, int $id): array
    {
        Authorization::authorize($actor, 'users.view');

        return $this->users->find($id) ?? throw HttpException::notFound('User not found.');
    }

    /** @return list<array<string, mixed>> roles with whether the actor may assign each */
    public function roles(array $actor): array
    {
        Authorization::authorize($actor, 'users.view');

        return array_map(
            static fn (array $role): array => [
                'slug' => $role['slug'],
                'name' => $role['name'],
                'description' => $role['description'],
                'assignable' => Authorization::can($actor, 'users.assign_role') && Authorization::canAssignRole($actor, $role['slug']),
            ],
            $this->roles->all(),
        );
    }

    /** @param array<string, mixed> $input { name, email, role, password } */
    public function create(array $actor, array $input): array
    {
        Authorization::authorize($actor, 'users.create');

        $errors = [];
        UserValidator::onlyFields($input, ['name', 'email', 'role', 'password'], $errors);
        $name = UserValidator::name($input['name'] ?? null, $errors);
        $email = UserValidator::email($input['email'] ?? null, $errors);
        $password = UserValidator::newPassword($input['password'] ?? null, $errors);
        $role = $this->resolveRole($input['role'] ?? null, $errors);

        if ($email !== null && $this->users->emailExists($email)) {
            $errors['email'] = 'A user with this email already exists.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if (!Authorization::canAssignRole($actor, $role['slug'])) {
            throw HttpException::forbidden('You cannot create users with this role.', 'role_not_assignable');
        }

        $id = $this->users->create($name, $email, PasswordHasher::hash($password), (int) $role['id']);

        return $this->users->find($id);
    }

    /** @param array<string, mixed> $input any of { name, email, role, status } */
    public function update(array $actor, int $id, array $input): array
    {
        $target = $this->get($actor, $id);
        $isSelf = (int) $actor['id'] === (int) $target['id'];

        $errors = [];
        UserValidator::onlyFields($input, ['name', 'email', 'role', 'status'], $errors);
        if ($input === []) {
            $errors['_'] = 'Nothing to update.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        // Role and status: never on your own account, and only with the matching permission.
        if (array_key_exists('role', $input)) {
            if ($isSelf) {
                throw HttpException::forbidden('You cannot change your own role.', 'cannot_change_own_role');
            }
            Authorization::authorize($actor, 'users.assign_role');
        }
        if (array_key_exists('status', $input)) {
            if ($isSelf) {
                throw HttpException::forbidden('You cannot change the status of your own account.', 'cannot_change_own_status');
            }
            Authorization::authorize($actor, 'users.change_status');
        }
        if (array_key_exists('name', $input) || array_key_exists('email', $input)) {
            Authorization::authorize($actor, 'users.update');
        }
        if (!$isSelf && !Authorization::canManageUser($actor, $target)) {
            throw HttpException::forbidden('You cannot manage users at this level.', 'cannot_manage_user');
        }

        $fields = [];
        if (array_key_exists('name', $input)) {
            $fields['name'] = UserValidator::name($input['name'], $errors);
        }
        if (array_key_exists('email', $input)) {
            $fields['email'] = UserValidator::email($input['email'], $errors);
            if ($fields['email'] !== null && $this->users->emailExists($fields['email'], $id)) {
                $errors['email'] = 'A user with this email already exists.';
            }
        }
        if (array_key_exists('status', $input)) {
            $fields['status'] = UserValidator::status($input['status'], $errors);
        }
        if (array_key_exists('role', $input)) {
            $role = $this->resolveRole($input['role'], $errors);
            if ($role !== null) {
                if (!Authorization::canAssignRole($actor, $role['slug'])) {
                    throw HttpException::forbidden('You cannot assign this role.', 'role_not_assignable');
                }
                $fields['role_id'] = (int) $role['id'];
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $this->users->update($id, $fields);

        return $this->users->find($id);
    }

    /** @param array<string, string> $errors */
    private function resolveRole(mixed $slug, array &$errors): ?array
    {
        $role = is_string($slug) ? $this->roles->findBySlug($slug) : null;
        if ($role === null || !Authorization::isKnownRole($role['slug'])) {
            $errors['role'] = 'Choose a valid role.';

            return null;
        }

        return $role;
    }
}
