<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Product classification, year-specific commercials, and the product-level scheme. */
final class ProductModuleTest extends FeatureTestCase
{
    public function testCategoryMustBelongToTheSelectedGroup(): void
    {
        $admin = $this->signedInAs('admin', 'admin-class@example.test');
        self::assertError($admin->request('POST', '/api/catalogue-terms', ['kind' => 'category', 'name' => 'Loose']), 422, 'validation_failed');

        $group = $this->group($admin, 'Cardiac');
        $other = $this->group($admin, 'Derma');
        $category = $this->category($admin, 'Tablets', $group);
        $foreign = $this->category($admin, 'Creams', $other);

        self::assertError($admin->request('POST', '/api/products', $this->productBody('Bad', 'BAD', $group, $foreign)), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/products', [
            'name' => 'No class', 'code' => '', 'groupCategoryId' => null, 'categoryId' => null, 'scheme' => '',
        ]), 422, 'validation_failed');

        $created = $admin->request('POST', '/api/products', $this->productBody('Good', 'GOOD', $group, $category, '10+5'));
        self::assertSame(201, $created['status'], $created['raw']);
        self::assertSame('10+5', $created['body']['data']['product']['scheme']);
        self::assertSame($category, $created['body']['data']['product']['categoryId']);

        $edited = $admin->request('PATCH', '/api/products/' . $created['body']['data']['product']['id'], $this->productBody('Good Plus', 'GOOD', $group, $category, '10+5'));
        self::assertSame(200, $edited['status'], $edited['raw']);
        self::assertSame('Good Plus', $edited['body']['data']['product']['name']);

        self::assertError($admin->request('POST', '/api/catalogue-terms/' . $group . '/delete', []), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/catalogue-terms/' . $category . '/delete', []), 422, 'validation_failed');
        self::assertSame(200, $admin->request('POST', '/api/products/' . $created['body']['data']['product']['id'] . '/delete', [])['status']);
        self::assertSame(200, $admin->request('POST', '/api/catalogue-terms/' . $category . '/delete', [])['status']);
        self::assertSame(200, $admin->request('POST', '/api/catalogue-terms/' . $group . '/delete', [])['status']);
    }

    public function testLegacyCategoryIsNotGuessedAndMustBeMapped(): void
    {
        $admin = $this->signedInAs('admin', 'admin-legacy@example.test');
        $group = $this->group($admin, 'Cardiac');
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO catalogue_terms (kind, name) VALUES (?, ?)')->execute(['category', 'Legacy']);
        $legacyId = (string) $pdo->lastInsertId();
        self::assertNull($pdo->query('SELECT parent_id FROM catalogue_terms WHERE name = \'Legacy\'')->fetchColumn());

        self::assertError($admin->request('POST', '/api/products', $this->productBody('Old', '', $group, $legacyId)), 422, 'validation_failed');
        $mapped = $admin->request('PATCH', '/api/catalogue-terms/' . $legacyId, ['name' => 'Legacy', 'parentId' => $group]);
        self::assertSame(200, $mapped['status'], $mapped['raw']);
        self::assertSame($group, $mapped['body']['data']['term']['parentId']);
        self::assertSame(201, $admin->request('POST', '/api/products', $this->productBody('Old', 'OLD', $group, $legacyId))['status']);
    }

    public function testYearCommercialsStaySeparateAndInclusionCanChange(): void
    {
        $admin = $this->signedInAs('admin', 'admin-prices@example.test');
        $product = $this->makeProduct($admin, 'ACVIO SP TABLET', 'ACVIO', '10+5');
        $first = $admin->request('PUT', '/api/master-sheet/2024-2025/' . $product, [
            'inclusion' => 'included', 'stripPack' => '1x10', 'mrp' => 90, 'ptr' => 68, 'pts' => 60,
        ]);
        self::assertSame(200, $first['status'], $first['raw']);
        $second = $admin->request('PUT', '/api/master-sheet/2026-2027/' . $product, [
            'inclusion' => 'excluded', 'stripPack' => '1x10', 'mrp' => '102', 'ptr' => '77.71', 'pts' => '69.94',
        ]);
        self::assertSame(200, $second['status'], $second['raw']);
        $changed = $admin->request('PUT', '/api/master-sheet/2026-2027/' . $product, [
            'inclusion' => 'included', 'stripPack' => '1x10', 'mrp' => '102', 'ptr' => '77.71', 'pts' => '69.94',
        ]);
        self::assertSame(200, $changed['status'], $changed['raw']);

        $sheet = $admin->request('GET', '/api/erp')['body']['data']['masterSheet'];
        self::assertSame('included', $sheet['2024-2025'][$product]['inclusion']);
        self::assertSame('90.00', $sheet['2024-2025'][$product]['mrp']);
        self::assertSame('68.00', $sheet['2024-2025'][$product]['ptr']);
        self::assertSame('60.00', $sheet['2024-2025'][$product]['pts']);
        self::assertSame('1x10', $sheet['2024-2025'][$product]['stripPack']);
        self::assertSame('included', $sheet['2026-2027'][$product]['inclusion']);
        self::assertSame('77.71', $sheet['2026-2027'][$product]['ptr']);
        self::assertSame('69.94', $sheet['2026-2027'][$product]['pts']);
        self::assertError($admin->request('PUT', '/api/master-sheet/2026-2027/' . $product, [
            'inclusion' => 'included', 'mrp' => -1,
        ]), 422, 'validation_failed');
        self::assertSame('102.00', $admin->request('GET', '/api/erp')['body']['data']['masterSheet']['2026-2027'][$product]['mrp']);
    }

    public function testSchemeIsProductLevelAndHistoryIsNotDuplicated(): void
    {
        $admin = $this->signedInAs('admin', 'admin-scheme@example.test');
        $product = $this->makeProduct($admin, 'Scheme Product', 'SCH', '10+5');
        $saved = $admin->request('PUT', '/api/master-sheet/2026-2027/' . $product, [
            'inclusion' => 'included', 'scheme' => '10+7', 'stripPack' => '1x10', 'mrp' => '10',
        ]);
        self::assertSame(200, $saved['status'], $saved['raw']);

        $state = $admin->request('GET', '/api/erp')['body']['data'];
        self::assertSame('10+7', $state['products'][0]['scheme']);
        $pdo = Database::connection();
        $stored = $pdo->prepare('SELECT scheme, scheme_buy_qty, scheme_free_qty FROM products WHERE id = ?');
        $stored->execute([(int) $product]);
        $row = $stored->fetch();
        self::assertSame('10+7', $row['scheme']);
        self::assertSame(10, (int) $row['scheme_buy_qty']);
        self::assertSame(7, (int) $row['scheme_free_qty']);
        $yearScheme = $pdo->prepare(
            'SELECT e.scheme FROM master_sheet_entries e JOIN financial_years y ON y.id = e.year_id WHERE e.product_id = ? AND y.label = ?'
        );
        $yearScheme->execute([(int) $product, '2026-2027']);
        self::assertNull($yearScheme->fetchColumn());

        $schemeRows = [];
        foreach ($state['productHistory'] as $entry) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'Scheme' && $change['to'] === '10+7') {
                    $schemeRows[] = $entry;
                }
            }
        }
        self::assertCount(1, $schemeRows);
        self::assertSame('10+5', $schemeRows[0]['changes'][0]['from']);
        self::assertSame('Admin', $schemeRows[0]['by']);
        self::assertNotNull($schemeRows[0]['at']);
        foreach ($state['masterSheetHistory'] as $entry) {
            self::assertNotSame('Scheme', $entry['field']);
        }

        $again = $admin->request('PATCH', '/api/products/' . $product, $this->productBody('Scheme Product', 'SCH', $this->groupId($admin), $this->categoryId($admin), '10+7'));
        self::assertSame(200, $again['status'], $again['raw']);
        $after = $admin->request('GET', '/api/products')['body']['data']['productHistory'];
        $stillOne = 0;
        foreach ($after as $entry) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'Scheme' && $change['to'] === '10+7') {
                    $stillOne++;
                }
            }
        }
        self::assertSame(1, $stillOne);
    }

    public function testUpdateProductsAndScanDoNotOverwriteOrInsertEarly(): void
    {
        $admin = $this->signedInAs('admin', 'admin-scan@example.test');
        $first = $this->makeProduct($admin, 'One', 'ONE', '');
        $second = $this->makeProduct($admin, 'Two', 'TWO', '');
        $pdo = Database::connection();
        $count = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM master_sheet_entries')->fetchColumn();
        $before = $count();

        $scan = $admin->request('GET', '/api/master-sheet/2026-2027/scan');
        self::assertSame(200, $scan['status'], $scan['raw']);
        self::assertCount(2, $scan['body']['data']['missing']);
        self::assertSame($before, $count());

        $added = $admin->request('POST', '/api/master-sheet/2026-2027/add-products', ['productIds' => [$first]]);
        self::assertSame(200, $added['status'], $added['raw']);
        self::assertSame(1, $added['body']['data']['added']);
        self::assertSame(0, $added['body']['data']['failed']);
        self::assertSame($before + 1, $count());
        $kept = $admin->request('GET', '/api/erp')['body']['data']['masterSheet']['2026-2027'][$first];
        self::assertNull($kept['inclusion']);
        self::assertNull($kept['mrp']);

        $admin->request('PUT', '/api/master-sheet/2026-2027/' . $first, [
            'inclusion' => 'excluded', 'stripPack' => '1x10', 'mrp' => '90', 'ptr' => '68', 'pts' => '60',
        ]);
        $rest = $admin->request('POST', '/api/master-sheet/2026-2027/update-products', []);
        self::assertSame(1, $rest['body']['data']['added']);
        self::assertSame(1, $rest['body']['data']['skipped']);
        self::assertSame(0, $rest['body']['data']['failed']);
        $repeat = $admin->request('POST', '/api/master-sheet/2026-2027/update-products', []);
        self::assertSame(0, $repeat['body']['data']['added']);
        self::assertSame(2, $repeat['body']['data']['skipped']);
        $sheet = $admin->request('GET', '/api/erp')['body']['data']['masterSheet']['2026-2027'];
        self::assertSame('excluded', $sheet[$first]['inclusion']);
        self::assertSame('90.00', $sheet[$first]['mrp']);
        self::assertSame('1x10', $sheet[$first]['stripPack']);
        self::assertNull($sheet[$second]['inclusion']);
        self::assertArrayNotHasKey($first, $admin->request('GET', '/api/erp')['body']['data']['masterSheet']['2025-2026'] ?? []);

        $bad = $admin->request('POST', '/api/master-sheet/2026-2027/add-products', ['productIds' => ['999999']]);
        self::assertSame(1, $bad['body']['data']['failed']);
        self::assertSame(0, $bad['body']['data']['added']);
    }

    public function testConflictingYearSchemesAreNotCopiedOntoTheProduct(): void
    {
        $admin = $this->signedInAs('admin', 'admin-conflict@example.test');
        $product = $this->makeProduct($admin, 'Conflict', 'CON', '');
        $pdo = Database::connection();
        $pdo->prepare('UPDATE products SET scheme = NULL, scheme_buy_qty = NULL, scheme_free_qty = NULL WHERE id = ?')->execute([(int) $product]);
        $years = $pdo->query('SELECT label, id FROM financial_years')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $insert = $pdo->prepare(
            'INSERT INTO master_sheet_entries (year_id, product_id, inclusion, scheme, scheme_buy_qty, scheme_free_qty) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([(int) $years['2024-2025'], (int) $product, 'included', '10+5', 10, 5]);
        $insert->execute([(int) $years['2025-2026'], (int) $product, 'included', '10+7', 10, 7]);
        $pdo->exec(
            'UPDATE products AS p
             INNER JOIN (
                 SELECT product_id, MIN(scheme) AS scheme, MIN(scheme_buy_qty) AS scheme_buy_qty, MIN(scheme_free_qty) AS scheme_free_qty
                 FROM master_sheet_entries
                 WHERE scheme IS NOT NULL AND scheme <> \'\'
                 GROUP BY product_id
                 HAVING COUNT(DISTINCT scheme) = 1
             ) AS agreed ON agreed.product_id = p.id
             SET p.scheme = agreed.scheme, p.scheme_buy_qty = agreed.scheme_buy_qty, p.scheme_free_qty = agreed.scheme_free_qty
             WHERE p.scheme IS NULL'
        );
        $scheme = $pdo->prepare('SELECT scheme FROM products WHERE id = ?');
        $scheme->execute([(int) $product]);
        self::assertNull($scheme->fetchColumn());
        $kept = $pdo->prepare('SELECT COUNT(DISTINCT scheme) FROM master_sheet_entries WHERE product_id = ? AND scheme IS NOT NULL');
        $kept->execute([(int) $product]);
        self::assertSame(2, (int) $kept->fetchColumn());
    }

    public function testSalesUserCannotChangeProductsOrTheSheet(): void
    {
        $admin = $this->signedInAs('admin', 'admin-guard@example.test');
        $product = $this->makeProduct($admin, 'Visible', 'VIS', '');
        $sales = $this->signedInAs('sales_user', 'sales-guard@example.test');
        self::assertSame(200, $sales->request('GET', '/api/products')['status']);
        self::assertError($sales->request('POST', '/api/products', ['name' => 'Nope']), 403, 'forbidden');
        self::assertError($sales->request('PUT', '/api/master-sheet/2026-2027/' . $product, ['inclusion' => 'included']), 403, 'forbidden');
        self::assertError($sales->request('POST', '/api/master-sheet/2026-2027/update-products', []), 403, 'forbidden');
        self::assertError($sales->request('GET', '/api/master-sheet/2026-2027/scan'), 403, 'forbidden');
    }

    private function makeProduct(ApiClient $admin, string $name, string $code, string $scheme): string
    {
        $group = $this->group($admin, $name . ' Group');
        $category = $this->category($admin, $name . ' Category', $group);
        $created = $admin->request('POST', '/api/products', $this->productBody($name, $code, $group, $category, $scheme));
        self::assertSame(201, $created['status'], $created['raw']);
        $this->lastGroup = $group;
        $this->lastCategory = $category;

        return $created['body']['data']['product']['id'];
    }

    private string $lastGroup = '';

    private string $lastCategory = '';

    private function groupId(ApiClient $admin): string
    {
        return $this->lastGroup;
    }

    private function categoryId(ApiClient $admin): string
    {
        return $this->lastCategory;
    }

    private function group(ApiClient $admin, string $name): string
    {
        $created = $admin->request('POST', '/api/catalogue-terms', ['kind' => 'groupCategory', 'name' => $name]);
        self::assertSame(201, $created['status'], $created['raw']);

        return $created['body']['data']['term']['id'];
    }

    private function category(ApiClient $admin, string $name, string $groupId): string
    {
        $created = $admin->request('POST', '/api/catalogue-terms', ['kind' => 'category', 'name' => $name, 'parentId' => $groupId]);
        self::assertSame(201, $created['status'], $created['raw']);

        return $created['body']['data']['term']['id'];
    }

    /** @return array<string, string> */
    private function productBody(string $name, string $code, string $groupId, string $categoryId, string $scheme = ''): array
    {
        return [
            'name' => $name,
            'code' => $code,
            'groupCategoryId' => $groupId,
            'categoryId' => $categoryId,
            'scheme' => $scheme,
        ];
    }
}
