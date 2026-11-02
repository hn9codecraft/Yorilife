<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Database\DemoSalesOfficeSeeder;
use Tests\Support\FeatureTestCase;

/** Sprint 5 demo Sales Office rows, run against the MySQL test database. */
final class DemoDataTest extends FeatureTestCase
{
    public function testDemoSeedIsIdempotentAndEveryThirdLineParentTypeIsValid(): void
    {
        $pdo = Database::connection();
        $usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $productsBefore = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();

        $seeder = new DemoSalesOfficeSeeder();
        $first = $seeder->seed();
        $second = $seeder->seed();

        self::assertSame(2, $first['headquarters']);
        self::assertSame(9, $first['managers']);
        self::assertSame(5, $first['stockists']);
        self::assertSame(16, $first['created']);
        self::assertSame(0, $first['updated']);
        self::assertSame(0, $second['created']);
        self::assertSame(16, $second['updated']);
        self::assertSame($usersBefore, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame($productsBefore, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());

        self::assertSame(2, $this->countWhere("code LIKE 'DEMO-HQ-%'", 'headquarters'));
        self::assertSame(4, $this->countWhere("level = 1 AND code LIKE 'ER-M1-%'", 'managers'));
        self::assertSame(4, $this->countWhere("level = 2 AND code LIKE 'ER-M2-%'", 'managers'));
        self::assertSame(1, $this->countWhere("level = 3 AND code = 'ER-M3-001' AND name = 'Shyam' AND parent_manager_id IS NULL AND parent_headquarters_id IS NULL", 'managers'));
        self::assertSame(4, $this->countWhere("level = 2 AND parent_manager_id = (SELECT id FROM managers WHERE code = 'ER-M3-001')", 'managers'));
        self::assertSame(4, $this->countWhere("level = 1 AND parent_manager_id = (SELECT id FROM managers WHERE code = 'ER-M2-001')", 'managers'));
        foreach (['ER-M2-002', 'ER-M2-003', 'ER-M2-004'] as $secondCode) {
            self::assertSame(0, $this->countWhere("level = 1 AND parent_manager_id = (SELECT id FROM managers WHERE code = '{$secondCode}')", 'managers'));
        }
        self::assertSame(2, $this->countWhere("parent_manager_id = (SELECT id FROM managers WHERE code = 'ER-M1-001')", 'headquarters'));
        self::assertSame(0, $this->countWhere("code IN ('ER-M1-002', 'ER-M1-003', 'ER-M1-004') AND (SELECT COUNT(*) FROM headquarters h WHERE h.parent_manager_id = managers.id) > 0", 'managers'));
        self::assertSame(3, $this->countWhere("headquarters_id = (SELECT id FROM headquarters WHERE code = 'DEMO-HQ-01')", 'stockists'));
        self::assertSame(2, $this->countWhere("headquarters_id = (SELECT id FROM headquarters WHERE code = 'DEMO-HQ-02')", 'stockists'));
        self::assertSame(5, $this->countWhere("code LIKE 'DEMO-STK-%' AND headquarters_id IS NOT NULL", 'stockists'));
        self::assertSame(0, $this->countWhere("code LIKE 'DEMO-HQ-%' AND parent_manager_id IS NULL", 'headquarters'));

        $broken = (int) $pdo->query(
            'SELECT COUNT(*) FROM managers child
             LEFT JOIN managers parent ON parent.id = child.parent_manager_id
             WHERE child.code LIKE \'ER-M%\'
               AND child.level < 3
               AND (parent.id IS NULL OR parent.level <> child.level + 1)'
        )->fetchColumn();
        self::assertSame(0, $broken);

        $hq = $pdo->query("SELECT name, state, address, pincode, phone FROM headquarters WHERE code = 'DEMO-HQ-01'")->fetch();
        self::assertSame('HQ 1', $hq['name']);
        self::assertSame('Gujarat', $hq['state']);
        self::assertSame('380009', $hq['pincode']);
        self::assertSame('9000000001', $hq['phone']);

        $admin = $this->signedInAs('admin', 'demo-tree@example.test');
        $tree = $admin->request('GET', '/api/erp')['body']['data']['hierarchy'];
        self::assertCount(1, $tree['roots']);
        $root = $tree['roots'][0];
        self::assertSame('Shyam', $root['name']);
        self::assertSame('ER-M3-001', $root['code']);
        self::assertCount(4, $root['children']);
        self::assertSame(['Ali', 'Mohit', 'Raj', 'Shubham'], array_column($root['children'], 'name'));
        foreach (array_slice($root['children'], 0, 3) as $secondLine) {
            self::assertSame([], $secondLine['children']);
        }
        $shubham = $root['children'][3];
        self::assertSame('Shubham', $shubham['name']);
        self::assertSame('ER-M2-001', $shubham['code']);
        self::assertCount(4, $shubham['children']);
        self::assertSame('Aarav Mehta', $shubham['children'][0]['name']);
        self::assertSame('ER-M1-001', $shubham['children'][0]['code']);
        self::assertCount(2, $shubham['children'][0]['headquarters']);
        self::assertSame('HQ 1', $shubham['children'][0]['headquarters'][0]['name']);
        self::assertCount(3, $shubham['children'][0]['headquarters'][0]['stockists']);
        self::assertSame('HQ 2', $shubham['children'][0]['headquarters'][1]['name']);
        self::assertCount(2, $shubham['children'][0]['headquarters'][1]['stockists']);
        foreach (array_slice($shubham['children'], 1) as $firstLine) {
            self::assertSame([], $firstLine['headquarters']);
        }
        self::assertSame([], $tree['unplaced']);
    }

    public function testEachLevelRejectsAParentThatSkipsTheChain(): void
    {
        $admin = $this->signedInAs('admin', 'admin-line3@example.test');
        $third = $admin->request('POST', '/api/managers', [
            'name' => 'Third', 'code' => 'L3', 'level' => 3,
        ])['body']['data']['manager'];
        $second = $admin->request('POST', '/api/managers', [
            'name' => 'Second', 'code' => 'L2', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $third['id'],
        ])['body']['data']['manager'];
        $first = $admin->request('POST', '/api/managers', [
            'name' => 'First', 'code' => 'L1', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $second['id'],
        ])['body']['data']['manager'];

        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Root with parent', 'code' => 'L3-X', 'level' => 3, 'parentType' => 'manager2', 'parentId' => $second['id'],
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/managers', [
            'name' => 'Skipped', 'code' => 'L1-X', 'level' => 1, 'parentType' => 'manager3', 'parentId' => $third['id'],
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/headquarters', [
            'name' => 'No parent', 'code' => 'HQ-X', 'state' => 'Gujarat', 'address' => '1 Demo Road', 'pincode' => '380001', 'phone' => '9000000188',
        ]), 422, 'validation_failed');
        $hq = $admin->request('POST', '/api/headquarters', [
            'name' => 'Line HQ', 'code' => 'HQ-OK', 'state' => 'Gujarat', 'address' => '1 Demo Road', 'pincode' => '380001', 'phone' => '9000000188', 'parentManagerId' => $first['id'],
        ]);
        self::assertSame(201, $hq['status'], $hq['raw']);
        self::assertError($admin->request('POST', '/api/stockists', [
            'name' => 'With manager', 'code' => 'STK-X', 'headquartersId' => $hq['body']['data']['headquarters']['id'], 'managerId' => $third['id'],
        ]), 422, 'validation_failed');
    }

    public function testSeedingLeavesRecordsThatAreNotDemoCodesUnchanged(): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO headquarters (name, code, state, address, pincode, phone) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['Kept Office', 'REAL-HQ', 'Goa', '9 Real Road', '403001', '9000000777']);

        (new DemoSalesOfficeSeeder())->seed();

        $kept = $pdo->query("SELECT name, state, address FROM headquarters WHERE code = 'REAL-HQ'")->fetch();
        self::assertSame('Kept Office', $kept['name']);
        self::assertSame('Goa', $kept['state']);
        self::assertSame('9 Real Road', $kept['address']);
        self::assertSame(1, $this->countWhere("code = 'REAL-HQ'", 'headquarters'));
    }

    public function testHeadquartersStateMustBeAnOfficialStateOrTerritory(): void
    {
        $admin = $this->signedInAs('admin', 'state-list@example.test');
        $third = $admin->request('POST', '/api/managers', ['name' => 'Top', 'code' => 'ST-3', 'level' => 3])['body']['data']['manager'];
        $second = $admin->request('POST', '/api/managers', [
            'name' => 'Mid', 'code' => 'ST-2', 'level' => 2, 'parentType' => 'manager3', 'parentId' => $third['id'],
        ])['body']['data']['manager'];
        $first = $admin->request('POST', '/api/managers', [
            'name' => 'Lead', 'code' => 'ST-1', 'level' => 1, 'parentType' => 'manager2', 'parentId' => $second['id'],
        ])['body']['data']['manager'];
        $office = [
            'address' => '1 Demo Road', 'pincode' => '110001', 'phone' => '9000000188', 'parentManagerId' => $first['id'],
        ];
        self::assertError($admin->request('POST', '/api/headquarters', $office + [
            'name' => 'Bad State', 'code' => 'BAD-ST', 'state' => 'Texas', 'pincode' => '380001',
        ]), 422, 'validation_failed');
        self::assertSame(201, $admin->request('POST', '/api/headquarters', $office + [
            'name' => 'Capital', 'code' => 'DEL-HQ', 'state' => 'Delhi',
        ])['status']);
        self::assertSame(36, count(require dirname(__DIR__, 2) . '/config/states.php'));
    }

    private function countWhere(string $where, string $table): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
    }
}
