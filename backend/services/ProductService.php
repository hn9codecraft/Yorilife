<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductCategoryRepository;
use App\Repositories\ProductRepository;
use App\Utils\HttpException;

final class ProductService
{
    public function __construct(private readonly ProductRepository $records = new ProductRepository())
    {
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $actor */
    public function create(array $actor, array $input): array
    {
        $parsed = $this->parse($input, null);
        $id = $this->records->transaction(function () use ($parsed, $actor): int {
            $id = $this->records->insert([
                'name' => $parsed['name'],
                'code' => $parsed['code'],
                'category_id' => $parsed['categoryId'],
                'product_type_id' => $parsed['productTypeId'],
                'product_group_id' => $parsed['productGroupId'],
                'packaging_unit' => $parsed['packagingUnit'],
                'strip' => $parsed['strip'],
                'pack' => $parsed['pack'],
                'mrp' => $parsed['mrp'],
                'ptr' => $parsed['ptr'],
                'pts' => $parsed['pts'],
                'net_rate' => $parsed['netRate'],
                'scheme' => $parsed['scheme']['text'],
                'scheme_buy_qty' => $parsed['scheme']['buy'],
                'scheme_free_qty' => $parsed['scheme']['free'],
            ]);
            if ($parsed['replaceTerms']) {
                $this->records->replaceTerms($id, [...$parsed['tagIds'], ...$parsed['badgeIds']]);
            }
            $this->records->addChange($id, $parsed['name'], 'created', $parsed['changes'], (int) $actor['id']);
            AuditLogger::record([
                'module' => 'products',
                'action' => 'create',
                'targetType' => 'product',
                'targetId' => $id,
                'targetLabel' => $parsed['name'],
            ]);

            return $id;
        });

        return $this->present($id);
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $actor */
    public function update(array $actor, int $id, array $input): array
    {
        $current = $this->records->find($id) ?? throw HttpException::notFound('Product not found.');
        if ($current['deleted_at'] !== null) {
            throw HttpException::validation(['name' => 'Restore this product before editing it.']);
        }
        $parsed = $this->parse($input, $current);
        if ($parsed['changes'] === []) {
            return $this->present($id);
        }
        $this->records->transaction(function () use ($id, $parsed, $actor): void {
            $this->records->update($id, [
                'name' => $parsed['name'],
                'code' => $parsed['code'],
                'category_id' => $parsed['categoryId'],
                'product_type_id' => $parsed['productTypeId'],
                'product_group_id' => $parsed['productGroupId'],
                'packaging_unit' => $parsed['packagingUnit'],
                'strip' => $parsed['strip'],
                'pack' => $parsed['pack'],
                'mrp' => $parsed['mrp'],
                'ptr' => $parsed['ptr'],
                'pts' => $parsed['pts'],
                'net_rate' => $parsed['netRate'],
                'scheme' => $parsed['scheme']['text'],
                'scheme_buy_qty' => $parsed['scheme']['buy'],
                'scheme_free_qty' => $parsed['scheme']['free'],
            ]);
            if ($parsed['replaceTerms']) {
                $this->records->replaceTerms($id, [...$parsed['tagIds'], ...$parsed['badgeIds']]);
            }
            $this->records->addChange($id, $parsed['name'], 'updated', $parsed['changes'], (int) $actor['id']);
            AuditLogger::record([
                'module' => 'products',
                'action' => 'update',
                'targetType' => 'product',
                'targetId' => $id,
                'targetLabel' => $parsed['name'],
                'details' => ['fields' => array_column($parsed['changes'], 'field')],
            ]);
        });

        return $this->present($id);
    }

    /** @param array<string, mixed> $actor */
    public function delete(array $actor, int $id): array
    {
        $current = $this->records->find($id) ?? throw HttpException::notFound('Product not found.');
        if ($current['deleted_at'] === null) {
            $this->records->transaction(function () use ($current, $actor): void {
                $this->records->setDeleted((int) $current['id'], true);
                $this->records->addChange((int) $current['id'], $current['name'], 'deleted', [
                    ['field' => 'Status', 'from' => 'Active', 'to' => 'Deleted'],
                ], (int) $actor['id']);
                AuditLogger::record([
                    'module' => 'products',
                    'action' => 'delete',
                    'targetType' => 'product',
                    'targetId' => (int) $current['id'],
                    'targetLabel' => $current['name'],
                ]);
            });
        }

        return $this->present($id);
    }

    /** @param array<string, mixed> $actor */
    public function restore(array $actor, int $id): array
    {
        $current = $this->records->find($id) ?? throw HttpException::notFound('Product not found.');
        if ($current['deleted_at'] !== null) {
            $this->records->transaction(function () use ($current, $actor): void {
                $this->records->setDeleted((int) $current['id'], false);
                $this->records->addChange((int) $current['id'], $current['name'], 'restored', [
                    ['field' => 'Status', 'from' => 'Deleted', 'to' => 'Active'],
                ], (int) $actor['id']);
                AuditLogger::record([
                    'module' => 'products',
                    'action' => 'restore',
                    'targetType' => 'product',
                    'targetId' => (int) $current['id'],
                    'targetLabel' => $current['name'],
                ]);
            });
        }

        return $this->present($id);
    }

    public function present(int $id): array
    {
        $row = $this->records->find($id);
        $links = $this->records->linksByProduct()[$id] ?? [];

        return ErpPresenter::product($row, $links['tag'] ?? [], $links['badge'] ?? []);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function parse(array $input, ?array $current): array
    {
        $errors = [];
        Input::onlyFields($input, [
            'name', 'code', 'groupCategoryId', 'categoryId', 'scheme',
            'strip', 'pack', 'mrp', 'ptr', 'pts', 'tagIds', 'badgeIds',
            // Product List (Products > Product) fields.
            'sku', 'productTypeId', 'productGroupId', 'packagingUnit', 'netRate',
        ], $errors);
        $name = Input::text($input, 'name', 'product name', 200, $errors);
        // SKU is stored in products.code (unique among active products).
        // "sku" is the Product List field; "code" is kept for older clients.
        if (array_key_exists('sku', $input)) {
            $code = Input::optionalText($input, 'sku', 'SKU', 50, $errors);
        } elseif (array_key_exists('code', $input) || $current === null) {
            $code = Input::optionalText($input, 'code', 'product code', 50, $errors);
        } else {
            $code = $current['code'] ?? null;
        }
        $packagingUnit = array_key_exists('packagingUnit', $input)
            ? Input::optionalText($input, 'packagingUnit', 'packaging unit', 100, $errors)
            : ($current['packaging_unit'] ?? null);
        $netRate = array_key_exists('netRate', $input)
            ? Input::money($input, 'netRate', 'Net rate', $errors)
            : (isset($current['net_rate']) && $current['net_rate'] !== null && $current['net_rate'] !== ''
                ? number_format((float) $current['net_rate'], 2, '.', '')
                : null);
        $productTypeId = array_key_exists('productTypeId', $input)
            ? $this->optionalId($input['productTypeId'], 'productTypeId', 'product type', $errors)
            : (isset($current['product_type_id']) && $current['product_type_id'] !== null ? (int) $current['product_type_id'] : null);
        $productGroupId = array_key_exists('productGroupId', $input)
            ? $this->optionalId($input['productGroupId'], 'productGroupId', 'product group', $errors)
            : (isset($current['product_group_id']) && $current['product_group_id'] !== null ? (int) $current['product_group_id'] : null);
        // Commercial fields: when omitted on edit, keep the stored value.
        $strip = array_key_exists('strip', $input)
            ? Input::optionalText($input, 'strip', 'strip', 50, $errors)
            : ($current['strip'] ?? null);
        $pack = array_key_exists('pack', $input)
            ? Input::optionalText($input, 'pack', 'pack', 50, $errors)
            : ($current['pack'] ?? null);
        $mrp = array_key_exists('mrp', $input)
            ? Input::money($input, 'mrp', 'MRP', $errors)
            : (isset($current['mrp']) && $current['mrp'] !== null && $current['mrp'] !== ''
                ? number_format((float) $current['mrp'], 2, '.', '')
                : null);
        $ptr = array_key_exists('ptr', $input)
            ? Input::money($input, 'ptr', 'PTR', $errors)
            : (isset($current['ptr']) && $current['ptr'] !== null && $current['ptr'] !== ''
                ? number_format((float) $current['ptr'], 2, '.', '')
                : null);
        $pts = array_key_exists('pts', $input)
            ? Input::money($input, 'pts', 'PTS', $errors)
            : (isset($current['pts']) && $current['pts'] !== null && $current['pts'] !== ''
                ? number_format((float) $current['pts'], 2, '.', '')
                : null);

        // Category and group are optional. groupCategoryId is accepted for
        // older clients; when both are set they must still match.
        $categoryId = array_key_exists('categoryId', $input)
            ? $this->optionalId($input['categoryId'], 'categoryId', 'product category', $errors)
            : (isset($current['category_id']) && $current['category_id'] !== null ? (int) $current['category_id'] : null);
        $groupId = array_key_exists('groupCategoryId', $input)
            ? $this->optionalId($input['groupCategoryId'], 'groupCategoryId', 'group category', $errors)
            : null;

        $replaceTerms = array_key_exists('tagIds', $input) || array_key_exists('badgeIds', $input);
        $kept = $current === null ? ['tag' => [], 'badge' => []] : $this->currentLinks((int) $current['id']);
        $tagIds = array_key_exists('tagIds', $input) ? Input::idList($input['tagIds']) : $kept['tag'];
        $badgeIds = array_key_exists('badgeIds', $input) ? Input::idList($input['badgeIds']) : $kept['badge'];
        if (array_key_exists('tagIds', $input) && $tagIds === null) {
            $errors['tagIds'] = 'Tags must be a list of records.';
            $tagIds = [];
        }
        if (array_key_exists('badgeIds', $input) && $badgeIds === null) {
            $errors['badgeIds'] = 'Badges must be a list of records.';
            $badgeIds = [];
        }
        $scheme = Input::scheme($input['scheme'] ?? '');
        if ($scheme['text'] !== null && mb_strlen($scheme['text']) > 50) {
            $errors['scheme'] = 'Scheme must be 50 characters or fewer.';
        }
        // Product List money fields are DECIMAL(12,2): at most 10 digits before the point.
        foreach (['mrp' => [$mrp, 'MRP'], 'ptr' => [$ptr, 'PTR'], 'pts' => [$pts, 'PTS'], 'netRate' => [$netRate, 'Net rate']] as $field => [$amount, $label]) {
            if (array_key_exists($field, $input) && $amount !== null && !isset($errors[$field]) && (float) $amount > 9999999999.99) {
                $errors[$field] = "{$label} is too large.";
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if (array_key_exists('sku', $input) && $code !== null && $this->records->activeCodeTaken($code, $current !== null ? (int) $current['id'] : null)) {
            throw HttpException::validation(['sku' => 'An active product already uses this SKU.']);
        }
        foreach (['type' => [$productTypeId, 'productTypeId'], 'group' => [$productGroupId, 'productGroupId']] as $kind => [$refId, $field]) {
            if ($refId === null) {
                continue;
            }
            $ref = (new ProductCategoryRepository($kind))->find($refId);
            $unchanged = $current !== null && (int) ($current[$kind === 'type' ? 'product_type_id' : 'product_group_id'] ?? 0) === $refId;
            if ($ref === null || ($ref['deleted_at'] !== null && !$unchanged)) {
                throw HttpException::validation([$field => $kind === 'type' ? 'Choose a product type from the list.' : 'Choose a product group from the list.']);
            }
        }

        $lookupIds = array_values(array_filter([$groupId, $categoryId, ...$tagIds, ...$badgeIds], static fn ($id) => $id !== null));
        $terms = $this->records->termsById($lookupIds);
        foreach ($tagIds as $id) {
            $term = $terms[$id] ?? null;
            if ($term === null || $term['kind'] !== 'tag' || $term['deleted_at'] !== null) {
                throw HttpException::validation(['tagIds' => 'One of the selected tags does not exist.']);
            }
        }
        foreach ($badgeIds as $id) {
            $term = $terms[$id] ?? null;
            if ($term === null || $term['kind'] !== 'badge' || $term['deleted_at'] !== null) {
                throw HttpException::validation(['badgeIds' => 'One of the selected badges does not exist.']);
            }
        }

        if ($categoryId !== null) {
            $category = $terms[$categoryId] ?? null;
            if ($category === null || $category['kind'] !== 'category' || $category['deleted_at'] !== null) {
                throw HttpException::validation(['categoryId' => 'Choose an active product category.']);
            }
            $parentId = $category['parent_id'] !== null ? (int) $category['parent_id'] : null;
            if ($groupId !== null && $parentId !== null && $parentId !== $groupId) {
                throw HttpException::validation(['categoryId' => 'This product category does not belong to the selected group category.']);
            }
            if ($groupId !== null && $parentId === null) {
                // Category has no group; client sent a group — refuse the mismatch.
                throw HttpException::validation(['categoryId' => 'This product category is not mapped to a group category.']);
            }
        } elseif ($groupId !== null) {
            $group = $terms[$groupId] ?? null;
            if ($group === null || $group['kind'] !== 'group_category' || $group['deleted_at'] !== null) {
                throw HttpException::validation(['groupCategoryId' => 'Choose an active group category.']);
            }
            // Group alone without a category is ignored for storage (products only store category_id).
        }

        $links = ['tag' => $tagIds, 'badge' => $badgeIds];
        $before = $current === null ? [] : $this->snapshot($current, $kept);
        $after = $this->snapshot([
            'name' => $name,
            'code' => $code,
            'category_id' => $categoryId,
            'product_type_id' => $productTypeId,
            'product_group_id' => $productGroupId,
            'packaging_unit' => $packagingUnit,
            'strip' => $strip,
            'pack' => $pack,
            'mrp' => $mrp,
            'ptr' => $ptr,
            'pts' => $pts,
            'net_rate' => $netRate,
            'scheme' => $scheme['text'],
        ], $links);

        return [
            'name' => $name,
            'code' => $code,
            'categoryId' => $categoryId,
            'productTypeId' => $productTypeId,
            'productGroupId' => $productGroupId,
            'packagingUnit' => $packagingUnit,
            'netRate' => $netRate,
            'strip' => $strip,
            'pack' => $pack,
            'mrp' => $mrp,
            'ptr' => $ptr,
            'pts' => $pts,
            'scheme' => $scheme,
            'tagIds' => $tagIds,
            'badgeIds' => $badgeIds,
            'replaceTerms' => $replaceTerms,
            'changes' => $this->diff($before, $after),
        ];
    }

    /** @return array{tag: list<int>, badge: list<int>} */
    private function currentLinks(int $productId): array
    {
        $links = $this->records->linksByProduct()[$productId] ?? [];

        return ['tag' => $links['tag'] ?? [], 'badge' => $links['badge'] ?? []];
    }

    /**
     * @param array<string, mixed> $product
     * @param array{tag: list<int>, badge: list<int>} $links
     * @return array<string, ?string>
     */
    private function snapshot(array $product, array $links): array
    {
        $categoryId = isset($product['category_id']) && $product['category_id'] !== null && $product['category_id'] !== ''
            ? (int) $product['category_id']
            : 0;
        $terms = $this->records->termsById(array_values(array_filter([$categoryId, ...$links['tag'], ...$links['badge']])));
        $category = $terms[$categoryId] ?? null;
        $groupName = null;
        if ($category !== null && $category['parent_id'] !== null) {
            $groups = $this->records->termsById([(int) $category['parent_id']]);
            $groupName = $groups[(int) $category['parent_id']]['name'] ?? null;
        }

        return [
            'Name' => $product['name'] !== '' ? $product['name'] : null,
            'Code' => ($product['code'] ?? null) !== null && $product['code'] !== '' ? $product['code'] : null,
            'Group category' => $groupName,
            'Category' => $category['name'] ?? null,
            'Product type' => $this->categoryName('type', $product['product_type_id'] ?? null),
            'Product group' => $this->categoryName('group', $product['product_group_id'] ?? null),
            'Packaging unit' => ($product['packaging_unit'] ?? null) !== null && $product['packaging_unit'] !== '' ? $product['packaging_unit'] : null,
            'Strip' => ($product['strip'] ?? null) !== null && $product['strip'] !== '' ? $product['strip'] : null,
            'Pack' => ($product['pack'] ?? null) !== null && $product['pack'] !== '' ? $product['pack'] : null,
            'MRP' => ($product['mrp'] ?? null) !== null && $product['mrp'] !== '' ? (string) $product['mrp'] : null,
            'PTR' => ($product['ptr'] ?? null) !== null && $product['ptr'] !== '' ? (string) $product['ptr'] : null,
            'PTS' => ($product['pts'] ?? null) !== null && $product['pts'] !== '' ? (string) $product['pts'] : null,
            'Net rate' => ($product['net_rate'] ?? null) !== null && $product['net_rate'] !== '' ? number_format((float) $product['net_rate'], 2, '.', '') : null,
            'Scheme' => ($product['scheme'] ?? null) !== null && $product['scheme'] !== '' ? $product['scheme'] : null,
            'Tags' => $this->termList($links['tag'], $terms),
            'Badges' => $this->termList($links['badge'], $terms),
        ];
    }

    /**
     * @param list<int> $ids
     * @param array<int, array<string, mixed>> $terms
     */
    private function termList(array $ids, array $terms): ?string
    {
        $names = [];
        foreach ($ids as $id) {
            $name = $terms[$id]['name'] ?? null;
            if ($name !== null) {
                $names[] = $name;
            }
        }
        usort($names, 'strcasecmp');

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * @param array<string, ?string> $before
     * @param array<string, ?string> $after
     * @return list<array{field: string, from: ?string, to: ?string}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $value) {
            $previous = $before[$field] ?? null;
            if ($previous !== $value) {
                $changes[] = ['field' => $field, 'from' => $previous, 'to' => $value];
            }
        }

        return $changes;
    }

    private function categoryName(string $kind, mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return (new ProductCategoryRepository($kind))->find((int) $id)['name'] ?? null;
    }

    /** @param array<string, string> $errors */
    private function optionalId(mixed $value, string $field, string $label, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = Input::id($value);
        if ($id === null) {
            $errors[$field] = "Choose a {$label}.";
        }

        return $id;
    }
}
