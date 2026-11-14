<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use Tests\Support\FeatureTestCase;

/** Sprint 4 persistence: office, catalogue, products, master sheet and sales. */
final class BusinessDataTest extends FeatureTestCase
{
    public function testSalesUserCanViewButNotChangeBusinessData(): void
    {
        $admin = $this->signedInAs('admin', 'admin-office@example.test');
        $created = $admin->request('POST', '/api/headquarters', $this->hq('North', 'N', $this->managerChain($admin)));
        self::assertSame(201, $created['status'], $created['raw']);

        $sales = $this->signedInAs('sales_user');
        $state = $sales->request('GET', '/api/erp');
        self::assertSame(200, $state['status'], $state['raw']);
        self::assertSame('North', $state['body']['data']['headquarters'][0]['name']);
        self::assertError($sales->request('POST', '/api/headquarters', ['name' => 'South']), 403, 'forbidden');
    }

    public function testManagerHierarchyAndOptionalUniqueCodes(): void
    {
        $admin = $this->signedInAs('admin', 'admin-hierarchy@example.test');
        $third = $admin->request('POST', '/api/managers', ['name' => 'Top', 'code' => '', 'level' => 3]);
        self::assertSame(201, $third['status'], $third['raw']);
        $thirdId = $third['body']['data']['manager']['id'];
        self::assertNull($third['body']['data']['manager']['parentId']);
        $second = $admin->request('POST', '/api/managers', [
            'name' => 'Ravi', 'code' => null, 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
        ]);
        self::assertSame(201, $second['status'], $second['raw']);
        $secondAgain = $admin->request('POST', '/api/managers', [
            'name' => 'Ravi Two', 'code' => '', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
        ]);
        self::assertSame(201, $secondAgain['status'], $secondAgain['raw']);
        $first = $admin->request('POST', '/api/managers', [
            'name' => 'Asha', 'code' => '', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $second['body']['data']['manager']['id'],
        ]);
        self::assertSame(201, $first['status'], $first['raw']);
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Wrong', 'level' => 2, 'parentType' => 'manager1', 'parentId' => $first['body']['data']['manager']['id'],
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Skipped', 'level' => 1, 'parentType' => 'manager3', 'parentId' => $thirdId,
        ]), 422, 'validation_failed');

        $parentId = $first['body']['data']['manager']['id'];
        $hq = $admin->request('POST', '/api/headquarters', $this->hq('Pune', '', $parentId))['body']['data']['headquarters'];
        $again = $admin->request('POST', '/api/headquarters', $this->hq('Pune', '', $parentId));
        self::assertSame(201, $again['status'], $again['raw']);

        self::assertSame(201, $admin->request('POST', '/api/headquarters', $this->hq('West', 'PUN', $parentId))['status']);
        self::assertError($admin->request('POST', '/api/headquarters', $this->hq('Other', 'PUN', $parentId)), 422, 'validation_failed');

        $stockist = $admin->request('POST', '/api/stockists', ['name' => 'Medico', 'code' => '', 'headquartersId' => $hq['id']]);
        self::assertSame(201, $stockist['status'], $stockist['raw']);
        self::assertArrayNotHasKey('managerId', $stockist['body']['data']['stockist']);
    }

    public function testCatalogueDeleteUnlinksProductsAndClearsTermLinks(): void
    {
        $admin = $this->signedInAs('admin', 'admin-catalogue@example.test');
        $group = $admin->request('POST', '/api/catalogue-terms', ['kind' => 'groupCategory', 'name' => 'General'])['body']['data']['term'];
        $category = $admin->request('POST', '/api/catalogue-terms', [
            'kind' => 'category', 'name' => 'Tablets', 'parentId' => $group['id'],
        ])['body']['data']['term'];
        $tag = $admin->request('POST', '/api/catalogue-terms', ['kind' => 'tag', 'name' => 'New'])['body']['data']['term'];
        $badge = $admin->request('POST', '/api/catalogue-terms', ['kind' => 'badge', 'name' => 'Focus'])['body']['data']['term'];
        $product = $admin->request('POST', '/api/products', [
            'name' => 'Yori-T',
            'code' => 'YT',
            'groupCategoryId' => $group['id'],
            'categoryId' => $category['id'],
            'tagIds' => [$tag['id']],
            'badgeIds' => [$badge['id']],
            'scheme' => '',
        ]);
        self::assertSame(201, $product['status'], $product['raw']);
        self::assertSame([$tag['id']], $product['body']['data']['product']['tagIds']);
        self::assertSame([$badge['id']], $product['body']['data']['product']['badgeIds']);

        $productId = $product['body']['data']['product']['id'];
        self::assertSame(200, $admin->request('POST', '/api/catalogue-terms/' . $tag['id'] . '/delete', [])['status']);
        self::assertSame(200, $admin->request('POST', '/api/catalogue-terms/' . $badge['id'] . '/delete', [])['status']);
        self::assertSame(200, $admin->request('POST', '/api/catalogue-terms/' . $category['id'] . '/delete', [])['status']);

        $after = $admin->request('GET', '/api/products')['body']['data']['products'][0];
        self::assertSame($productId, $after['id']);
        self::assertNull($after['categoryId']);
        self::assertSame([], $after['tagIds']);
        self::assertSame([], $after['badgeIds']);
        self::assertNull($after['deletedAt']);

        self::assertSame(200, $admin->request('POST', '/api/catalogue-terms/' . $group['id'] . '/delete', [])['status']);
    }

    public function testProductCodeIsUniqueOnlyWhileActiveAndHistoryNamesTheUser(): void
    {
        $admin = $this->signedInAs('admin', 'admin-products@example.test');
        $group = $admin->request('POST', '/api/catalogue-terms', ['kind' => 'groupCategory', 'name' => 'General'])['body']['data']['term'];
        $category = $admin->request('POST', '/api/catalogue-terms', [
            'kind' => 'category', 'name' => 'Tablets', 'parentId' => $group['id'],
        ])['body']['data']['term'];
        $body = ['groupCategoryId' => $group['id'], 'categoryId' => $category['id'], 'tagIds' => [], 'badgeIds' => [], 'scheme' => ''];
        $first = $admin->request('POST', '/api/products', $body + ['name' => 'One', 'code' => 'SAME']);
        $second = $admin->request('POST', '/api/products', $body + ['name' => 'Two', 'code' => 'SAME']);
        self::assertSame(201, $first['status'], $first['raw']);
        self::assertSame([], $first['body']['data']['product']['tagIds']);
        self::assertSame([], $first['body']['data']['product']['badgeIds']);
        self::assertError($second, 422, 'validation_failed');

        $id = $first['body']['data']['product']['id'];
        self::assertSame(200, $admin->request('POST', "/api/products/{$id}/delete", [])['status']);
        $reuse = $admin->request('POST', '/api/products', $body + ['name' => 'Two', 'code' => 'SAME']);
        self::assertSame(201, $reuse['status'], $reuse['raw']);
        self::assertError($admin->request('POST', "/api/products/{$id}/restore", []), 422, 'validation_failed');

        $history = $admin->request('GET', '/api/products')['body']['data']['productHistory'];
        self::assertSame('Admin', $history[0]['by']);
        self::assertNotNull($history[0]['by']);
    }

    public function testFinancialYearsStaySeparateAndSchemeTextIsStoredWithQuantities(): void
    {
        $admin = $this->signedInAs('admin', 'admin-sheet@example.test');
        $group = $admin->request('POST', '/api/catalogue-terms', ['kind' => 'groupCategory', 'name' => 'General'])['body']['data']['term'];
        $category = $admin->request('POST', '/api/catalogue-terms', [
            'kind' => 'category', 'name' => 'Tablets', 'parentId' => $group['id'],
        ])['body']['data']['term'];
        $product = $admin->request('POST', '/api/products', [
            'name' => 'Sheeted', 'code' => '', 'groupCategoryId' => $group['id'], 'categoryId' => $category['id'], 'scheme' => '10+5',
        ])['body']['data']['product'];
        $created = $admin->request('POST', '/api/financial-years', ['label' => '2027-2028']);
        self::assertSame(201, $created['status'], $created['raw']);
        self::assertError($admin->request('POST', '/api/financial-years', ['label' => '2027-2029']), 422, 'validation_failed');

        $save = $admin->request('PUT', '/api/master-sheet/2027-2028/' . $product['id'], [
            'inclusion' => 'included', 'stripPack' => '1x10', 'mrp' => '102', 'ptr' => '77.71', 'pts' => '69.94',
        ]);
        self::assertSame(200, $save['status'], $save['raw']);
        $other = $admin->request('PUT', '/api/master-sheet/2026-2027/' . $product['id'], [
            'inclusion' => 'excluded', 'stripPack' => '1x15', 'mrp' => '90', 'ptr' => '68', 'pts' => '60',
        ]);
        self::assertSame(200, $other['status'], $other['raw']);

        $state = $admin->request('GET', '/api/erp')['body']['data'];
        self::assertSame('included', $state['masterSheet']['2027-2028'][$product['id']]['inclusion']);
        self::assertSame('102.00', $state['masterSheet']['2027-2028'][$product['id']]['mrp']);
        self::assertSame('excluded', $state['masterSheet']['2026-2027'][$product['id']]['inclusion']);
        self::assertSame('90.00', $state['masterSheet']['2026-2027'][$product['id']]['mrp']);
        self::assertSame('1x15', $state['masterSheet']['2026-2027'][$product['id']]['stripPack']);
        self::assertSame('10+5', $state['products'][0]['scheme']);
        self::assertArrayNotHasKey('scheme', $state['masterSheet']['2027-2028'][$product['id']]);
        self::assertSame('Admin', $state['masterSheetHistory'][0]['by']);
        $stored = \App\Database\Database::connection()->prepare('SELECT scheme_buy_qty, scheme_free_qty FROM products WHERE id = ?');
        $stored->execute([(int) $product['id']]);
        $quantities = $stored->fetch();
        self::assertSame(10, (int) $quantities['scheme_buy_qty']);
        self::assertSame(5, (int) $quantities['scheme_free_qty']);
    }

    /** @return array<string, string> */
    private function managerChain(object $admin): string
    {
        $third = $admin->request('POST', '/api/managers', ['name' => 'Top', 'code' => '', 'level' => 3]);
        self::assertSame(201, $third['status'], $third['raw']);
        $second = $admin->request('POST', '/api/managers', [
            'name' => 'Mid', 'code' => '', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $third['body']['data']['manager']['id'],
        ]);
        self::assertSame(201, $second['status'], $second['raw']);
        $first = $admin->request('POST', '/api/managers', [
            'name' => 'Lead', 'code' => '', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $second['body']['data']['manager']['id'],
        ]);
        self::assertSame(201, $first['status'], $first['raw']);

        return $first['body']['data']['manager']['id'];
    }

    public function testSecondLineManagerEmailAndPhoneAreOptional(): void
    {
        $admin = $this->signedInAs('admin', 'second-contact@example.test');
        $third = $admin->request('POST', '/api/managers', ['name' => 'Shyam', 'code' => 'ER-M3-009', 'level' => 3]);
        self::assertSame(201, $third['status'], $third['raw']);
        $thirdId = $third['body']['data']['manager']['id'];
        $created = $admin->request('POST', '/api/managers', [
            'name' => 'Shubham', 'code' => 'ER-M2-009', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
            'email' => '', 'phone' => '',
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $manager = $created['body']['data']['manager'];
        self::assertNull($manager['email']);
        self::assertNull($manager['phone']);
        self::assertSame($thirdId, $manager['parentId']);

        $updated = $admin->request('PATCH', '/api/managers/' . $manager['id'], [
            'name' => 'Shubham', 'code' => 'ER-M2-009', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
            'email' => '  Shubham@Example.test ', 'phone' => '09000000111',
        ]);
        self::assertSame(200, $updated['status'], $updated['raw']);
        self::assertSame($manager['id'], $updated['body']['data']['manager']['id']);
        self::assertSame('shubham@example.test', $updated['body']['data']['manager']['email']);
        self::assertSame('09000000111', $updated['body']['data']['manager']['phone']);
        self::assertSame($thirdId, $updated['body']['data']['manager']['parentId']);

        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Ali', 'code' => 'ER-M2-010', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
            'email' => 'not-an-email', 'phone' => '',
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Mohit', 'code' => 'ER-M2-011', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
            'email' => '', 'phone' => '123',
        ]), 422, 'validation_failed');
    }

    public function testSecondLineManagerStatesMustBelongToTheThirdLineManager(): void
    {
        $admin = $this->signedInAs('admin', 'second-states@example.test');
        $locations = $admin->request('GET', '/api/locations')['body']['data']['locations'];
        $gujarat = $locations[0]['id'];
        $andhra = $locations[1]['id'];
        $bihar = $locations[4]['id'];
        $third = $admin->request('POST', '/api/managers', [
            'name' => 'Shyam', 'code' => 'ER-M3-019', 'level' => 3, 'stateIds' => [$gujarat, $andhra],
        ]);
        self::assertSame(201, $third['status'], $third['raw']);
        $thirdId = $third['body']['data']['manager']['id'];

        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Shubham', 'code' => 'ER-M2-019', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
            'stateIds' => [],
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Shubham', 'code' => 'ER-M2-019', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
            'stateIds' => [$gujarat, $bihar],
        ]), 422, 'validation_failed');

        $created = $admin->request('POST', '/api/managers', [
            'name' => 'Shubham', 'code' => 'ER-M2-019', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
            'stateIds' => [$andhra, $gujarat],
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        self::assertSame(['Gujarat', 'Andhra Pradesh'], array_column($created['body']['data']['manager']['states'], 'name'));

        $id = $created['body']['data']['manager']['id'];
        $loaded = $admin->request('GET', '/api/erp')['body']['data']['managers'];
        $saved = null;
        foreach ($loaded as $manager) {
            if ($manager['id'] === $id) {
                $saved = $manager;
            }
        }
        self::assertNotNull($saved);
        self::assertSame(['Gujarat', 'Andhra Pradesh'], array_column($saved['states'], 'name'));
    }

    public function testFirstLineManagerChoosesStatesFromTheSecondLineManager(): void
    {
        $admin = $this->signedInAs('admin', 'first-states@example.test');
        $locations = $admin->request('GET', '/api/locations')['body']['data']['locations'];
        $gujarat = $locations[0]['id'];
        $andhra = $locations[1]['id'];
        $bihar = $locations[4]['id'];
        $third = $admin->request('POST', '/api/managers', [
            'name' => 'Shyam', 'code' => 'ER-M3-021', 'level' => 3, 'stateIds' => [$gujarat, $andhra, $bihar],
        ]);
        $thirdId = $third['body']['data']['manager']['id'];
        $second = $admin->request('POST', '/api/managers', [
            'name' => 'Shubham', 'code' => 'ER-M2-021', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
            'stateIds' => [$gujarat, $andhra],
        ]);
        self::assertSame(201, $second['status'], $second['raw']);
        $secondId = $second['body']['data']['manager']['id'];

        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Aarav', 'code' => 'ER-M1-021', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $secondId,
            'stateIds' => [],
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Aarav', 'code' => 'ER-M1-021', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $secondId,
            'stateIds' => [$bihar],
        ]), 422, 'validation_failed');

        $created = $admin->request('POST', '/api/managers', [
            'name' => 'Aarav', 'code' => 'ER-M1-021', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $secondId,
            'stateIds' => [$gujarat], 'email' => 'aarav@example.test', 'phone' => '12452724',
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $manager = $created['body']['data']['manager'];
        self::assertSame(['Gujarat'], array_column($manager['states'], 'name'));
        self::assertSame('aarav@example.test', $manager['email']);
        self::assertSame('12452724', $manager['phone']);

        $empty = $admin->request('POST', '/api/managers', [
            'name' => 'Neha', 'code' => 'ER-M1-022', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $secondId,
            'stateIds' => [$andhra], 'email' => '', 'phone' => '',
        ]);
        self::assertSame(201, $empty['status'], $empty['raw']);
        self::assertNull($empty['body']['data']['manager']['email']);
        self::assertNull($empty['body']['data']['manager']['phone']);
    }

    public function testThirdLineManagerKeepsSeveralStatesAndRejectsADuplicateCode(): void
    {
        $admin = $this->signedInAs('admin', 'admin-states@example.test');
        $locations = $admin->request('GET', '/api/locations')['body']['data']['locations'];
        self::assertSame(
            ['Gujarat', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh'],
            array_column($locations, 'name'),
        );
        self::assertSame(['GUJ', 'AP', 'ARP', 'ASM', 'BIH', 'CG'], array_column($locations, 'code'));
        $gujarat = $locations[0]['id'];
        $andhra = $locations[1]['id'];
        $profile = [
            'email' => 'shyam@example.test',
            'phone' => '9000000101',
        ];
        $created = $admin->request('POST', '/api/managers', $profile + [
            'name' => 'Shyam',
            'code' => 'M3-SHYAM',
            'level' => 3,
            'stateIds' => [$andhra, $gujarat],
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $manager = $created['body']['data']['manager'];
        self::assertSame(['Gujarat', 'Andhra Pradesh'], array_column($manager['states'], 'name'));
        self::assertSame('shyam@example.test', $manager['email']);
        self::assertSame('9000000101', $manager['phone']);
        self::assertNull($manager['parentId']);

        $second = $admin->request('POST', '/api/managers', [
            'name' => 'Shubham', 'code' => 'M2-SHUBHAM', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $manager['id'],
        ]);
        self::assertSame(201, $second['status'], $second['raw']);

        $renamed = $admin->request('PATCH', '/api/locations/' . $gujarat, ['name' => 'Gujarat West', 'code' => 'GUJ']);
        self::assertSame(200, $renamed['status'], $renamed['raw']);

        $updated = $admin->request('PATCH', '/api/managers/' . $manager['id'], $profile + [
            'name' => 'Shyam',
            'code' => 'M3-SHYAM',
            'level' => 3,
            'stateIds' => [$gujarat],
        ]);
        self::assertSame(200, $updated['status'], $updated['raw']);
        self::assertSame($manager['id'], $updated['body']['data']['manager']['id']);
        self::assertSame([['id' => $gujarat, 'name' => 'Gujarat West']], $updated['body']['data']['manager']['states']);
        $listed = $admin->request('GET', '/api/erp')['body']['data']['managers'];
        $shubham = array_values(array_filter($listed, static fn (array $row): bool => $row['name'] === 'Shubham'))[0];
        self::assertSame($manager['id'], $shubham['parentId']);

        self::assertError($admin->request('POST', '/api/locations', ['name' => 'gujarat west', 'code' => 'NEW']), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/locations', ['name' => 'Odisha', 'code' => 'ap']), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/locations/' . $gujarat . '/delete'), 422, 'validation_failed');
        $optional = $admin->request('POST', '/api/managers', [
            'name' => 'Optional Contact', 'code' => 'M3-OPTIONAL', 'level' => 3, 'stateIds' => [$andhra], 'email' => '', 'phone' => '',
        ]);
        self::assertSame(201, $optional['status'], $optional['raw']);
        self::assertNull($optional['body']['data']['manager']['email']);
        self::assertNull($optional['body']['data']['manager']['phone']);
        $kept = $admin->request('PATCH', '/api/managers/' . $optional['body']['data']['manager']['id'], [
            'name' => 'Optional Contact', 'code' => 'M3-OPTIONAL', 'level' => 3, 'stateIds' => [$andhra], 'email' => '  kept@example.test ', 'phone' => '09000000102',
        ]);
        self::assertSame(200, $kept['status'], $kept['raw']);
        self::assertSame('kept@example.test', $kept['body']['data']['manager']['email']);
        self::assertSame('09000000102', $kept['body']['data']['manager']['phone']);
        $removed = $admin->request('POST', '/api/managers/' . $optional['body']['data']['manager']['id'] . '/delete');
        self::assertSame(200, $removed['status'], $removed['raw']);
        self::assertError($admin->request('POST', '/api/managers/' . $manager['id'] . '/delete'), 422, 'validation_failed');
        $first = $admin->request('POST', '/api/managers', [
            'name' => 'Lead', 'code' => 'M1-LEAD', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $second['body']['data']['manager']['id'],
        ]);
        self::assertSame(201, $first['status'], $first['raw']);
        self::assertError($admin->request('POST', '/api/managers/' . $second['body']['data']['manager']['id'] . '/delete'), 422, 'validation_failed');
        $office = $admin->request('POST', '/api/headquarters', $this->hq('West', 'HQ-WEST', $first['body']['data']['manager']['id']));
        self::assertSame(201, $office['status'], $office['raw']);
        self::assertError($admin->request('POST', '/api/managers/' . $first['body']['data']['manager']['id'] . '/delete'), 422, 'validation_failed');
        $stillThere = $admin->request('GET', '/api/erp')['body']['data'];
        self::assertNotFalse(array_search($manager['id'], array_column($stillThere['managers'], 'id'), true));
        self::assertSame($manager['id'], $shubham['parentId']);
        $sequence = $admin->request('POST', '/api/managers', [
            'name' => 'Sequence', 'code' => 'er-m3-001', 'level' => 3, 'stateIds' => [$andhra], 'email' => '', 'phone' => '',
        ]);
        self::assertSame(201, $sequence['status'], $sequence['raw']);
        self::assertSame('ER-M3-001', $sequence['body']['data']['manager']['code']);
        $renumbered = $admin->request('PATCH', '/api/managers/' . $sequence['body']['data']['manager']['id'], [
            'name' => 'Sequence', 'code' => 'ER-M3-001', 'level' => 3, 'stateIds' => [$andhra], 'email' => '', 'phone' => '',
        ]);
        self::assertSame(200, $renumbered['status'], $renumbered['raw']);
        self::assertSame($sequence['body']['data']['manager']['id'], $renumbered['body']['data']['manager']['id']);
        self::assertSame('ER-M3-001', $renumbered['body']['data']['manager']['code']);
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Again', 'code' => 'ER-M3-001', 'level' => 3, 'stateIds' => [$andhra], 'email' => 'again@example.test', 'phone' => '',
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Letters', 'code' => 'ER-M3-12A', 'level' => 3, 'stateIds' => [$andhra], 'email' => '', 'phone' => '',
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Wrong level', 'code' => 'ER-M2-001', 'level' => 3, 'stateIds' => [$andhra], 'email' => '', 'phone' => '',
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', $profile + [
            'name' => 'Copy', 'code' => 'M3-SHYAM', 'level' => 3, 'stateIds' => [$andhra], 'email' => 'copy@example.test',
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', $profile + [
            'name' => 'Same email', 'code' => 'M3-MAIL', 'level' => 3, 'stateIds' => [$andhra],
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', $profile + [
            'name' => 'No code', 'code' => '', 'level' => 3, 'stateIds' => [$andhra], 'email' => 'none@example.test',
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Bad contact', 'code' => 'M3-BAD', 'level' => 3, 'stateIds' => [$andhra], 'email' => 'not-an-email', 'phone' => '123',
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', $profile + [
            'name' => 'No state', 'code' => 'M3-NONE', 'level' => 3, 'stateIds' => [], 'email' => 'empty@example.test',
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', $profile + [
            'name' => 'Bad state', 'code' => 'M3-UNKNOWN', 'level' => 3, 'stateIds' => ['999999'], 'email' => 'unknown@example.test',
        ]), 422, 'validation_failed');
    }

    public function testHeadquartersCodeKeepsLeadingZerosAndUsesALocationState(): void
    {
        $admin = $this->signedInAs('admin', 'hq-code@example.test');
        $parentId = $this->managerChain($admin);
        $gujarat = $admin->request('GET', '/api/locations')['body']['data']['locations'][0]['id'];
        $created = $admin->request('POST', '/api/headquarters', [
            'name' => 'Navrangpura',
            'locationId' => $gujarat,
            'code' => 'hq-001',
            'address' => '1 Demo Road',
            'pincode' => '380009',
            'phone' => '9000000101',
            'email' => '  HQ@Example.test ',
            'parentManagerId' => $parentId,
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $hq = $created['body']['data']['headquarters'];
        self::assertSame('HQ-001', $hq['code']);
        self::assertSame('Gujarat', $hq['state']);
        self::assertSame($gujarat, $hq['locationId']);
        self::assertSame('hq@example.test', $hq['email']);
        self::assertSame($parentId, $hq['parentManagerId']);

        self::assertError($admin->request('POST', '/api/headquarters', [
            'name' => 'Athwa', 'locationId' => $gujarat, 'code' => 'HQ-001', 'address' => '2 Demo Road',
            'pincode' => '380009', 'phone' => '9000000102', 'email' => 'athwa@example.test', 'parentManagerId' => $parentId,
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/headquarters', [
            'name' => 'Athwa', 'locationId' => $gujarat, 'code' => 'HQ-12A', 'address' => '2 Demo Road',
            'pincode' => '380009', 'phone' => '9000000102', 'email' => 'athwa@example.test', 'parentManagerId' => $parentId,
        ]), 422, 'validation_failed');

        $legacy = $admin->request('POST', '/api/headquarters', $this->hq('Legacy', 'DEMO-HQ-51', $parentId));
        self::assertSame(201, $legacy['status'], $legacy['raw']);
        self::assertSame('DEMO-HQ-51', $legacy['body']['data']['headquarters']['code']);

        $stockist = $admin->request('POST', '/api/stockists', ['name' => 'Store', 'code' => '', 'headquartersId' => $hq['id']]);
        self::assertSame(201, $stockist['status'], $stockist['raw']);
        self::assertSame(200, $admin->request('POST', '/api/headquarters/' . $hq['id'] . '/delete')['status']);
        $codes = array_column($admin->request('GET', '/api/headquarters')['body']['data']['headquarters'], 'code');
        self::assertNotContains('HQ-001', $codes);
        self::assertContains('DEMO-HQ-51', $codes);
        $stockistNames = array_column($admin->request('GET', '/api/stockists')['body']['data']['stockists'], 'name');
        self::assertNotContains('Store', $stockistNames);
    }

    public function testDeletingAHeadquartersOfficeLeavesTheLocationHeadquarters(): void
    {
        $admin = $this->signedInAs('admin', 'hq-delete@example.test');
        $parentId = $this->managerChain($admin);
        $gujarat = $admin->request('GET', '/api/locations')['body']['data']['locations'][0]['id'];
        $locationHq = $admin->request('POST', '/api/location-headquarters', [
            'locationId' => $gujarat,
            'name' => 'Delete Source',
            'code' => 'HQ-001',
            'address' => '1 Demo Road',
            'pincode' => '380009',
            'phone' => '12452724',
            'email' => 'source@example.test',
        ]);
        self::assertSame(201, $locationHq['status'], $locationHq['raw']);
        $sourceId = null;
        foreach ($locationHq['body']['data']['locations'] as $location) {
            foreach ($location['headquarters'] as $office) {
                if ($office['name'] === 'Delete Source') {
                    $sourceId = $office['id'];
                    self::assertSame('12452724', $office['phone']);
                }
            }
        }
        self::assertNotNull($sourceId);

        $created = $admin->request('POST', '/api/headquarters', [
            'locationHeadquartersId' => $sourceId,
            'parentManagerId' => $parentId,
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $officeId = $created['body']['data']['headquarters']['id'];
        self::assertNotSame($sourceId, $officeId);

        $deleted = $admin->request('POST', '/api/headquarters/' . $officeId . '/delete');
        self::assertSame(200, $deleted['status'], $deleted['raw']);
        $after = $admin->request('GET', '/api/erp')['body']['data'];
        self::assertSame([], array_values(array_filter(
            $after['headquarters'],
            static fn (array $office): bool => $office['id'] === $officeId,
        )));
        $sourceRemains = false;
        foreach ($after['locations'] as $location) {
            foreach ($location['headquarters'] as $office) {
                if ($office['id'] === $sourceId && $office['code'] === 'HQ-001') {
                    $sourceRemains = true;
                }
            }
        }
        self::assertTrue($sourceRemains);

        $again = $admin->request('POST', '/api/headquarters', [
            'locationHeadquartersId' => $sourceId,
            'parentManagerId' => $parentId,
        ]);
        self::assertSame(201, $again['status'], $again['raw']);
        $blockedId = $again['body']['data']['headquarters']['id'];
        $stockist = $admin->request('POST', '/api/stockists', ['name' => 'Store', 'code' => '', 'headquartersId' => $blockedId]);
        self::assertSame(201, $stockist['status'], $stockist['raw']);
        $stockistId = $stockist['body']['data']['stockist']['id'];

        $removed = $admin->request('POST', '/api/headquarters/' . $blockedId . '/delete');
        self::assertSame(200, $removed['status'], $removed['raw']);
        $afterOffice = $admin->request('GET', '/api/erp')['body']['data'];
        self::assertSame([], array_values(array_filter(
            $afterOffice['headquarters'],
            static fn (array $office): bool => $office['id'] === $blockedId,
        )));
        self::assertSame([], array_values(array_filter(
            $afterOffice['stockists'],
            static fn (array $row): bool => $row['id'] === $stockistId,
        )));
        $sourceStillThere = false;
        foreach ($afterOffice['locations'] as $location) {
            foreach ($location['headquarters'] as $office) {
                if ($office['id'] === $sourceId) {
                    $sourceStillThere = true;
                }
            }
        }
        self::assertTrue($sourceStillThere);

        $sourceDeleted = $admin->request('POST', '/api/location-headquarters/' . $sourceId . '/delete');
        self::assertSame(200, $sourceDeleted['status'], $sourceDeleted['raw']);
        $sourceGone = true;
        foreach ($admin->request('GET', '/api/erp')['body']['data']['locations'] as $location) {
            foreach ($location['headquarters'] as $office) {
                if ($office['id'] === $sourceId) {
                    $sourceGone = false;
                }
            }
        }
        self::assertTrue($sourceGone);
    }

    public function testStockistCodeIsTypedByHandUniqueAndKeepsOptionalContact(): void
    {
        $admin = $this->signedInAs('admin', 'stockist-contact@example.test');
        $hq = $admin->request('POST', '/api/headquarters', $this->hq('HQ', 'HQ-001', $this->managerChain($admin)));
        self::assertSame(201, $hq['status'], $hq['raw']);
        $hqId = $hq['body']['data']['headquarters']['id'];
        $created = $admin->request('POST', '/api/stockists', [
            'name' => 'City Store',
            'code' => 'st-001',
            'headquartersId' => $hqId,
            'address' => '12 Market Road',
            'pincode' => '380001',
            'phone' => '12452724',
            'email' => 'store@example.test',
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $stockist = $created['body']['data']['stockist'];
        self::assertSame('st-001', $stockist['code']);
        self::assertSame('12 Market Road', $stockist['address']);
        self::assertSame('380001', $stockist['pincode']);
        self::assertSame('12452724', $stockist['phone']);
        self::assertSame('store@example.test', $stockist['email']);

        $blank = $admin->request('POST', '/api/stockists', ['name' => 'Plain', 'code' => '', 'headquartersId' => $hqId]);
        self::assertSame(201, $blank['status'], $blank['raw']);
        self::assertNull($blank['body']['data']['stockist']['code']);
        self::assertNull($blank['body']['data']['stockist']['address']);

        $free = $admin->request('POST', '/api/stockists', ['name' => 'Free Code', 'code' => 'ABC/12A', 'headquartersId' => $hqId]);
        self::assertSame(201, $free['status'], $free['raw']);
        self::assertSame('ABC/12A', $free['body']['data']['stockist']['code']);
        $again = $admin->request('POST', '/api/stockists', ['name' => 'Again', 'code' => ' ST-001 ', 'headquartersId' => $hqId]);
        self::assertError($again, 422, 'validation_failed');
        self::assertSame('This stockist code is already in use.', $again['body']['error']['details']['fields']['code'] ?? null, $again['raw']);

        $deleted = $admin->request('POST', '/api/stockists/' . $stockist['id'] . '/delete');
        self::assertSame(200, $deleted['status'], $deleted['raw']);
        $names = array_column($admin->request('GET', '/api/stockists')['body']['data']['stockists'], 'name');
        self::assertNotContains('City Store', $names);
        self::assertContains('Plain', $names);
    }

    public function testStockistKeepsHeadquarterLicencesAndAssignedEmployee(): void
    {
        $admin = $this->signedInAs('admin', 'stockist-licence@example.test');
        $gujarat = $admin->request('GET', '/api/locations')['body']['data']['locations'][0]['id'];
        $office = $admin->request('POST', '/api/location-headquarters', [
            'locationId' => $gujarat, 'name' => 'Licence HQ', 'code' => 'HQ-901', 'address' => '1 Demo Road',
            'pincode' => '380009', 'phone' => '12452724', 'email' => 'lic@example.test',
        ]);
        self::assertSame(201, $office['status'], $office['raw']);
        $officeId = null;
        foreach ($office['body']['data']['locations'] as $location) {
            foreach ($location['headquarters'] as $row) {
                if ($row['code'] === 'HQ-901') {
                    $officeId = $row['id'];
                }
            }
        }
        self::assertNotNull($officeId);
        $employee = $admin->request('POST', '/api/sales-employees', []);
        self::assertSame(201, $employee['status'], $employee['raw']);
        $employeeId = $employee['body']['data']['employee']['id'];

        $onlyName = $admin->request('POST', '/api/stockists', ['name' => 'Only Name']);
        self::assertSame(201, $onlyName['status'], $onlyName['raw']);
        self::assertNull($onlyName['body']['data']['stockist']['locationHeadquartersId']);

        $created = $admin->request('POST', '/api/stockists', [
            'name' => 'Licensed Store',
            'code' => 'LS-1',
            'locationHeadquartersId' => $officeId,
            'assignedEmployeeId' => $employeeId,
            'drugLicenceNo' => 'DL-123',
            'drugLicenceValidUpto' => '2027-03-31',
            'foodLicenceNo' => 'FL-9',
            'foodLicenceValidUpto' => '',
            'fssaiLicenceNo' => 'FSSAI-77',
            'fssaiLicenceValidUpto' => '2025-01-15',
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $stockist = $created['body']['data']['stockist'];
        self::assertSame((string) $officeId, (string) $stockist['locationHeadquartersId']);
        self::assertSame((string) $employeeId, (string) $stockist['assignedEmployeeId']);
        self::assertSame('DL-123', $stockist['drugLicenceNo']);
        self::assertSame('2027-03-31', $stockist['drugLicenceValidUpto']);
        self::assertNull($stockist['foodLicenceValidUpto']);
        self::assertSame('2025-01-15', $stockist['fssaiLicenceValidUpto']);

        $dup = $admin->request('POST', '/api/stockists', ['name' => 'Dup', 'code' => 'ls-1']);
        self::assertError($dup, 422, 'validation_failed');
        $badDate = $admin->request('POST', '/api/stockists', ['name' => 'Bad', 'drugLicenceValidUpto' => '2027-02-30']);
        self::assertError($badDate, 422, 'validation_failed');
        $badEmployee = $admin->request('POST', '/api/stockists', ['name' => 'Bad', 'assignedEmployeeId' => 999999]);
        self::assertError($badEmployee, 422, 'validation_failed');
        $badOffice = $admin->request('POST', '/api/stockists', ['name' => 'Bad', 'locationHeadquartersId' => 999999]);
        self::assertError($badOffice, 422, 'validation_failed');

        $updated = $admin->request('PATCH', '/api/stockists/' . $stockist['id'], [
            'name' => 'Licensed Store', 'code' => 'LS-1', 'locationHeadquartersId' => '', 'assignedEmployeeId' => '',
        ]);
        self::assertSame(200, $updated['status'], $updated['raw']);
        self::assertNull($updated['body']['data']['stockist']['locationHeadquartersId']);
        self::assertNull($updated['body']['data']['stockist']['assignedEmployeeId']);
        self::assertSame('DL-123', $updated['body']['data']['stockist']['drugLicenceNo']);
    }

    public function testRemovingAnEmployeeClearsAssignedToOnTheirStockists(): void
    {
        $admin = $this->signedInAs('admin', 'stockist-release@example.test');
        $removedId = $admin->request('POST', '/api/sales-employees', [])['body']['data']['employee']['id'];
        $keptId = $admin->request('POST', '/api/sales-employees', [])['body']['data']['employee']['id'];
        $first = $admin->request('POST', '/api/stockists', ['name' => 'Release One', 'assignedEmployeeId' => $removedId]);
        $second = $admin->request('POST', '/api/stockists', ['name' => 'Release Two', 'assignedEmployeeId' => $removedId]);
        $other = $admin->request('POST', '/api/stockists', ['name' => 'Keep Me', 'assignedEmployeeId' => $keptId]);
        foreach ([$first, $second, $other] as $created) {
            self::assertSame(201, $created['status'], $created['raw']);
        }

        $deleted = $admin->request('POST', '/api/sales-employees/' . $removedId . '/delete', []);
        self::assertSame(200, $deleted['status'], $deleted['raw']);

        $byName = [];
        foreach ($admin->request('GET', '/api/stockists')['body']['data']['stockists'] as $row) {
            $byName[$row['name']] = $row['assignedEmployeeId'];
        }
        self::assertNull($byName['Release One']);
        self::assertNull($byName['Release Two']);
        self::assertSame((string) $keptId, (string) $byName['Keep Me']);

        $audited = Database::connection()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE target_type = 'stockist' AND action = 'update' AND target_id IN (?, ?)"
        );
        $audited->execute([$first['body']['data']['stockist']['id'], $second['body']['data']['stockist']['id']]);
        self::assertSame(2, (int) $audited->fetchColumn());
    }

    public function testHeadquarterListAcceptsAnyCodeAndOptionalContact(): void
    {
        $admin = $this->signedInAs('admin', 'hq-free-code@example.test');
        $gujarat = $admin->request('GET', '/api/locations')['body']['data']['locations'][0]['id'];
        $minimal = $admin->request('POST', '/api/location-headquarters', ['locationId' => $gujarat, 'name' => 'Only Name']);
        self::assertSame(201, $minimal['status'], $minimal['raw']);
        $free = $admin->request('POST', '/api/location-headquarters', [
            'locationId' => $gujarat, 'name' => 'Surat North', 'code' => 'Surat/N 7', 'pincode' => '395001',
        ]);
        self::assertSame(201, $free['status'], $free['raw']);
        $codes = [];
        foreach ($free['body']['data']['locations'] as $location) {
            foreach ($location['headquarters'] as $office) {
                $codes[$office['name']] = [$office['code'], $office['pincode']];
            }
        }
        self::assertSame(['Surat/N 7', '395001'], $codes['Surat North']);
        self::assertNull($codes['Only Name'][0]);

        $duplicate = $admin->request('POST', '/api/location-headquarters', ['locationId' => $gujarat, 'name' => 'Other', 'code' => ' surat/n 7 ']);
        self::assertError($duplicate, 422, 'validation_failed');
        self::assertArrayHasKey('code', $duplicate['body']['error']['details']['fields']);
        $badPin = $admin->request('POST', '/api/location-headquarters', ['locationId' => $gujarat, 'name' => 'Bad Pin', 'pincode' => '12']);
        self::assertError($badPin, 422, 'validation_failed');
        self::assertArrayHasKey('pincode', $badPin['body']['error']['details']['fields']);

        $state = $admin->request('POST', '/api/locations', ['name' => 'No Code State', 'code' => '']);
        self::assertSame(201, $state['status'], $state['raw']);
        $nameless = $admin->request('POST', '/api/stockists', ['code' => 'NO-NAME-1']);
        self::assertSame(201, $nameless['status'], $nameless['raw']);
        self::assertNull($nameless['body']['data']['stockist']['name']);
    }

    public function testHeadquarterSavedWithOnlyStateAndNameKeepsStoredDetails(): void
    {
        $admin = $this->signedInAs('admin', 'hq-name-only@example.test');
        $locations = $admin->request('GET', '/api/locations')['body']['data']['locations'];
        $gujarat = $locations[0]['id'];
        $full = $admin->request('POST', '/api/location-headquarters', [
            'locationId' => $gujarat, 'name' => 'Keep Details', 'code' => 'KD-1', 'address' => '1 Test Road',
            'pincode' => '380001', 'phone' => '9000000777', 'email' => 'keep@example.test',
        ]);
        self::assertSame(201, $full['status'], $full['raw']);
        $find = static function (array $response, string $name): ?array {
            foreach ($response['body']['data']['locations'] as $location) {
                foreach ($location['headquarters'] as $office) {
                    if ($office['name'] === $name) {
                        return $office;
                    }
                }
            }

            return null;
        };
        $office = $find($full, 'Keep Details');
        self::assertNotNull($office);
        self::assertNotNull($office['updatedAt']);

        // The form now sends only state + name: stored code, address and contact stay.
        $renamed = $admin->request('PATCH', '/api/location-headquarters/' . $office['id'], ['locationId' => $gujarat, 'name' => 'Kept Details']);
        self::assertSame(200, $renamed['status'], $renamed['raw']);
        $kept = $find($renamed, 'Kept Details');
        self::assertSame(['KD-1', '1 Test Road', '380001', '9000000777', 'keep@example.test'], [$kept['code'], $kept['address'], $kept['pincode'], $kept['phone'], $kept['email']]);

        $minimal = $admin->request('POST', '/api/location-headquarters', ['locationId' => $gujarat, 'name' => 'Name Only HQ']);
        self::assertSame(201, $minimal['status'], $minimal['raw']);
        $duplicate = $admin->request('POST', '/api/location-headquarters', ['locationId' => $gujarat, 'name' => ' name only hq ']);
        self::assertError($duplicate, 422, 'validation_failed');
        self::assertArrayHasKey('name', $duplicate['body']['error']['details']['fields']);
    }

    /** @return array<string, string> */
    private function hq(string $name, string $code, string $parentId): array
    {
        return [
            'name' => $name,
            'code' => $code,
            'state' => 'Maharashtra',
            'address' => '1 Demo Road',
            'pincode' => '411001',
            'phone' => '9000000099',
            'parentManagerId' => $parentId,
        ];
    }
}
