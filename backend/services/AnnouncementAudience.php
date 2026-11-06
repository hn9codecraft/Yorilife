<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnnouncementRepository;

/**
 * Works out who receives an announcement. Three kinds of people: Sales Office
 * managers, ERP users and the employee list (stockists are never recipients).
 *
 * - "All" = every manager, every active ERP user and every active employee.
 * - Filters: inside one filter it is OR, between filters it is AND.
 *     location      managers (own states, states above them, or a headquarters below them) and employees (own or their HQ's state)
 *     headquarters  the 1st line manager of that HQ and the 2nd / 3rd line above, plus employees linked to it
 *     manager_level managers of that line only
 *     role          ERP users with that role slug and employees with that role
 *   A person kind a chosen filter does not apply to is left out (e.g. ERP users have no location).
 * - People picked from the employee list are always added on top.
 */
final class AnnouncementAudience
{
    public const RULE_TYPES = ['location', 'headquarters', 'manager_level', 'role', 'employee'];

    /** @var array<string, array<string, string>>|null names cached for one request */
    private ?array $names = null;

    public function __construct(private readonly AnnouncementRepository $repository = new AnnouncementRepository())
    {
    }

    /**
     * @param array<string, list<string>> $rules
     * @return list<array{type: string, id: int, name: string, contact: ?string, detail: string}>
     */
    public function resolve(bool $all, array $rules): array
    {
        $locations = array_flip($rules['location'] ?? []);
        $hqs = array_flip($rules['headquarters'] ?? []);
        $levels = array_flip($rules['manager_level'] ?? []);
        $roles = array_flip(array_map('mb_strtolower', $rules['role'] ?? []));
        $picked = array_flip($rules['employee'] ?? []);
        $hasFilter = $locations !== [] || $hqs !== [] || $levels !== [] || $roles !== [];

        $people = [];
        if ($all || $hasFilter) {
            foreach ($this->managers() as $manager) {
                if ($all || (($locations === [] || array_intersect_key($manager['locations'], $locations) !== [])
                    && ($hqs === [] || array_intersect_key($manager['hqs'], $hqs) !== [])
                    && ($levels === [] || isset($levels[(string) $manager['level']]))
                    && $roles === [])) {
                    $people[] = [
                        'type' => 'manager',
                        'id' => (int) $manager['id'],
                        'name' => (string) $manager['name'],
                        'contact' => $manager['email'] ?: ($manager['phone'] ?: null),
                        'detail' => self::ordinal((int) $manager['level']) . ' line manager' . ($manager['code'] ? ' · ' . $manager['code'] : ''),
                    ];
                }
            }
            foreach ($this->repository->activeUsers() as $user) {
                if ($all || ($locations === [] && $hqs === [] && $levels === [] && isset($roles[mb_strtolower((string) $user['role_slug'])]))) {
                    $people[] = ['type' => 'user', 'id' => (int) $user['id'], 'name' => (string) $user['name'], 'contact' => $user['email'], 'detail' => 'ERP user · ' . $user['role_name']];
                }
            }
        }

        $seenEmployees = [];
        foreach ($this->repository->activeEmployees() as $employee) {
            $id = (string) $employee['id'];
            $employeeLocations = array_filter([(string) $employee['location_id'], (string) $employee['hq_location_id']]);
            $matches = $all || $hasFilter && (($locations === [] || array_intersect_key(array_flip($employeeLocations), $locations) !== [])
                && ($hqs === [] || isset($hqs[(string) $employee['headquarters_id']]))
                && $levels === []
                && ($roles === [] || isset($roles[mb_strtolower((string) $employee['role'])])));
            if (($matches || isset($picked[$id])) && !isset($seenEmployees[$id])) {
                $seenEmployees[$id] = true;
                $people[] = [
                    'type' => 'employee',
                    'id' => (int) $employee['id'],
                    'name' => (string) $employee['name'],
                    'contact' => $employee['email'] ?: ($employee['phone'] ?: null),
                    'detail' => 'Employee' . ($employee['role'] ? ' · ' . $employee['role'] : ''),
                ];
            }
        }

        return $people;
    }

