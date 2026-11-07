<?php

declare(strict_types=1);

namespace App\Services;

/** Maps database rows to the shapes the web app already uses. Ids are strings. */
final class ErpPresenter
{
    /** @param array<string, mixed> $row */
    public static function headquarters(array $row): array
    {
        return self::named($row) + [
            'state' => $row['state'] ?? null,
            'locationId' => Presenter::id($row['location_id'] ?? null),
            'locationHeadquartersId' => Presenter::id($row['location_headquarters_id'] ?? null),
            'address' => $row['address'] ?? null,
            'pincode' => $row['pincode'] ?? null,
            'phone' => $row['phone'] ?? null,
            'email' => $row['email'] ?? null,
            'parentManagerId' => Presenter::id($row['parent_manager_id'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{id: int, name: string}> $states
     */
    public static function manager(array $row, array $states = []): array
    {
        if ($row['parent_manager_id'] !== null) {
            $parentType = 'manager' . (int) $row['parent_level'];
            $parentId = $row['parent_manager_id'];
        } elseif ($row['parent_headquarters_id'] !== null) {
            $parentType = 'headquarters';
            $parentId = $row['parent_headquarters_id'];
        } else {
            $parentType = null;
            $parentId = null;
        }

        return [
            'id' => Presenter::id($row['id']),
            'level' => (int) $row['level'],
            'name' => $row['name'],
            'code' => $row['code'],
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'states' => array_map(
                static fn (array $state): array => ['id' => Presenter::id($state['id']), 'name' => $state['name']],
                $states,
            ),
            'parentType' => $parentType,
            'parentId' => Presenter::id($parentId),
            'createdAt' => Presenter::iso($row['created_at']),
            'updatedAt' => Presenter::iso($row['updated_at']),
        ];
    }

    /**
     * Nested sales office: 3rd line → 2nd line → 1st line → headquarters → stockists.
     * Records that do not sit on that chain are returned under `unplaced` and are not given a guessed parent.
     *
     * @param list<array<string, mixed>> $managers
     * @param list<array<string, mixed>> $headquarters
     * @param list<array<string, mixed>> $stockists
     * @return array{roots: list<array<string, mixed>>, unplaced: list<array<string, mixed>>}
     */
    public static function salesOfficeHierarchy(array $managers, array $headquarters, array $stockists): array
    {
        $byName = static function (array $left, array $right): int {
            return strcasecmp((string) $left['name'], (string) $right['name']) ?: strcmp((string) $left['id'], (string) $right['id']);
        };
        $node = static function (array $manager): array {
            return [
                'id' => $manager['id'],
                'name' => $manager['name'],
                'code' => $manager['code'],
                'level' => $manager['level'],
            ];
        };

        $placedManagers = [];
        $placedHeadquarters = [];
        $placedStockists = [];
        $roots = [];
        $thirds = array_values(array_filter($managers, static fn (array $manager): bool => $manager['level'] === 3 && $manager['parentId'] === null));
        usort($thirds, $byName);
        foreach ($thirds as $third) {
            $placedManagers[$third['id']] = true;
            $seconds = [];
            foreach ($managers as $second) {
                if ($second['level'] !== 2 || $second['parentType'] !== 'manager3' || $second['parentId'] !== $third['id']) {
                    continue;
                }
                $placedManagers[$second['id']] = true;
                $firsts = [];
                foreach ($managers as $first) {
                    if ($first['level'] !== 1 || $first['parentType'] !== 'manager2' || $first['parentId'] !== $second['id']) {
                        continue;
                    }
                    $placedManagers[$first['id']] = true;
                    $offices = [];
                    foreach ($headquarters as $office) {
                        if ($office['parentManagerId'] !== $first['id']) {
                            continue;
                        }
                        $placedHeadquarters[$office['id']] = true;
                        $shops = [];
                        foreach ($stockists as $stockist) {
                            if ($stockist['headquartersId'] !== $office['id']) {
                                continue;
                            }
                            $placedStockists[$stockist['id']] = true;
                            $shops[] = ['id' => $stockist['id'], 'name' => $stockist['name'], 'code' => $stockist['code']];
                        }
                        usort($shops, $byName);
                        $offices[] = [
                            'id' => $office['id'],
                            'name' => $office['name'],
                            'code' => $office['code'],
                            'state' => $office['state'],
                            'stockists' => $shops,
                        ];
                    }
                    usort($offices, $byName);
                    $firsts[] = $node($first) + ['headquarters' => $offices];
                }
                usort($firsts, $byName);
                $seconds[] = $node($second) + ['children' => $firsts];
            }
            usort($seconds, $byName);
            $roots[] = $node($third) + ['children' => $seconds];
        }

        $unplaced = [];
        foreach ($managers as $manager) {
            if (!isset($placedManagers[$manager['id']])) {
                $unplaced[] = $node($manager) + ['kind' => 'manager'];
            }
        }
        foreach ($headquarters as $office) {
            if (!isset($placedHeadquarters[$office['id']])) {
                $unplaced[] = ['id' => $office['id'], 'name' => $office['name'], 'code' => $office['code'], 'kind' => 'headquarters'];
            }
        }
        foreach ($stockists as $stockist) {
            if (!isset($placedStockists[$stockist['id']])) {
                $unplaced[] = ['id' => $stockist['id'], 'name' => $stockist['name'], 'code' => $stockist['code'], 'kind' => 'stockist'];
            }
        }
        usort($unplaced, $byName);

        return ['roots' => $roots, 'unplaced' => $unplaced];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{id: int, name: string}> $headquarters
     */
    public static function location(array $row, array $headquarters = []): array
    {
        return [
            'id' => Presenter::id($row['id']),
            'name' => $row['name'],
            'code' => $row['code'],
            'headquarters' => array_map(
                static fn (array $office): array => [
                    'id' => Presenter::id($office['id']),
                    'name' => $office['name'],
                    'code' => $office['code'] ?? null,
                    'address' => $office['address'] ?? null,
                    'pincode' => $office['pincode'] ?? null,
                    'phone' => $office['phone'] ?? null,
                    'email' => $office['email'] ?? null,
                ],
                $headquarters,
            ),
            'createdAt' => Presenter::iso($row['created_at']),
            'updatedAt' => Presenter::iso($row['updated_at']),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function stockist(array $row): array
    {
        return self::named($row) + [
            'headquartersId' => Presenter::id($row['headquarters_id']),
            'address' => $row['address'] ?? null,
            'pincode' => $row['pincode'] ?? null,
            'phone' => $row['phone'] ?? null,
            'email' => $row['email'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function term(array $row): array
    {
        return [
            'id' => Presenter::id($row['id']),
            'kind' => self::termKindOut((string) $row['kind']),
            'parentId' => Presenter::id($row['parent_id'] ?? null),
            'name' => $row['name'],
            'description' => $row['description'] ?? null,
            'status' => $row['status'] ?? 'active',
            'createdAt' => Presenter::iso($row['created_at']),
            'updatedAt' => Presenter::iso($row['updated_at']),
            'deletedAt' => Presenter::iso($row['deleted_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $tagIds
     * @param list<int> $badgeIds
     */
    public static function product(array $row, array $tagIds = [], array $badgeIds = []): array
    {
        return [
            'id' => Presenter::id($row['id']),
            'name' => $row['name'],
            'code' => $row['code'],
            'categoryId' => Presenter::id($row['category_id']),
            'strip' => $row['strip'] ?? null,
            'pack' => $row['pack'] ?? null,
            'mrp' => self::money($row['mrp'] ?? null),
            'ptr' => self::money($row['ptr'] ?? null),
            'pts' => self::money($row['pts'] ?? null),
            'scheme' => $row['scheme'] ?? null,
            'tagIds' => array_map(Presenter::id(...), $tagIds),
            'badgeIds' => array_map(Presenter::id(...), $badgeIds),
            'createdAt' => Presenter::iso($row['created_at']),
            'updatedAt' => Presenter::iso($row['updated_at']),
            'deletedAt' => Presenter::iso($row['deleted_at']),
        ];
    }

    /**
     * Product change history, including scheme changes that were stored on the
     * year sheet before the scheme moved to the product. A scheme change is
     * shown once.
     *
     * @param list<array<string, mixed>> $productChanges
     * @param list<array<string, mixed>> $sheetChanges
     * @return list<array<string, mixed>>
     */
    public static function productHistory(array $productChanges, array $sheetChanges): array
    {
        $items = array_map(self::productChange(...), $productChanges);
        $seen = [];
        foreach ($items as $item) {
            foreach ($item['changes'] as $change) {
                if (($change['field'] ?? null) === 'Scheme') {
                    $seen[self::schemeKey($item['productId'], $change['from'] ?? null, $change['to'] ?? null, $item['at'])] = true;
                }
            }
        }
        foreach ($sheetChanges as $row) {
            if ($row['field'] !== 'Scheme') {
                continue;
            }
            $change = self::sheetChange($row);
            $key = self::schemeKey($change['productId'], $change['from'], $change['to'], $change['at']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = [
                'id' => 'sheet-scheme-' . $change['id'],
                'productId' => $change['productId'],
                'productName' => $change['productName'],
                'action' => 'updated',
                'changes' => [['field' => 'Scheme', 'from' => $change['from'], 'to' => $change['to']]],
                'at' => $change['at'],
                'by' => $change['by'],
            ];
        }

        return $items;
    }

    private static function schemeKey(string $productId, ?string $from, ?string $to, string $at): string
    {
        return $productId . "\n" . ($from ?? '') . "\n" . ($to ?? '') . "\n" . $at;
    }

    /** @param array<string, mixed> $row */
    public static function productChange(array $row): array
    {
        $changes = $row['changes'];
        if (is_string($changes)) {
            $changes = json_decode($changes, true) ?: [];
        }

        return [
            'id' => Presenter::id($row['id']),
            'productId' => Presenter::id($row['product_id']),
            'productName' => $row['product_name'],
            'action' => $row['action'],
            'changes' => $changes,
            'at' => Presenter::iso($row['changed_at']),
            'by' => $row['changed_by_name'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function sheetChange(array $row): array
    {
        return [
            'id' => Presenter::id($row['id']),
            'year' => $row['year_label'],
            'productId' => Presenter::id($row['product_id']),
            'productName' => $row['product_name'],
            'field' => $row['field'],
            'from' => $row['old_value'],
            'to' => $row['new_value'],
            'at' => Presenter::iso($row['changed_at']),
            'by' => $row['changed_by_name'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function sales(array $row): array
    {
        return [
            'id' => Presenter::id($row['id']),
            'month' => substr((string) $row['sales_month'], 0, 7),
            'headquartersId' => Presenter::id($row['headquarters_id']),
            'stockistId' => Presenter::id($row['stockist_id']),
            'achievementValue' => self::money($row['achievement_value']),
            'bonus' => self::money($row['bonus']),
            'deduction' => self::money($row['deduction']),
            'finalValue' => self::money($row['final_value']),
            'createdAt' => Presenter::iso($row['created_at']),
            'updatedAt' => Presenter::iso($row['updated_at']),
        ];
    }

    public static function termKindIn(string $kind): ?string
    {
        return [
            'groupCategory' => 'group_category',
            'category' => 'category',
            'tag' => 'tag',
            'badge' => 'badge',
        ][$kind] ?? null;
    }

    public static function termKindOut(string $kind): string
    {
        return $kind === 'group_category' ? 'groupCategory' : $kind;
    }

    /** @param array<string, mixed> $row */
    private static function named(array $row): array
    {
        return [
            'id' => Presenter::id($row['id']),
            'name' => $row['name'],
            'code' => $row['code'],
            'createdAt' => Presenter::iso($row['created_at']),
            'updatedAt' => Presenter::iso($row['updated_at']),
        ];
    }

    private static function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
