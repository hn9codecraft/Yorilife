<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MasterSheetRepository;
use App\Repositories\ProductRepository;
use App\Utils\HttpException;

final class MasterSheetService
{
    public function __construct(
        private readonly MasterSheetRepository $sheets = new MasterSheetRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
    ) {
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function createYear(array $actor, array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['label'], $errors);
        $year = Input::financialYear($input['label'] ?? null);
        if ($year === null) {
            $errors['label'] = 'Enter a financial year as YYYY-YYYY, running from April to March (for example 2027-2028).';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $this->sheets->insertYear([
            'label' => $year['label'],
            'starts_on' => $year['startsOn'],
            'ends_on' => $year['endsOn'],
            'created_by' => (int) $actor['id'],
        ]);

        return ['label' => $year['label'], 'startsOn' => $year['startsOn'], 'endsOn' => $year['endsOn']];
    }

    /**
     * Active products that have no row for this year. Does not insert anything.
     *
     * @return array{missing: list<array<string, mixed>>}
     */
    public function scan(string $yearLabel): array
    {
        $year = $this->sheets->findYear($yearLabel) ?? throw HttpException::notFound('Financial year not found.');
        $existing = array_fill_keys($this->sheets->productIdsForYear((int) $year['id']), true);
        $missingProducts = [];
        foreach ($this->products->all() as $product) {
            if ($product['deleted_at'] === null && !isset($existing[(int) $product['id']])) {
                $missingProducts[] = $product;
            }
        }

        return ['missing' => $this->describe($missingProducts)];
    }

    /**
     * Add every active product that is missing from the year. Existing rows are left as they are.
     *
     * @param array<string, mixed> $actor
     * @return array{added: int, skipped: int, failed: int}
     */
    public function updateProducts(array $actor, string $yearLabel): array
    {
        return $this->addMissing($actor, $yearLabel, null);
    }

    /**
     * Add only the products the admin confirmed after a scan.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @return array{added: int, skipped: int, failed: int}
     */
    public function addSelected(array $actor, string $yearLabel, array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['productIds'], $errors);
        $ids = Input::idList($input['productIds'] ?? null);
        if ($ids === null) {
            $errors['productIds'] = 'Choose products from the scan results.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $this->addMissing($actor, $yearLabel, $ids);
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function saveEntry(array $actor, string $yearLabel, int $productId, array $input): void
    {
        $year = $this->sheets->findYear($yearLabel) ?? throw HttpException::notFound('Financial year not found.');
        $product = $this->products->find($productId) ?? throw HttpException::notFound('Product not found.');
        if ($product['deleted_at'] !== null) {
            throw HttpException::validation(['inclusion' => 'Deleted products cannot be updated on the Master Sheet.']);
        }
        $errors = [];
        Input::onlyFields($input, ['inclusion', 'stripPack', 'mrp', 'ptr', 'pts', 'scheme'], $errors);
        $previous = $this->sheets->findEntry((int) $year['id'], $productId);
        $inclusion = $this->inclusion($input, $previous['inclusion'] ?? null, $errors);
        $stripPack = array_key_exists('stripPack', $input)
            ? Input::optionalText($input, 'stripPack', 'strip pack', 50, $errors)
            : ($previous['strip_pack'] ?? null);
        $mrp = $this->price($input, 'mrp', 'MRP', $previous['mrp'] ?? null, $errors);
        $ptr = $this->price($input, 'ptr', 'PTR', $previous['ptr'] ?? null, $errors);
        $pts = $this->price($input, 'pts', 'PTS', $previous['pts'] ?? null, $errors);
        $scheme = array_key_exists('scheme', $input) ? Input::scheme($input['scheme']) : null;
        if ($scheme !== null && $scheme['text'] !== null && mb_strlen($scheme['text']) > 50) {
            $errors['scheme'] = 'Scheme must be 50 characters or fewer.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $fields = [
            'inclusion' => $inclusion,
            'strip_pack' => $stripPack,
            'mrp' => $mrp,
            'ptr' => $ptr,
            'pts' => $pts,
            'updated_by' => (int) $actor['id'],
        ];
        $sheetChanged = $previous === null
            ? ($inclusion !== null || $stripPack !== null || $mrp !== null || $ptr !== null || $pts !== null)
            : !$this->sameSheet($previous, $fields);
        $schemeChanged = $scheme !== null && ($product['scheme'] ?? null) !== $scheme['text'];

        if (!$sheetChanged && !$schemeChanged) {
            return;
        }

        $this->sheets->transaction(function () use ($previous, $year, $productId, $fields, $sheetChanged, $schemeChanged, $scheme, $product, $actor, $inclusion): void {
            if ($sheetChanged) {
                if ($previous === null) {
                    $this->sheets->insertEntry($fields + ['year_id' => (int) $year['id'], 'product_id' => $productId]);
                } else {
                    $this->sheets->updateEntry((int) $previous['id'], $fields);
                }
                if (($previous['inclusion'] ?? null) !== $inclusion) {
                    $this->sheets->addChanges([[
                        'year_id' => (int) $year['id'],
                        'product_id' => $productId,
                        'product_name' => $product['name'],
                        'field' => 'Inclusion',
                        'old_value' => $previous['inclusion'] ?? null,
                        'new_value' => $inclusion,
                        'changed_by' => (int) $actor['id'],
                    ]]);
                }
            }
            if ($schemeChanged && $scheme !== null) {
                $this->products->update($productId, [
                    'scheme' => $scheme['text'],
                    'scheme_buy_qty' => $scheme['buy'],
                    'scheme_free_qty' => $scheme['free'],
                ]);
                $this->products->addChange($productId, $product['name'], 'updated', [[
                    'field' => 'Scheme',
                    'from' => $product['scheme'] ?? null,
                    'to' => $scheme['text'],
                ]], (int) $actor['id']);
            }
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @param list<int>|null $onlyIds null adds every missing active product
     * @return array{added: int, skipped: int, failed: int}
     */
    private function addMissing(array $actor, string $yearLabel, ?array $onlyIds): array
    {
        $year = $this->sheets->findYear($yearLabel) ?? throw HttpException::notFound('Financial year not found.');
        $yearId = (int) $year['id'];
        $existing = array_fill_keys($this->sheets->productIdsForYear($yearId), true);
        $active = [];
        foreach ($this->products->all() as $product) {
            if ($product['deleted_at'] === null) {
                $active[(int) $product['id']] = true;
            }
        }
        $targets = $onlyIds ?? array_keys($active);
        $added = 0;
        $skipped = 0;
        $failed = 0;
        $this->sheets->transaction(function () use ($actor, $yearId, &$existing, $active, $targets, &$added, &$skipped, &$failed): void {
            foreach ($targets as $id) {
                if (!isset($active[$id])) {
                    $failed++;
                    continue;
                }
                if (isset($existing[$id])) {
                    $skipped++;
                    continue;
                }
                $this->sheets->insertEntry([
                    'year_id' => $yearId,
                    'product_id' => $id,
                    'inclusion' => null,
                    'updated_by' => (int) $actor['id'],
                ]);
                $existing[$id] = true;
                $added++;
            }
        });

        return ['added' => $added, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function describe(array $products): array
    {
        $categoryIds = [];
        foreach ($products as $product) {
            if ($product['category_id'] !== null) {
                $categoryIds[] = (int) $product['category_id'];
            }
        }
        $categories = $this->products->termsById($categoryIds);
        $parentIds = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] !== null) {
                $parentIds[] = (int) $category['parent_id'];
            }
        }
        $groups = $this->products->termsById($parentIds);
        $rows = [];
        foreach ($products as $product) {
            $category = $product['category_id'] !== null ? ($categories[(int) $product['category_id']] ?? null) : null;
            $group = $category !== null && $category['parent_id'] !== null ? ($groups[(int) $category['parent_id']] ?? null) : null;
            $rows[] = [
                'id' => Presenter::id($product['id']),
                'name' => $product['name'],
                'code' => $product['code'],
                'groupCategory' => $group['name'] ?? null,
                'category' => $category['name'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function inclusion(array $input, ?string $previous, array &$errors): ?string
    {
        if (!array_key_exists('inclusion', $input) || $input['inclusion'] === null || $input['inclusion'] === '') {
            return $previous;
        }
        if (!in_array($input['inclusion'], ['included', 'excluded'], true)) {
            $errors['inclusion'] = 'Choose Included or Excluded.';

            return $previous;
        }

        return $input['inclusion'];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function price(array $input, string $field, string $label, mixed $previous, array &$errors): ?string
    {
        if (!array_key_exists($field, $input)) {
            return $this->storedMoney($previous);
        }

        return Input::money($input, $field, $label, $errors);
    }

    /** @param array<string, mixed> $previous @param array<string, mixed> $fields */
    private function sameSheet(array $previous, array $fields): bool
    {
        return ($previous['inclusion'] ?? null) === $fields['inclusion']
            && ($previous['strip_pack'] ?? null) === $fields['strip_pack']
            && $this->storedMoney($previous['mrp'] ?? null) === $fields['mrp']
            && $this->storedMoney($previous['ptr'] ?? null) === $fields['ptr']
            && $this->storedMoney($previous['pts'] ?? null) === $fields['pts'];
    }

    private function storedMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
