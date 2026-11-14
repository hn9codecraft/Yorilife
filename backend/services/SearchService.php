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

    /** Products > Category page (Product Type and Product Group tables). */
    private const CATEGORY_PATH = '/products/category';

    /** Products > Product page (the product list). */
    private const PRODUCT_PATH = '/products/list';

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

        // The old group categories, categories, tags/badges, Master Sheet and
        // Deleted Products pages are no longer in the app, so they are not searched.
        if (Authorization::can($user, 'catalogue.view')) {
            $this->addGroup($groups, 'product-types', 'Product types', $this->productCategories($like, 'type'));
            $this->addGroup($groups, 'product-groups', 'Product groups', $this->productCategories($like, 'group'));
        }

        if (Authorization::can($user, 'products.view')) {
            $this->addGroup($groups, 'products', 'Products', $this->products($like));
        }

        if (Authorization::can($user, 'sales_office.view')) {
            $this->addGroup($groups, 'headquarters', 'Headquarters', $this->headquarters($like));
            $this->addGroup($groups, 'stockists', 'Stockists', $this->stockists($like));
            $this->addGroup($groups, 'managers', 'Managers', $this->managers($like));
            $this->addGroup($groups, 'employees', 'Employees', $this->salesEmployees($like));
            $this->addGroup($groups, 'locations', 'Locations', $this->locations($like));
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
            '/sales-office/hierarchy',
        ), $this->records->headquarters($like));
    }

    /** @return list<array<string, string>> */
    private function managers(string $like): array
    {
        $items = [];
        foreach ($this->records->managers($like) as $row) {
            $level = (int) $row['level'];
            $items[] = $this->item(
                'manager',
                $row['id'],
                (string) $row['name'],
                $this->detail($this->managerLevel($level), $row['code'] ?? null, $row['email'] ?? null),
                '/sales-office/hierarchy',
            );
        }

        return $items;
    }

    /** @return list<array<string, string>> */
    private function salesEmployees(string $like): array
    {
        $items = [];
        foreach ($this->records->salesEmployees($like) as $row) {
            $label = SalesEmployeeService::fullName(
                isset($row['first_name']) ? ($row['first_name'] !== null ? (string) $row['first_name'] : null) : null,
                isset($row['last_name']) ? ($row['last_name'] !== null ? (string) $row['last_name'] : null) : null,
            ) ?? '';
            if ($label === '') {
                $label = trim((string) ($row['name'] ?? ''));
            }
            if ($label === '') {
                $label = trim((string) ($row['employee_code'] ?? ''));
            }
            if ($label === '') {
                $label = 'Employee';
            }
            $designation = SalesEmployeeService::DESIGNATIONS[$row['designation'] ?? ''] ?? null;
            $items[] = $this->item(
                'sales_employee',
                $row['id'],
                $label,
                $this->detail($designation, $row['employee_code'] ?? null),
                '/sales-office/employees',
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
     * Product types or groups that are not deleted.
     *
     * @return list<array<string, string>>
     */
    private function productCategories(string $like, string $kind): array
    {
        return array_map(fn (array $row): array => $this->item(
            $kind === 'type' ? 'product_type' : 'product_group',
            $row['id'],
            (string) $row['name'],
            $kind === 'type' ? 'Product type' : 'Product group',
            self::CATEGORY_PATH,
        ), $this->records->productCategories($like, $kind));
    }

    /** Active products only (by name or SKU). @return list<array<string, string>> */
    private function products(string $like): array
    {
        return array_map(fn (array $row): array => $this->item(
            'product',
            $row['id'],
            (string) $row['name'],
            $this->detail($row['code'] ?? null, null),
            self::PRODUCT_PATH,
        ), $this->records->products($like));
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
