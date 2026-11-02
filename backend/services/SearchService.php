<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SearchRepository;
use App\Repositories\UserRepository;
use App\Utils\HttpException;

/**
 * Global record search. Each group is included only when the signed-in user
 * may view that module. Paths are fixed list pages; nothing from the query
 * is turned into a URL, a column name, or a response field.
 */
final class SearchService
{
    private const MAX_LENGTH = 80;

    /** @var array<string, array{0: string, 1: string, 2: string}> kind => [group type, group label, detail] */
    private const TERM_GROUPS = [
        'group_category' => ['group-categories', 'Product group categories', 'Group category'],
        'category' => ['categories', 'Product categories', 'Product category'],
        'tag' => ['tags-badges', 'Product tags & badges', 'Tag'],
        'badge' => ['tags-badges', 'Product tags & badges', 'Badge'],
    ];

    /** @var array<string, string> */
    private const TERM_PATHS = [
        'group_category' => '/products/group-categories',
        'category' => '/products/categories',
        'tag' => '/products/tags-badges',
        'badge' => '/products/tags-badges',
    ];

    /** @var array<int, string> */
    private const MANAGER_PATHS = [
        1 => '/sales-office/first-line-managers',
        2 => '/sales-office/second-line-managers',
        3 => '/sales-office/third-line-managers',
    ];

    public function __construct(
        private readonly SearchRepository $records = new SearchRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @return array{groups: list<array{type: string, label: string, items: list<array{id: string, label: string, detail: string, path: string}>}>}
     */
    public function search(array $user, mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['groups' => []];
        }
        if (!is_string($raw)) {
            throw HttpException::validation(['q' => 'Enter text to search.']);
        }

        $query = trim($raw);
        if ($query === '') {
            return ['groups' => []];
        }
        if (mb_strlen($query) > self::MAX_LENGTH) {
            throw HttpException::validation(['q' => 'Search text must be 80 characters or fewer.']);
        }

        $like = '%' . addcslashes($query, '\\%_') . '%';
        $groups = [];

        if (Authorization::can($user, 'catalogue.view')) {
            foreach (['group_category', 'category'] as $kind) {
                [$type, $label] = self::TERM_GROUPS[$kind];
                $this->addGroup($groups, $type, $label, $this->terms($like, [$kind]));
            }
            $this->addGroup($groups, 'tags-badges', 'Product tags & badges', $this->terms($like, ['tag', 'badge']));
        }

        if (Authorization::can($user, 'products.view')) {
            $this->addGroup($groups, 'products', 'Products', $this->products($like));
        }

        if (Authorization::can($user, 'master_sheet.view')) {
            $this->addGroup($groups, 'financial-years', 'Financial years', $this->years($like));
        }

        if (Authorization::can($user, 'sales_office.view')) {
            $this->addGroup($groups, 'headquarters', 'Headquarters', $this->headquarters($like));
            $this->addGroup($groups, 'stockists', 'Stockists', $this->stockists($like));
            $this->addGroup($groups, 'managers', 'Managers', $this->managers($like));
            $this->addGroup($groups, 'locations', 'Locations', $this->locations($like));
        }

        if (Authorization::can($user, 'sales.view')) {
            $this->addGroup($groups, 'sales', 'Sales entries', $this->sales($like));
        }

        if (Authorization::can($user, 'users.view')) {
            $this->addGroup($groups, 'users', 'Users', $this->userRows($like));
        }

        return ['groups' => $groups];
    }

    /** @param list<array{type: string, label: string, items: list<array<string, string>>}> $groups */
    /** @param list<array<string, string>> $items */
    private function addGroup(array &$groups, string $type, string $label, array $items): void
    {
        if ($items !== []) {
            $groups[] = ['type' => $type, 'label' => $label, 'items' => $items];
        }
    }

    /** @return list<array<string, string>> */
    private function headquarters(string $like): array
    {
        return array_map(fn (array $row): array => $this->item(
            'headquarters',
            $row['id'],
            (string) $row['name'],
            $this->detail($row['code'] ?? null, $row['state'] ?? null),
            '/sales-office/headquarters',
        ), $this->records->headquarters($like));
    }

