<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class RoleRepository
{
    /** @return list<array{id: int, slug: string, name: string, description: ?string}> */
    public function all(): array
    {
        return Database::connection()->query('SELECT id, slug, name, description FROM roles ORDER BY id')->fetchAll();
    }

    /** @return array{id: int, slug: string, name: string, description: ?string}|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, slug, name, description FROM roles WHERE slug = ?');
        $stmt->execute([$slug]);

        return $stmt->fetch() ?: null;
    }
}
