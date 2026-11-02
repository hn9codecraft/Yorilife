<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Global search returns only records the signed-in user is allowed to view. */
final class SearchTest extends FeatureTestCase
{
    public function testSearchRequiresASessionAndRejectsABadQuery(): void
    {
        self::assertError((new ApiClient())->request('GET', '/api/search?q=Gujarat'), 401, 'unauthenticated');

        $sales = $this->signedInAs('sales_user', 'search-sales@example.test');
        self::assertSame(['groups' => []], $sales->request('GET', '/api/search')['body']['data']);
        self::assertSame(['groups' => []], $sales->request('GET', '/api/search?q=%20%20')['body']['data']);
        self::assertError($sales->request('GET', '/api/search?q=' . str_repeat('a', 81)), 422, 'validation_failed');
        self::assertError($sales->request('GET', '/api/search?q[]=Gujarat'), 422, 'validation_failed');
    }

    public function testResultsFollowPermissionsAndLinkToTheListPage(): void
    {
        $admin = $this->signedInAs('admin', 'search-admin@example.test');
        $group = $this->term($admin, ['kind' => 'groupCategory', 'name' => 'Zephyr Group']);
        $category = $this->term($admin, ['kind' => 'category', 'name' => 'Zephyr Category', 'parentId' => $group]);
        $tag = $this->term($admin, ['kind' => 'tag', 'name' => 'Zephyr Tag']);
        self::assertSame(200, $admin->request('POST', "/api/catalogue-terms/{$tag}/delete", [])['status']);

        $active = $admin->request('POST', '/api/products', [
            'name' => '100% Pure Zephyr', 'code' => 'ZEP-100', 'groupCategoryId' => $group, 'categoryId' => $category, 'scheme' => '',
        ]);
        self::assertSame(201, $active['status'], $active['raw']);
        $retired = $admin->request('POST', '/api/products', [
            'name' => 'Retired Zephyr', 'code' => 'ZEP-OLD', 'groupCategoryId' => $group, 'categoryId' => $category, 'scheme' => '',
        ]);
        self::assertSame(201, $retired['status'], $retired['raw']);
        $retiredId = $retired['body']['data']['product']['id'];
        self::assertSame(200, $admin->request('POST', "/api/products/{$retiredId}/delete", [])['status']);

        $locations = $admin->request('GET', '/api/locations')['body']['data']['locations'];
        $gujarat = null;
        foreach ($locations as $location) {
            if ($location['name'] === 'Gujarat') {
                $gujarat = $location['id'];
            }
        }
        self::assertNotNull($gujarat);
        $third = $admin->request('POST', '/api/managers', [
            'name' => 'Zephyr Third', 'code' => 'ZEP-M3', 'level' => 3,
            'email' => 'zephyr.third@example.test', 'phone' => '', 'stateIds' => [$gujarat],
        ]);
        self::assertSame(201, $third['status'], $third['raw']);
        $thirdId = $third['body']['data']['manager']['id'];
        $second = $admin->request('POST', '/api/managers', [
            'name' => 'Zephyr Second', 'code' => 'ZEP-M2', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $thirdId,
        ]);
        self::assertSame(201, $second['status'], $second['raw']);
        $first = $admin->request('POST', '/api/managers', [
            'name' => 'Zephyr First', 'code' => 'ZEP-M1', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $second['body']['data']['manager']['id'],
        ]);
        self::assertSame(201, $first['status'], $first['raw']);
        $hq = $admin->request('POST', '/api/headquarters', [
            'name' => 'Zephyr HQ', 'code' => 'ZEP-HQ', 'state' => 'Gujarat', 'address' => '1 Demo Road',
            'pincode' => '411001', 'phone' => '9000000099', 'parentManagerId' => $first['body']['data']['manager']['id'],
        ]);
        self::assertSame(201, $hq['status'], $hq['raw']);
        $stockist = $admin->request('POST', '/api/stockists', [
            'name' => 'Zephyr Store', 'code' => 'ZEP-ST', 'headquartersId' => $hq['body']['data']['headquarters']['id'],
        ]);
        self::assertSame(201, $stockist['status'], $stockist['raw']);
        $sale = $admin->request('POST', '/api/sales-entries', [
            'month' => '2026-04',
            'headquartersId' => $hq['body']['data']['headquarters']['id'],
            'stockistId' => $stockist['body']['data']['stockist']['id'],
            'achievementValue' => 100,
            'bonus' => 10,
            'deduction' => 5,
            'finalValue' => 77.5,
        ]);
        self::assertSame(201, $sale['status'], $sale['raw']);
        self::assertSame(201, $admin->request('POST', '/api/financial-years', ['label' => '2098-2099'])['status']);

        $found = $admin->request('GET', '/api/search?q=' . rawurlencode('Zephyr'));
        self::assertSame(200, $found['status'], $found['raw']);
        self::assertStringNotContainsString('password_hash', $found['raw']);
        self::assertStringNotContainsString('finalValue', $found['raw']);
        self::assertStringNotContainsString('77.50', $found['raw']);
        self::assertStringNotContainsString('9000000099', $found['raw']);

        $byType = $this->byType($found);
        self::assertSame(['/products/group-categories'], array_column($byType['group-categories'], 'path'));
        self::assertSame(['/products/categories'], array_column($byType['categories'], 'path'));
        self::assertSame('/products/tags-badges', $byType['tags-badges'][0]['path']);
        self::assertStringContainsString('Deleted', $byType['tags-badges'][0]['detail']);
        self::assertSame('/products/list', $this->named($byType['products'], '100% Pure Zephyr')['path']);
        self::assertSame('/products/deleted', $this->named($byType['products'], 'Retired Zephyr')['path']);
        self::assertSame('/sales-office/headquarters', $byType['headquarters'][0]['path']);
        self::assertSame('/sales-office/stockists', $byType['stockists'][0]['path']);
        self::assertSame('/sales-office/third-line-managers', $this->named($byType['managers'], 'Zephyr Third')['path']);
        self::assertStringContainsString('zephyr.third@example.test', $this->named($byType['managers'], 'Zephyr Third')['detail']);
        self::assertSame('/sales-office/second-line-managers', $this->named($byType['managers'], 'Zephyr Second')['path']);
        self::assertSame('/sales-office/first-line-managers', $this->named($byType['managers'], 'Zephyr First')['path']);
        self::assertSame('/sales/stockist-sales', $byType['sales'][0]['path']);
        self::assertSame('Zephyr Store', $byType['sales'][0]['label']);
        self::assertStringContainsString('2026-04', $byType['sales'][0]['detail']);
        self::assertArrayNotHasKey('financial-years', $byType);
        self::assertArrayNotHasKey('users', $byType);

        $year = $admin->request('GET', '/api/search?q=' . rawurlencode('2098-2099'));
        self::assertSame('/products/master-sheet', $this->byType($year)['financial-years'][0]['path']);
        $people = $admin->request('GET', '/api/search?q=' . rawurlencode('search-admin@'));
        self::assertSame('/system/users', $this->named($this->byType($people)['users'], 'Admin')['path']);
        self::assertStringNotContainsString('password_hash', $people['raw']);

        foreach ($found['body']['data']['groups'] as $group) {
            foreach ($group['items'] as $item) {
                self::assertSame(['id', 'label', 'detail', 'path'], array_keys($item));
            }
        }

        $literal = $admin->request('GET', '/api/search?q=' . rawurlencode('%'));
        self::assertSame(['100% Pure Zephyr'], array_column($this->byType($literal)['products'], 'label'));
        $injection = $admin->request('GET', '/api/search?q=' . rawurlencode("' OR 1=1 --"));
        self::assertSame(200, $injection['status'], $injection['raw']);
        self::assertStringNotContainsString('Gujarat', $injection['raw']);
        self::assertStringNotContainsString('Zephyr', $injection['raw']);

        $sales = $this->signedInAs('sales_user', 'search-viewer@example.test');
        $visible = $sales->request('GET', '/api/search?q=' . rawurlencode('Zephyr'));
        self::assertSame(200, $visible['status'], $visible['raw']);
        self::assertArrayNotHasKey('users', $this->byType($visible));
        self::assertStringNotContainsString('search-admin@example.test', $visible['raw']);
        self::assertStringNotContainsString('password_hash', $visible['raw']);
        self::assertSame('Zephyr HQ', $this->byType($visible)['headquarters'][0]['label']);

        $location = $sales->request('GET', '/api/search?q=' . rawurlencode('GUJ'));
        self::assertSame('Gujarat', $this->byType($location)['locations'][0]['label']);
        self::assertSame('/sales-office/locations', $this->byType($location)['locations'][0]['path']);
        self::assertSame('GUJ', $this->byType($location)['locations'][0]['detail']);
    }

    /** @param array<string, mixed> $body */
    private function term(ApiClient $client, array $body): string
    {
        $created = $client->request('POST', '/api/catalogue-terms', $body);
        self::assertSame(201, $created['status'], $created['raw']);

        return $created['body']['data']['term']['id'];
    }

    /** @param array{status: int, body: array<string, mixed>|null, raw: string} $response */
    /** @return array<string, list<array<string, string>>> */
    private function byType(array $response): array
    {
        $grouped = [];
        foreach ($response['body']['data']['groups'] ?? [] as $group) {
            $grouped[$group['type']] = $group['items'];
        }

        return $grouped;
    }

    /**
     * @param list<array<string, string>> $items
     * @return array<string, string>
     */
    private function named(array $items, string $label): array
    {
        foreach ($items as $item) {
            if ($item['label'] === $label) {
                return $item;
            }
        }
        self::fail("Missing search result {$label}");
    }
}
