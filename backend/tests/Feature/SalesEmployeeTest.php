<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Services\SalesEmployeeService;
use Tests\Support\FeatureTestCase;

/** Sales Office employee list: optional fields, designation line, and location masters. */
final class SalesEmployeeTest extends FeatureTestCase
{
    public function testDesignationsStayInHierarchyOrder(): void
    {
        self::assertSame(
            ['GM', 'ZSM', 'DSM', 'RBM', 'DBM', 'ABM', 'BO'],
            array_keys(SalesEmployeeService::DESIGNATIONS),
        );
        self::assertSame([], SalesEmployeeService::above('GM'));
        self::assertSame(['GM'], SalesEmployeeService::above('ZSM'));
        self::assertSame(['GM', 'ZSM', 'DSM', 'RBM', 'DBM', 'ABM'], SalesEmployeeService::above('BO'));
        self::assertSame([], SalesEmployeeService::above(null));
    }

    public function testAnEmptyEmployeeCanBeSavedAndEdited(): void
    {
        $admin = $this->signedInAs('admin', 'admin-employees@example.test');
        $created = $admin->request('POST', '/api/sales-employees', []);
        self::assertSame(201, $created['status'], $created['raw']);
        $employee = $created['body']['data']['employee'];
        self::assertNull($employee['name']);
        self::assertNull($employee['employeeCode']);
        self::assertNull($employee['phone']);
        self::assertNull($employee['email']);
        self::assertNull($employee['designation']);
        self::assertNull($employee['locationId']);
        self::assertNull($employee['locationHeadquartersId']);
        self::assertSame([], $employee['managers']);

        $sales = $this->signedInAs('sales_user', 'sales-employees@example.test');
        $listed = $sales->request('GET', '/api/sales-employees');
        self::assertSame(200, $listed['status'], $listed['raw']);
        self::assertSame($employee['id'], $listed['body']['data']['employees'][0]['id']);
        self::assertError($sales->request('POST', '/api/sales-employees', ['name' => 'Blocked']), 403, 'forbidden');
        self::assertError($sales->request('PATCH', '/api/sales-employees/' . $employee['id'], ['name' => 'Blocked']), 403, 'forbidden');

        $updated = $admin->request('PATCH', '/api/sales-employees/' . $employee['id'], [
            'name' => 'Asha',
            'employeeCode' => 'E-14',
            'phone' => '09000001111',
            'email' => 'Asha@Example.test',
        ]);
        self::assertSame(200, $updated['status'], $updated['raw']);
        self::assertSame('Asha', $updated['body']['data']['employee']['name']);
        self::assertSame('E-14', $updated['body']['data']['employee']['employeeCode']);
        self::assertSame('asha@example.test', $updated['body']['data']['employee']['email']);
        self::assertSame('09000001111', $updated['body']['data']['employee']['phone']);

        $gmOnly = $admin->request('POST', '/api/sales-employees', '{"name":"Top","designation":"GM","managers":{}}', ['Content-Type' => 'application/json']);
        self::assertSame(201, $gmOnly['status'], $gmOnly['raw']);
        self::assertSame([], $gmOnly['body']['data']['employee']['managers']);

        self::assertError($admin->request('POST', '/api/sales-employees', ['email' => 'not-an-email']), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/sales-employees', ['designation' => 'CEO']), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/sales-employees', ['bonus' => 1]), 422, 'validation_failed');