    /** @return array{total: int, byType: array<string, int>, sample: list<array<string, mixed>>} */
    public function preview(bool $all, array $rules): array
    {
        $people = $this->resolve($all, $rules);
        $byType = ['manager' => 0, 'user' => 0, 'employee' => 0];
        foreach ($people as $person) {
            $byType[$person['type']]++;
        }

        return [
            'total' => count($people),
            'byType' => $byType,
            'sample' => array_map(static fn (array $p): array => ['type' => $p['type'], 'name' => $p['name'], 'detail' => $p['detail']], array_slice($people, 0, 10)),
        ];
    }

    /** Short text such as "Location: Gujarat; Manager line: 1st" for screens and print. */
    public function summary(bool $all, array $rules): string
    {
        if ($all) {
            return 'All employees (managers, ERP users and employee list)';
        }
        $names = $this->names();
        $parts = [];
        $labels = ['location' => 'Location', 'headquarters' => 'Headquarters', 'manager_level' => 'Manager line', 'role' => 'Role', 'employee' => 'Employees'];
        foreach ($labels as $type => $label) {
            $values = $rules[$type] ?? [];
            if ($values === []) {
                continue;
            }
            $shown = array_map(static fn (string $value): string => $names[$type][$value] ?? $value, $values);
            $parts[] = $label . ': ' . implode(', ', $shown);
        }

        return $parts === [] ? 'No audience chosen' : implode('; ', $parts);
    }

    /** @return array<string, array<string, string>> */
    private function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }
        $names = ['location' => [], 'headquarters' => [], 'manager_level' => ['1' => '1st line', '2' => '2nd line', '3' => '3rd line'], 'role' => [], 'employee' => []];
        foreach ($this->repository->locations() as $row) {
            $names['location'][(string) $row['id']] = (string) $row['name'];
        }
        foreach ($this->repository->headquarters() as $row) {
            $names['headquarters'][(string) $row['id']] = trim($row['name'] . ($row['code'] ? " ({$row['code']})" : ''));
        }
        foreach ($this->repository->roles() as $row) {
            $names['role'][(string) $row['slug']] = (string) $row['name'];
        }
        foreach ($this->repository->employeeChoices() as $row) {
            $names['employee'][(string) $row['id']] = (string) $row['name'];
        }

        return $this->names = $names;
    }

    /**
     * Managers with the locations and headquarters they cover.
     *
     * @return list<array<string, mixed>>
     */
    private function managers(): array
    {
        $managers = [];
        foreach ($this->repository->managers() as $row) {
            $row['locations'] = [];
            $row['hqs'] = [];
            $managers[(int) $row['id']] = $row;
        }
        $parentOf = static fn (int $id): ?int => isset($managers[$id]['parent_manager_id']) ? (int) $managers[$id]['parent_manager_id'] : null;
        $chain = static function (int $id) use ($managers, $parentOf): array {
            $ids = [];
            for ($current = $id; $current !== null && isset($managers[$current]) && !in_array($current, $ids, true); $current = $parentOf($current)) {
                $ids[] = $current;
            }

            return $ids;
        };

        $ownStates = [];
        foreach ($this->repository->managerLocations() as $row) {
            $ownStates[(int) $row['manager_id']][] = (string) $row['location_id'];
        }
        // States set on a manager or anyone above them.
        foreach (array_keys($managers) as $id) {
            foreach ($chain($id) as $above) {
                foreach ($ownStates[$above] ?? [] as $location) {
                    $managers[$id]['locations'][$location] = true;
                }
            }
        }
        // Headquarters (and their state) below each manager.
        foreach ($this->repository->headquarters() as $hq) {
            if ($hq['parent_manager_id'] === null) {
                continue;
            }
            foreach ($chain((int) $hq['parent_manager_id']) as $above) {
                $managers[$above]['hqs'][(string) $hq['id']] = true;
                if ($hq['location_id'] !== null) {
                    $managers[$above]['locations'][(string) $hq['location_id']] = true;
                }
            }
        }

        return array_values($managers);
    }

    private static function ordinal(int $level): string
    {
        return match ($level) {
            1 => '1st',
            2 => '2nd',
            3 => '3rd',
            default => "{$level}th",
        };
    }
}