    /** @return list<array<string, string>> */
    private function managers(string $like): array
    {
        $items = [];
        foreach ($this->records->managers($like) as $row) {
            $level = (int) $row['level'];
            if (!isset(self::MANAGER_PATHS[$level])) {
                continue;
            }
            $items[] = $this->item(
                'manager',
                $row['id'],
                (string) $row['name'],
                $this->detail($this->managerLevel($level), $row['code'] ?? null, $row['email'] ?? null),
                self::MANAGER_PATHS[$level],
            );
        }

        return $items;
    }

    /** @return list<array<string, string>> */
    private function stockists(string $like): array
    {
        return array_map(fn (array $row): array => $this->item(
            'stockist',
            $row['id'],
            (string) $row['name'],
            $this->detail($row['code'] ?? null, $row['headquarters_name'] ?? null),
            '/sales-office/stockists',
        ), $this->records->stockists($like));
    }

    /** @return list<array<string, string>> */
    private function locations(string $like): array
    {
        return array_map(fn (array $row): array => $this->item(
            'location',
            $row['id'],
            (string) $row['name'],
            $this->detail($row['code'] ?? null),
            '/sales-office/locations',
        ), $this->records->locations($like));
    }

    /**
     * @param list<string> $kinds
     * @return list<array<string, string>>
     */
    private function terms(string $like, array $kinds): array
    {
        $items = [];
        foreach ($this->records->terms($like, $kinds) as $row) {
            $kind = (string) $row['kind'];
            if (!isset(self::TERM_GROUPS[$kind], self::TERM_PATHS[$kind])) {
                continue;
            }
            $items[] = $this->item(
                'term',
                $row['id'],
                (string) $row['name'],
                $this->detail(self::TERM_GROUPS[$kind][2], $row['deleted_at'] !== null ? 'Deleted' : null),
                self::TERM_PATHS[$kind],
            );
        }

        return $items;
    }

    /** @return list<array<string, string>> */
    private function products(string $like): array
    {
        return array_map(function (array $row): array {
            $deleted = $row['deleted_at'] !== null;

            return $this->item(
                'product',
                $row['id'],
                (string) $row['name'],
                $this->detail($deleted ? 'Deleted' : null, $row['code'] ?? null),
                $deleted ? '/products/deleted' : '/products/list',
            );
        }, $this->records->products($like));
    }

    /** @return list<array<string, string>> */
    private function years(string $like): array
    {
        return array_map(fn (array $row): array => $this->item(
            'year',
            $row['id'],
            (string) $row['label'],
            'Financial year',
            '/products/master-sheet',
        ), $this->records->years($like));
    }

    /** @return list<array<string, string>> */
    private function sales(string $like): array
    {
        return array_map(fn (array $row): array => $this->item(
            'sale',
            $row['id'],
            (string) $row['stockist_name'],
            $this->detail($row['sales_month'] ?? null, $row['headquarters_name'] ?? null),
            '/sales/stockist-sales',
        ), $this->records->sales($like));
    }

    /** @return list<array<string, string>> */
    private function userRows(string $like): array
    {
        return array_map(fn (array $row): array => $this->item(
            'user',
            $row['id'],
            (string) $row['name'],
            $this->detail($row['email'] ?? null, $row['role_name'] ?? null, ($row['status'] ?? '') === 'active' ? 'Active' : 'Disabled'),
            '/system/users',
        ), $this->users->search($like));
    }

    /** @return array{id: string, label: string, detail: string, path: string} */
    private function item(string $type, int|string $id, string $label, string $detail, string $path): array
    {
        return [
            'id' => $type . ':' . $id,
            'label' => $label,
            'detail' => $detail,
            'path' => $path,
        ];
    }

    private function detail(mixed ...$parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }
            $part = trim($part);
            if ($part !== '') {
                $clean[] = $part;
            }
        }

        return implode(' · ', $clean);
    }

    private function managerLevel(int $level): string
    {
        return match ($level) {
            1 => '1st line manager',
            2 => '2nd line manager',
            3 => '3rd line manager',
            default => 'Manager',
        };
    }
}
