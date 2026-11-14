<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Products > Category (Product Type, Product Group) and the Product List fields. */
final class ProductCategoryTest extends FeatureTestCase
{
    public function testProductTypeAndGroupCrudWithCaseInsensitiveUniqueNames(): void
    {
        $admin = $this->signedInAs('admin', 'admin-product-category@example.test');

        $type = $admin->request('POST', '/api/product-types', ['name' => '  Tablet ']);
        self::assertSame(201, $type['status'], $type['raw']);
        self::assertSame('Tablet', $type['body']['data']['productType']['name']);
        self::assertSame(0, $type['body']['data']['productType']['productCount']);
        $typeId = $type['body']['data']['productType']['id'];

        $duplicate = $admin->request('POST', '/api/product-types', ['name' => 'tablet']);
        self::assertError($duplicate, 422, 'validation_failed');
        self::assertArrayHasKey('name', $duplicate['body']['error']['details']['fields']);
        self::assertError($admin->request('POST', '/api/product-types', ['name' => '']), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/product-types', ['name' => 'Syrup', 'code' => 'X']), 422, 'validation_failed');

        $renamed = $admin->request('PATCH', '/api/product-types/' . $typeId, ['name' => 'Tablets']);
        self::assertSame(200, $renamed['status'], $renamed['raw']);
        self::assertSame('Tablets', $renamed['body']['data']['productType']['name']);

        foreach (['Focus Product', 'Best Selling'] as $name) {
            self::assertSame(201, $admin->request('POST', '/api/product-groups', ['name' => $name])['status']);
        }
        self::assertError($admin->request('POST', '/api/product-groups', ['name' => 'FOCUS PRODUCT']), 422, 'validation_failed');

        $list = $admin->request('GET', '/api/product-categories');
        self::assertSame(200, $list['status'], $list['raw']);
        self::assertSame(['Tablets'], array_column($list['body']['data']['productTypes'], 'name'));
        self::assertSame(['Best Selling', 'Focus Product'], array_column($list['body']['data']['productGroups'], 'name'));

        // The full business state carries both lists for the screens.
        $state = $admin->request('GET', '/api/erp')['body']['data'];
        self::assertCount(1, $state['productTypes']);
        self::assertCount(2, $state['productGroups']);

        // Delete is a soft delete; the name can then be used again.
        $deleted = $admin->request('POST', '/api/product-types/' . $typeId . '/delete', []);
        self::assertSame(200, $deleted['status'], $deleted['raw']);
        self::assertNotNull($deleted['body']['data']['productType']['deletedAt']);
        self::assertSame([], $admin->request('GET', '/api/product-categories')['body']['data']['productTypes']);
        self::assertSame(201, $admin->request('POST', '/api/product-types', ['name' => 'Tablets'])['status']);
        self::assertSame(404, $admin->request('PATCH', '/api/product-types/' . $typeId, ['name' => 'Again'])['status']);
    }

    public function testProductListFieldsValidationAndDeleteBlockedWhileInUse(): void
    {
        $admin = $this->signedInAs('admin', 'admin-product-list@example.test');
        $typeId = $admin->request('POST', '/api/product-types', ['name' => 'Capsule'])['body']['data']['productType']['id'];
        $groupId = $admin->request('POST', '/api/product-groups', ['name' => 'Popular'])['body']['data']['productGroup']['id'];

        // Only the name is required.
        $minimal = $admin->request('POST', '/api/products', ['name' => 'Name Only']);
        self::assertSame(201, $minimal['status'], $minimal['raw']);
        self::assertNull($minimal['body']['data']['product']['sku']);

        $body = [
            'name' => 'Yori Cap', 'sku' => 'YC-10', 'productTypeId' => $typeId, 'packagingUnit' => '10 x 10',
            'productGroupId' => $groupId, 'mrp' => '120.50', 'ptr' => '96.40', 'pts' => '86.75', 'scheme' => '10+1', 'netRate' => '80.00',
        ];
        $created = $admin->request('POST', '/api/products', $body);
        self::assertSame(201, $created['status'], $created['raw']);
        $product = $created['body']['data']['product'];
        self::assertSame(['YC-10', 'YC-10', $typeId, $groupId, '10 x 10', '120.50', '96.40', '86.75', '80.00', '10+1'], [
            $product['sku'], $product['code'], $product['productTypeId'], $product['productGroupId'], $product['packagingUnit'],
            $product['mrp'], $product['ptr'], $product['pts'], $product['netRate'], $product['scheme'],
        ]);

        // SKU is unique (case-insensitive) among active products.
        $dupSku = $admin->request('POST', '/api/products', ['name' => 'Other', 'sku' => 'yc-10']);
        self::assertError($dupSku, 422, 'validation_failed');
        self::assertSame(['sku'], array_keys($dupSku['body']['error']['details']['fields']));

        foreach ([['mrp' => '-1'], ['netRate' => 'abc'], ['pts' => '12345678901.00'], ['productTypeId' => '999999'], ['productGroupId' => 'x'], ['name' => '']] as $bad) {
            $response = $admin->request('POST', '/api/products', array_merge(['name' => 'Bad'], $bad));
            self::assertError($response, 422, 'validation_failed');
            self::assertArrayHasKey(array_key_first($bad), $response['body']['error']['details']['fields'], json_encode($bad));
        }

        // Editing without the new fields keeps them; clearing works with blanks.
        $kept = $admin->request('PATCH', '/api/products/' . $product['id'], ['name' => 'Yori Cap Plus', 'sku' => 'YC-10', 'scheme' => '10+1']);
        self::assertSame(200, $kept['status'], $kept['raw']);
        self::assertSame([$typeId, $groupId, '80.00'], [$kept['body']['data']['product']['productTypeId'], $kept['body']['data']['product']['productGroupId'], $kept['body']['data']['product']['netRate']]);

        // A type or group in use cannot be deleted; the product count is reported.
        $counts = $admin->request('GET', '/api/product-categories')['body']['data'];
        self::assertSame(1, $counts['productTypes'][0]['productCount']);
        $blocked = $admin->request('POST', '/api/product-groups/' . $groupId . '/delete', []);
        self::assertError($blocked, 422, 'validation_failed');
        self::assertStringContainsString('1 product uses', $blocked['body']['error']['details']['fields']['record']);

        $cleared = $admin->request('PATCH', '/api/products/' . $product['id'], ['name' => 'Yori Cap Plus', 'sku' => '', 'productTypeId' => null, 'productGroupId' => '', 'netRate' => '', 'scheme' => '']);
        self::assertSame(200, $cleared['status'], $cleared['raw']);
        self::assertNull($cleared['body']['data']['product']['productGroupId']);
        self::assertNull($cleared['body']['data']['product']['netRate']);
        self::assertSame(200, $admin->request('POST', '/api/product-groups/' . $groupId . '/delete', [])['status']);

        // History records the new fields.
        $history = $admin->request('GET', '/api/products')['body']['data']['productHistory'];
        $fields = [];
        foreach ($history as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $fields[$change['field']] = true;
            }
        }
        foreach (['Product type', 'Product group', 'Packaging unit', 'Net rate'] as $field) {
            self::assertArrayHasKey($field, $fields);
        }
    }

    public function testViewOnlyRolesCannotChangeCategoriesAndExportsAreAudited(): void
    {
        $viewer = $this->signedInAs('sales_user', 'viewer-product-category@example.test');
        self::assertSame(200, $viewer->request('GET', '/api/product-categories')['status']);
        self::assertError($viewer->request('POST', '/api/product-types', ['name' => 'Nope']), 403, 'forbidden');
        self::assertError($viewer->request('POST', '/api/product-groups', ['name' => 'Nope']), 403, 'forbidden');
        self::assertError($viewer->request('POST', '/api/products', ['name' => 'Nope']), 403, 'forbidden');

        foreach (['products_csv', 'product_categories_csv'] as $kind) {
            $export = $viewer->request('POST', '/api/exports', ['kind' => $kind, 'filename' => 'products-2026-11-14.csv', 'rowCount' => 3]);
            self::assertSame(200, $export['status'], $export['raw']);
        }
        self::assertError($viewer->request('POST', '/api/exports', ['kind' => 'other_csv', 'filename' => 'x.csv', 'rowCount' => 1]), 422, 'validation_failed');
    }
}