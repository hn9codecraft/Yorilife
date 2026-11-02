<?php

declare(strict_types=1);

namespace App\Repositories;

/** Headquarters, managers and stockists. */
final class SalesOfficeRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function headquarters(): array
    {
        return $this->many('SELECT id, name, state, location_id, location_headquarters_id, address, pincode, phone, email, code, parent_manager_id, created_at, updated_at FROM headquarters ORDER BY name, id');
    }

    /** @return array<string, mixed>|null */
    public function findHeadquarters(int $id): ?array
    {
        return $this->one('SELECT id, name, state, location_id, location_headquarters_id, address, pincode, phone, email, code, parent_manager_id, created_at, updated_at FROM headquarters WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function managers(): array
    {
        return $this->many(
            'SELECT m.id, m.level, m.name, m.code, m.email, m.phone, m.parent_headquarters_id, m.parent_manager_id,
                    p.level AS parent_level, m.created_at, m.updated_at
             FROM managers m
             LEFT JOIN managers p ON p.id = m.parent_manager_id
             ORDER BY m.level, m.name, m.id'
        );
    }

    /** @return array<string, mixed>|null */
    public function findManager(int $id): ?array
    {
        return $this->one(
            'SELECT m.id, m.level, m.name, m.code, m.email, m.phone, m.parent_headquarters_id, m.parent_manager_id,
                    p.level AS parent_level, m.created_at, m.updated_at
             FROM managers m
             LEFT JOIN managers p ON p.id = m.parent_manager_id
             WHERE m.id = ?',
            [$id]
        );
    }

    /** @return list<array<string, mixed>> */
    public function stockists(): array
    {
        return $this->many('SELECT id, name, code, address, pincode, phone, email, headquarters_id, created_at, updated_at FROM stockists ORDER BY name, id');
    }

    /** @return array<string, mixed>|null */
    public function findStockist(int $id): ?array
    {
        return $this->one('SELECT id, name, code, address, pincode, phone, email, headquarters_id, created_at, updated_at FROM stockists WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findHeadquartersByCode(string $code): ?array
    {
        return $this->one('SELECT id, name, state, location_id, location_headquarters_id, address, pincode, phone, email, code, parent_manager_id, created_at, updated_at FROM headquarters WHERE code = ?', [$code]);
    }

    /** @return array<string, mixed>|null */
    public function findManagerByCode(string $code): ?array
    {
        return $this->one(
            'SELECT m.id, m.level, m.name, m.code, m.email, m.phone, m.parent_headquarters_id, m.parent_manager_id,
                    p.level AS parent_level, m.created_at, m.updated_at
             FROM managers m
             LEFT JOIN managers p ON p.id = m.parent_manager_id
             WHERE m.code = ?',
            [$code]
        );
    }

    /** @return array<string, mixed>|null */
    public function findStockistByCode(string $code): ?array
    {
        return $this->one('SELECT id, name, code, address, pincode, phone, email, headquarters_id, created_at, updated_at FROM stockists WHERE code = ?', [$code]);
    }

    /**
     * Removes demo managers whose codes are not in the keep list.
     * Only rows with a DEMO-M code are deleted, children before parents.
     *
     * @param list<string> $keepCodes
     */
    public function deleteDemoManagersExcept(array $keepCodes): void
    {
        $marks = implode(', ', array_fill(0, count($keepCodes), '?'));
        $this->pdo()->prepare(
            "DELETE ms FROM manager_states ms
             INNER JOIN managers m ON m.id = ms.manager_id
             WHERE m.code LIKE 'DEMO-M%' AND m.code NOT IN ({$marks})"
        )->execute($keepCodes);
        $sql = "DELETE FROM managers WHERE code LIKE 'DEMO-M%' AND code NOT IN ({$marks}) AND level = ?";
        $statement = $this->pdo()->prepare($sql);
        foreach ([1, 2, 3] as $level) {
            $statement->execute([...$keepCodes, $level]);
        }
    }

    /** Clears the manager link on demo headquarters so a replaced demo manager can be removed. */
    public function detachDemoHeadquarters(): void
    {
        $this->pdo()->exec("UPDATE headquarters SET parent_manager_id = NULL WHERE code LIKE 'DEMO-HQ-%'");
    }

    /**
     * @param list<string> $keepCodes
     */
    public function deleteDemoHeadquartersExcept(array $keepCodes): void
    {
        $marks = implode(', ', array_fill(0, count($keepCodes), '?'));
        $this->pdo()->prepare(
            "DELETE FROM headquarters WHERE code LIKE 'DEMO-HQ-%' AND code NOT IN ({$marks})"
        )->execute($keepCodes);
    }

    /**
     * Sales rows for demo stockists that are about to be removed. Kept demo
     * stockists, and every stockist without a DEMO-STK code, stay as they are.
     *
     * @param list<string> $keepCodes
     */
    public function deleteSalesForRemovedDemoStockists(array $keepCodes): void
    {
        $marks = implode(', ', array_fill(0, count($keepCodes), '?'));
        $this->pdo()->prepare(
            "DELETE se FROM sales_entries se
             INNER JOIN stockists s ON s.id = se.stockist_id
             WHERE s.code LIKE 'DEMO-STK-%' AND s.code NOT IN ({$marks})"
        )->execute($keepCodes);
    }

    /**
     * @param list<string> $keepCodes
     */
    public function deleteDemoStockistsExcept(array $keepCodes): void
    {
        $marks = implode(', ', array_fill(0, count($keepCodes), '?'));
        $this->pdo()->prepare(
            "DELETE FROM stockists WHERE code LIKE 'DEMO-STK-%' AND code NOT IN ({$marks})"
        )->execute($keepCodes);
    }

    /** @param array<string, mixed> $fields */
    public function insertHeadquarters(array $fields): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow('headquarters', $fields),
            ['headquarters_code_unique' => ['code' => 'This code is already in use.']]
        );
    }

    /** @param array{name: string, code: ?string} $fields */
    public function updateHeadquarters(int $id, array $fields): void
    {
        $this->run(
            fn () => $this->updateRow('headquarters', $id, $fields),
            ['headquarters_code_unique' => ['code' => 'This code is already in use.']]
        );
    }

    public function deleteHeadquarters(int $id): void
    {
        $this->transaction(function () use ($id): void {
            $pdo = $this->pdo();
            $pdo->prepare('UPDATE managers SET parent_headquarters_id = NULL WHERE parent_headquarters_id = ?')->execute([$id]);
            $pdo->prepare(
                'DELETE FROM sales_entries WHERE headquarters_id = ? OR stockist_id IN (SELECT id FROM stockists WHERE headquarters_id = ?)'
            )->execute([$id, $id]);
            $pdo->prepare('DELETE FROM stockists WHERE headquarters_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM headquarters WHERE id = ?')->execute([$id]);
        });
    }

    public function stockistsForHeadquartersCount(int $headquartersId): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM stockists WHERE headquarters_id = ?');
        $statement->execute([$headquartersId]);

        return (int) $statement->fetchColumn();
    }

    public function salesForHeadquartersCount(int $headquartersId): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM sales_entries WHERE headquarters_id = ?');
        $statement->execute([$headquartersId]);

        return (int) $statement->fetchColumn();
    }

    public function headquartersForLocationCount(int $locationId): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM headquarters WHERE location_id = ?');
        $statement->execute([$locationId]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function locations(): array
    {
        return $this->many('SELECT id, name, code, created_at, updated_at FROM locations ORDER BY id');
    }

    /** @return array<string, mixed>|null */
    public function findLocation(int $id): ?array
    {
        return $this->one('SELECT id, name, code, created_at, updated_at FROM locations WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findLocationByName(string $name): ?array
    {
        return $this->one('SELECT id, name, code FROM locations WHERE name = ?', [$name]);
    }

    /** @return array<string, mixed>|null */
    public function findLocationByCode(string $code): ?array
    {
        return $this->one('SELECT id, name, code FROM locations WHERE code = ?', [$code]);
    }

    public function assignmentCount(int $locationId): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM manager_states WHERE location_id = ?');
        $statement->execute([$locationId]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $fields */
    public function insertLocation(array $fields): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow('locations', $fields),
            [
                'locations_name_unique' => ['name' => 'This state already exists.'],
                'locations_code_unique' => ['code' => 'This location code is already in use.'],
            ]
        );
    }

    /** @param array<string, mixed> $fields */
    public function updateLocation(int $id, array $fields): void
    {
        $this->run(
            fn () => $this->updateRow('locations', $id, $fields),
            [
                'locations_name_unique' => ['name' => 'This state already exists.'],
                'locations_code_unique' => ['code' => 'This location code is already in use.'],
            ]
        );
    }

    public function deleteLocation(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
    }

    /** @return array<string, mixed>|null */
    public function findLocationHeadquarters(int $id): ?array
    {
        return $this->one(
            'SELECT lh.id, lh.location_id, lh.name, lh.code, lh.address, lh.pincode, lh.phone, lh.email, l.name AS location_name
             FROM location_headquarters lh
             INNER JOIN locations l ON l.id = lh.location_id
             WHERE lh.id = ?',
            [$id],
        );
    }

    /** @return array<string, mixed>|null */
    public function findHeadquartersByLocationOffice(int $locationHeadquartersId): ?array
    {
        return $this->one('SELECT id FROM headquarters WHERE location_headquarters_id = ? ORDER BY id LIMIT 1', [$locationHeadquartersId]);
    }

    /** @param array<string, mixed> $fields */
    public function insertLocationHeadquarters(array $fields): int
    {
        $this->pdo()->prepare(
            'INSERT INTO location_headquarters (location_id, name, code, address, pincode, phone, email, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fields['location_id'],
            $fields['name'],
            $fields['code'],
            $fields['address'],
            $fields['pincode'],
            $fields['phone'],
            $fields['email'],
            $fields['position'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $fields */
    public function updateLocationHeadquarters(int $id, array $fields): void
    {
        $this->pdo()->prepare(
            'UPDATE location_headquarters SET location_id = ?, name = ?, code = ?, address = ?, pincode = ?, phone = ?, email = ? WHERE id = ?'
        )->execute([
            $fields['location_id'],
            $fields['name'],
            $fields['code'],
            $fields['address'],
            $fields['pincode'],
            $fields['phone'],
            $fields['email'],
            $id,
        ]);
    }

    public function deleteLocationHeadquarters(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM location_headquarters WHERE id = ?')->execute([$id]);
    }

    /** @return array<string, mixed>|null */
    public function findLocationHeadquartersByCode(string $code): ?array
    {
        return $this->one('SELECT id, code FROM location_headquarters WHERE code = ?', [$code]);
    }

    /** @param array<string, mixed> $fields */
    public function copyLocationOfficeOntoHeadquarters(int $locationHeadquartersId, array $fields): void
    {
        $this->pdo()->prepare(
            'UPDATE headquarters SET name = ?, state = ?, location_id = ?, address = ?, pincode = ?, phone = ?, email = ?, code = ? WHERE location_headquarters_id = ?'
        )->execute([
            $fields['name'],
            $fields['state'],
            $fields['location_id'],
            $fields['address'],
            $fields['pincode'],
            $fields['phone'],
            $fields['email'],
            $fields['code'],
            $locationHeadquartersId,
        ]);
    }

    /** @return array<int, list<array{id: int, name: string}>> */
    public function locationHeadquartersGrouped(): array
    {
        $grouped = [];
        foreach ($this->many('SELECT id, location_id, name, code, address, pincode, phone, email FROM location_headquarters ORDER BY location_id, position, id') as $row) {
            $grouped[(int) $row['location_id']][] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'code' => $row['code'],
                'address' => $row['address'],
                'pincode' => $row['pincode'],
                'phone' => $row['phone'],
                'email' => $row['email'],
            ];
        }

        return $grouped;
    }

    /**
     * Keep a row when its name is unchanged so editing does not create a second record.
     *
     * @param list<string> $names
     */
    public function replaceLocationHeadquarters(int $locationId, array $names): void
    {
        $existing = $this->many(
            'SELECT id, name FROM location_headquarters WHERE location_id = ? ORDER BY position, id',
            [$locationId],
        );
        $byName = [];
        foreach ($existing as $row) {
            $byName[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        }
        $kept = [];
        $position = 1;
        $update = $this->pdo()->prepare('UPDATE location_headquarters SET name = ?, position = ? WHERE id = ?');
        $insert = $this->pdo()->prepare('INSERT INTO location_headquarters (location_id, name, position) VALUES (?, ?, ?)');
        foreach ($names as $name) {
            $key = mb_strtolower($name);
            if (isset($byName[$key])) {
                $id = $byName[$key];
                $update->execute([$name, $position, $id]);
                $kept[] = $id;
            } else {
                $insert->execute([$locationId, $name, $position]);
                $kept[] = (int) $this->pdo()->lastInsertId();
            }
            $position++;
        }
        if ($kept === []) {
            $this->pdo()->prepare('DELETE FROM location_headquarters WHERE location_id = ?')->execute([$locationId]);

            return;
        }
        $marks = implode(', ', array_fill(0, count($kept), '?'));
        $this->pdo()->prepare("DELETE FROM location_headquarters WHERE location_id = ? AND id NOT IN ({$marks})")
            ->execute([$locationId, ...$kept]);
    }

    /**
     * @return array<int, list<array{id: int, name: string}>>
     */
    public function statesGrouped(): array
    {
        $grouped = [];
        foreach ($this->many(
            'SELECT ms.manager_id, l.id, l.name
             FROM manager_states ms
             INNER JOIN locations l ON l.id = ms.location_id
             ORDER BY l.id'
        ) as $row) {
            $grouped[(int) $row['manager_id']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }

        return $grouped;
    }

    /** @param list<int> $locationIds */
    public function replaceManagerStates(int $managerId, array $locationIds): void
    {
        $this->pdo()->prepare('DELETE FROM manager_states WHERE manager_id = ?')->execute([$managerId]);
        $insert = $this->pdo()->prepare('INSERT INTO manager_states (manager_id, location_id) VALUES (?, ?)');
        foreach ($locationIds as $locationId) {
            $insert->execute([$managerId, $locationId]);
        }
    }

    /** @param list<int> $ids @return list<int> */
    public function existingLocationIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->many("SELECT id FROM locations WHERE id IN ({$marks})", $ids);

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** @param array<string, mixed> $fields */
    public function insertManager(array $fields): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow('managers', $fields),
            [
                'managers_code_unique' => ['code' => 'This code is already in use.'],
                'managers_email_unique' => ['email' => 'This email address is already in use.'],
            ]
        );
    }

    /** @param array<string, mixed> $fields */
    public function childManagerCount(int $managerId): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM managers WHERE parent_manager_id = ?');
        $statement->execute([$managerId]);

        return (int) $statement->fetchColumn();
    }

    public function headquartersForManagerCount(int $managerId): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM headquarters WHERE parent_manager_id = ?');
        $statement->execute([$managerId]);

        return (int) $statement->fetchColumn();
    }

    public function deleteManager(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM manager_states WHERE manager_id = ?')->execute([$id]);
        $this->pdo()->prepare('DELETE FROM managers WHERE id = ?')->execute([$id]);
    }

    /** @param array<string, mixed> $fields */
    public function updateManager(int $id, array $fields): void
    {
        $this->run(
            fn () => $this->updateRow('managers', $id, $fields),
            [
                'managers_code_unique' => ['code' => 'This code is already in use.'],
                'managers_email_unique' => ['email' => 'This email address is already in use.'],
            ]
        );
    }

    /** @param array<string, mixed> $fields */
    public function insertStockist(array $fields): int
    {
        return (int) $this->run(
            fn (): int => $this->insertRow('stockists', $fields),
            ['stockists_code_unique' => ['code' => 'This code is already in use.']]
        );
    }

    /** @param array<string, mixed> $fields */
    public function updateStockist(int $id, array $fields): void
    {
        $this->run(
            fn () => $this->updateRow('stockists', $id, $fields),
            ['stockists_code_unique' => ['code' => 'This code is already in use.']]
        );
    }

    public function deleteStockist(int $id): void
    {
        $this->transaction(function () use ($id): void {
            $pdo = $this->pdo();
            $pdo->prepare('DELETE FROM sales_entries WHERE stockist_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM stockists WHERE id = ?')->execute([$id]);
        });
    }
}