        $audit = Database::connection()->query(
            "SELECT action FROM audit_logs WHERE target_type = 'sales_employee' AND target_id = {$employee['id']} ORDER BY id"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['create', 'update'], $audit);
    }

    public function testManagersAreIndependentAndFollowTheDesignationAbove(): void
    {
        $admin = $this->signedInAs('admin', 'admin-line@example.test');
        $place = $this->place($admin);
        $gm = $this->employee($admin, ['name' => 'Gita', 'employeeCode' => 'GM-1', 'designation' => 'GM', 'locationId' => $place['gujarat'], 'locationHeadquartersId' => $place['ahmedabad']]);
        $zsm = $this->employee($admin, ['name' => 'Zara', 'designation' => 'ZSM']);
        $rbm = $this->employee($admin, ['name' => 'Rhea', 'designation' => 'RBM']);
        $bo = $this->employee($admin, [
            'name' => 'Bina',
            'designation' => 'BO',
            'locationId' => $place['gujarat'],
            'locationHeadquartersId' => $place['ahmedabad'],
            'managers' => [
                'GM' => $gm['id'],
                'RBM' => $rbm['id'],
                'ZSM' => null,
                'DSM' => '',
                'DBM' => null,
                'ABM' => null,
            ],
        ]);

        $codes = array_column($bo['managers'], 'designation');
        self::assertSame(['GM', 'RBM'], $codes);
        self::assertSame('Gita', $bo['managers'][0]['employeeName']);
        self::assertSame('Ahmedabad', $bo['locationHeadquartersName']);
        self::assertSame('Gujarat', $bo['locationName']);

        self::assertError($admin->request('PATCH', '/api/sales-employees/' . $bo['id'], [
            'designation' => 'BO',
            'managers' => ['GM' => $bo['id']],
        ]), 422, 'validation_failed');
        self::assertError($admin->request('PATCH', '/api/sales-employees/' . $bo['id'], [
            'designation' => 'BO',
            'managers' => ['GM' => $zsm['id']],
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/sales-employees', [
            'designation' => 'ZSM',
            'managers' => ['RBM' => $rbm['id']],
        ]), 422, 'validation_failed');
        self::assertError($admin->request('POST', '/api/sales-employees', [
            'locationId' => $place['bihar'],
            'locationHeadquartersId' => $place['ahmedabad'],
        ]), 422, 'validation_failed');

        $narrowed = $admin->request('PATCH', '/api/sales-employees/' . $bo['id'], [
            'designation' => 'ZSM',
            'managers' => ['GM' => $gm['id']],
        ]);
        self::assertSame(200, $narrowed['status'], $narrowed['raw']);
        self::assertSame(['GM'], array_column($narrowed['body']['data']['employee']['managers'], 'designation'));

        $cleared = $admin->request('PATCH', '/api/sales-employees/' . $gm['id'], ['designation' => 'BO']);
        self::assertSame(200, $cleared['status'], $cleared['raw']);
        $again = $admin->request('GET', '/api/sales-employees');
        $zsmRow = $this->named($again['body']['data']['employees'], 'Zara');
        self::assertSame([], $zsmRow['managers']);
        $boRow = $this->named($again['body']['data']['employees'], 'Bina');
        self::assertSame([], $boRow['managers'], 'the GM slot is cleared once that person is no longer a GM');

        $found = $admin->request('GET', '/api/search?q=' . rawurlencode('GM-1'));
        self::assertSame(200, $found['status'], $found['raw']);
        $match = null;
        foreach ($found['body']['data']['groups'] as $group) {
            if ($group['type'] === 'employees') {
                $match = $group['items'][0];
            }
        }
        self::assertNotNull($match);
        self::assertSame('/sales-office/employees', $match['path']);
        self::assertSame('Gita', $match['label']);

        self::assertSame(200, $admin->request('POST', '/api/sales-employees/' . $bo['id'] . '/delete')['status']);
        $left = $admin->request('GET', '/api/sales-employees');
        self::assertNull($this->named($left['body']['data']['employees'], 'Bina'));
        self::assertError($admin->request('POST', '/api/locations/' . $place['gujarat'] . '/delete'), 422, 'validation_failed');
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function employee(\Tests\Support\ApiClient $admin, array $fields): array
    {
        $response = $admin->request('POST', '/api/sales-employees', $fields);
        self::assertSame(201, $response['status'], $response['raw']);

        return $response['body']['data']['employee'];
    }

    /** @return array{gujarat: int, bihar: int, ahmedabad: int} */
    private function place(\Tests\Support\ApiClient $admin): array
    {
        $locations = $admin->request('GET', '/api/locations')['body']['data']['locations'];
        $idOf = static function (string $name) use ($locations): int {
            foreach ($locations as $location) {
                if ($location['name'] === $name) {
                    return (int) $location['id'];
                }
            }
            self::fail("Missing seeded state {$name}");
        };
        $gujarat = $idOf('Gujarat');
        $created = $admin->request('POST', '/api/location-headquarters', [
            'locationId' => $gujarat,
            'name' => 'Ahmedabad',
            'code' => 'HQ-001',
            'address' => '1 River Road',
            'pincode' => '380001',
            'phone' => '9000000101',
            'email' => 'ahmedabad@example.test',
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $officeId = null;
        foreach ($created['body']['data']['locations'] as $location) {
            if ((int) $location['id'] !== $gujarat) {
                continue;
            }
            foreach ($location['headquarters'] as $office) {
                if ($office['name'] === 'Ahmedabad') {
                    $officeId = (int) $office['id'];
                }
            }
        }
        self::assertNotNull($officeId);

        return ['gujarat' => $gujarat, 'bihar' => $idOf('Bihar'), 'ahmedabad' => $officeId];
    }

    /** @param list<array<string, mixed>> $rows */
    private function named(array $rows, string $name): ?array
    {
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        return null;
    }
}
