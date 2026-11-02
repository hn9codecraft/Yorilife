<?php

declare(strict_types=1);

namespace App\Services;

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
                'scheme' => $parsed['scheme']['text'],
                'scheme_buy_qty' => $parsed['scheme']['buy'],
                'scheme_free_qty' => $parsed['scheme']['free'],
            ]);
            if ($parsed['replaceTerms']) {
                $this->records->replaceTerms($id, [...$parsed['tagIds'], ...$parsed['badgeIds']]);
            }
            $this->records->addChange($id, $parsed['name'], 'created', $parsed['changes'], (int) $actor['id']);

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
                'scheme' => $parsed['scheme']['text'],
                'scheme_buy_qty' => $parsed['scheme']['buy'],
                'scheme_free_qty' => $parsed['scheme']['free'],
            ]);
            if ($parsed['replaceTerms']) {
                $this->records->replaceTerms($id, [...$parsed['tagIds'], ...$parsed['badgeIds']]);
            }
            $this->records->addChange($id, $parsed['name'], 'updated', $parsed['changes'], (int) $actor['id']);
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
     * @return array{name: string, code: ?string, categoryId: int, scheme: array{text: ?string, buy: ?int, free: ?int}, changes: list<array{field: string, from: ?string, to: ?string}>}
     */
    private function parse(array $input, ?array $current): array
    {
        $errors = [];
        Input::onlyFields($input, ['name', 'code', 'groupCategoryId', 'categoryId', 'scheme', 'tagIds', 'badgeIds'], $errors);
        $name = Input::text($input, 'name', 'product name', 200, $errors);
        $code = Input::optionalText($input, 'code', 'product code', 50, $errors);
        $groupId = $this->requiredId($input['groupCategoryId'] ?? null, 'groupCategoryId', 'group category', $errors);
        $categoryId = $this->requiredId($input['categoryId'] ?? null, 'categoryId', 'product category', $errors);
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
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $terms = $this->records->termsById([$groupId, $categoryId, ...$tagIds, ...$badgeIds]);
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
        $group = $terms[$groupId] ?? null;
        $category = $terms[$categoryId] ?? null;
        if ($group === null || $group['kind'] !== 'group_category' || $group['deleted_at'] !== null) {
            throw HttpException::validation(['groupCategoryId' => 'Choose an active group category.']);
        }
        if ($category === null || $category['kind'] !== 'category' || $category['deleted_at'] !== null) {
            throw HttpException::validation(['categoryId' => 'Choose an active product category.']);
        }
        $parentId = $category['parent_id'] !== null ? (int) $category['parent_id'] : null;
        if ($parentId === null) {
            throw HttpException::validation(['categoryId' => 'This product category is not mapped to a group category.']);
        }
        if ($parentId !== $groupId) {
            throw HttpException::validation(['categoryId' => 'This product category does not belong to the selected group category.']);
        }

        $links = ['tag' => $tagIds, 'badge' => $badgeIds];
        $before = $current === null ? [] : $this->snapshot($current, $kept);
        $after = $this->snapshot([
            'name' => $name,
            'code' => $code,
            'category_id' => $categoryId,
            'scheme' => $scheme['text'],
        ], $links);

        return [
            'name' => $name,
            'code' => $code,
            'categoryId' => $categoryId,
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
        $categoryId = isset($product['category_id']) ? (int) $product['category_id'] : 0;
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

    /** @param array<string, string> $errors */
    private function requiredId(mixed $value, string $field, string $label, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            $errors[$field] = "Choose a {$label}.";

            return null;
        }
        $id = Input::id($value);
        if ($id === null) {
            $errors[$field] = "Choose a {$label}.";
        }

        return $id;
    }
}
