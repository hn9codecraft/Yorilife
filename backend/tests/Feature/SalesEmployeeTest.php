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
        self::assertNull($employee['firstName']);
        self::assertNull($employee['lastName']);
        self::assertNull($employee['name']);
        self::assertNull($employee['employeeCode']);
        self::assertNull($employee['phone']);
        self::assertNull($employee['email']);
        self::assertNull($employee['designation']);
        self::assertSame([], $employee['locationIds']);
        self::assertSame([], $employee['headquarters']);
        self::assertSame([], $employee['managers']);

        $sales = $this->signedInAs('sales_user', 'sales-employees@example.test');
        $listed = $sales->request('GET', '/api/sales-employees');
        self::assertSame(200, $listed['status'], $listed['raw']);
        self::assertSame($employee['id'], $listed['body']['data']['employees'][0]['id']);
        self::assertError($sales->request('POST', '/api/sales-employees', ['firstName' => 'Blocked']), 403, 'forbidden');
        self::assertError($sales->request('PATCH', '/api/sales-employees/' . $employee['id'], ['firstName' => 'Blocked']), 403, 'forbidden');

        $updated = $admin->request('PATCH', '/api/sales-employees/' . $employee['id'], [
            'firstName' => 'Asha',
            'lastName' => 'Patel',
            'employeeCode' => 'E-14',
            'phone' => '09000001111',
            'email' => 'Asha@Example.test',
        ]);
        self::assertSame(200, $updated['status'], $updated['raw']);
        self::assertSame('Asha', $updated['body']['data']['employee']['firstName']);
        self::assertSame('Patel', $updated['body']['data']['employee']['lastName']);
        self::assertSame('Asha Patel', $updated['body']['data']['employee']['name']);
        self::assertSame('E-14', $updated['body']['data']['employee']['employeeCode']);
        self::assertSame('asha@example.test', $updated['body']['data']['employee']['email']);
        self::assertSame('09000001111', $updated['body']['data']['employee']['phone']);
        $stored = Database::connection()->query(
            'SELECT name, first_name, last_name FROM sales_employees WHERE id = ' . (int) $employee['id']
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('Asha Patel', $stored['name']);
        self::assertSame('Asha', $stored['first_name']);
        self::assertSame('Patel', $stored['last_name']);

        $gmOnly = $admin->request('POST', '/api/sales-employees', '{"firstName":"Top","designation":"GM","managers":{}}', ['Content-Type' => 'application/json']);
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
        $gm = $this->employee($admin, ['firstName' => 'Gita', 'employeeCode' => 'GM-1', 'designation' => 'GM', 'locationIds' => [$place['gujarat']], 'locationHeadquartersIds' => [$place['ahmedabad']]]);
        $zsm = $this->employee($admin, ['firstName' => 'Zara', 'designation' => 'ZSM']);
        $rbm = $this->employee($admin, ['firstName' => 'Rhea', 'designation' => 'RBM']);
        $bo = $this->employee($admin, [
            'firstName' => 'Bina',
            'lastName' => 'Rao Officer',
            'designation' => 'BO',
            'locationIds' => [$place['gujarat'], $place['bihar']],
            'locationHeadquartersIds' => [$place['ahmedabad']],
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
        self::assertSame('Bina Rao Officer', $bo['name']);
        self::assertEqualsCanonicalizing(['Bihar', 'Gujarat'], array_column($bo['locations'], 'name'));
        self::assertSame('Ahmedabad', $bo['headquarters'][0]['name']);
        self::assertSame($place['gujarat'], $bo['headquarters'][0]['locationId']);

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
        $outside = $admin->request('POST', '/api/sales-employees', [
            'locationIds' => [$place['bihar']],
            'locationHeadquartersIds' => [$place['ahmedabad']],
        ]);
        self::assertError($outside, 422, 'validation_failed');
        self::assertSame(
            'Choose a headquarters in a selected state.',
            $outside['body']['error']['details']['fields']['locationHeadquartersIds'] ?? null,
        );

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
        $boRow = $this->named($again['body']['data']['employees'], 'Bina Rao Officer');
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
        self::assertNull($this->named($left['body']['data']['employees'], 'Bina Rao Officer'));
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

    public function testCodePhoneAndEmailAreUniqueAmongActiveEmployees(): void
    {
        $admin = $this->signedInAs('admin', 'admin-unique@example.test');
        $first = $this->employee($admin, [
            'firstName' => 'Asha',
            'employeeCode' => 'E-14',
            'phone' => ' 09000001111 ',
            'email' => 'Asha@Example.test',
        ]);
        self::assertSame('09000001111', $first['phone']);
        self::assertSame('asha@example.test', $first['email']);

        $blank = $this->employee($admin, []);
        $anotherBlank = $this->employee($admin, ['employeeCode' => '', 'phone' => '   ', 'email' => null]);
        self::assertNull($blank['employeeCode']);
        self::assertNull($anotherBlank['phone']);
        self::assertNull($anotherBlank['email']);

        $sameCode = $admin->request('POST', '/api/sales-employees', ['employeeCode' => 'e-14']);
        self::assertSame(201, $sameCode['status'], 'employee code keeps its letter case');
        $duplicateCode = $admin->request('POST', '/api/sales-employees', ['employeeCode' => 'E-14']);
        self::assertError($duplicateCode, 422, 'validation_failed');
        self::assertSame('This employee code is already used.', $duplicateCode['body']['error']['details']['fields']['employeeCode'] ?? null);

        $duplicatePhone = $admin->request('POST', '/api/sales-employees', ['phone' => '09000001111']);
        self::assertError($duplicatePhone, 422, 'validation_failed');
        self::assertSame('This phone number is already used.', $duplicatePhone['body']['error']['details']['fields']['phone'] ?? null);

        $duplicateEmail = $admin->request('POST', '/api/sales-employees', ['email' => 'ASHA@example.test']);
        self::assertError($duplicateEmail, 422, 'validation_failed');
        self::assertSame('This email is already used.', $duplicateEmail['body']['error']['details']['fields']['email'] ?? null);

        $kept = $admin->request('PATCH', '/api/sales-employees/' . $first['id'], [
            'employeeCode' => 'E-14',
            'phone' => '09000001111',
            'email' => 'asha@example.test',
            'lastName' => 'Patel',
        ]);
        self::assertSame(200, $kept['status'], $kept['raw']);
        self::assertSame('Asha Patel', $kept['body']['data']['employee']['name']);

        self::assertSame(200, $admin->request('POST', '/api/sales-employees/' . $first['id'] . '/delete')['status']);
        $reused = $this->employee($admin, [
            'employeeCode' => 'E-14',
            'phone' => '09000001111',
            'email' => 'asha@example.test',
        ]);
        self::assertSame('E-14', $reused['employeeCode']);
        self::assertNotSame($first['id'], $reused['id']);
    }

    public function testStoredNamesSplitOnTheFirstSpaceAndPlacesMoveToJoinTables(): void
    {
        $pdo = Database::connection();
        $now = gmdate('Y-m-d H:i:s');
        $insert = $pdo->prepare('INSERT INTO sales_employees (name, location_id, created_at, updated_at) VALUES (?, NULL, ?, ?)');
        $split = $pdo->prepare(
            "UPDATE sales_employees
             SET first_name = NULLIF(SUBSTRING_INDEX(TRIM(name), ' ', 1), ''),
                 last_name = NULLIF(TRIM(SUBSTRING(TRIM(name), CHAR_LENGTH(SUBSTRING_INDEX(TRIM(name), ' ', 1)) + 1)), '')
             WHERE id = ? AND name IS NOT NULL AND TRIM(name) <> ''"
        );
        $cases = [
            'Asha' => ['Asha', null],
            'Gita Manager' => ['Gita', 'Manager'],
            'Bina Rao Officer' => ['Bina', 'Rao Officer'],
            '  Leading  Rest' => ['Leading', 'Rest'],
            '   ' => [null, null],
        ];
        foreach ($cases as $name => [$first, $last]) {
            $insert->execute([$name, $now, $now]);
            $id = (int) $pdo->lastInsertId();
            $split->execute([$id]);
            $row = $pdo->query('SELECT name, first_name, last_name FROM sales_employees WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
            self::assertSame($name, $row['name'], 'the original name column stays');
            self::assertSame($first, $row['first_name']);
            self::assertSame($last, $row['last_name']);
        }

        $columns = $pdo->query('SHOW COLUMNS FROM sales_employees')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('name', $columns);
        self::assertContains('location_id', $columns);
        self::assertContains('location_headquarters_id', $columns);
    }

    public function testClearingAStateDropsItsHeadquarters(): void
    {
        $admin = $this->signedInAs('admin', 'admin-places@example.test');
        $place = $this->place($admin);
        $biharOffice = $admin->request('POST', '/api/location-headquarters', [
            'locationId' => $place['bihar'],
            'name' => 'Patna',
            'code' => 'HQ-002',
            'address' => '2 River Road',
            'pincode' => '800001',
            'phone' => '9000000102',
            'email' => 'patna@example.test',
        ]);
        self::assertSame(201, $biharOffice['status'], $biharOffice['raw']);
        $patna = null;
        foreach ($biharOffice['body']['data']['locations'] as $location) {
            foreach ($location['headquarters'] as $office) {
                if ($office['name'] === 'Patna') {
                    $patna = (int) $office['id'];
                }
            }
        }
        self::assertNotNull($patna);
        $employee = $this->employee($admin, [
            'firstName' => 'Nita',
            'locationIds' => [$place['gujarat'], $place['bihar']],
            'locationHeadquartersIds' => [$place['ahmedabad'], $patna],
        ]);
        self::assertEqualsCanonicalizing(['Ahmedabad', 'Patna'], array_column($employee['headquarters'], 'name'));

        $narrowed = $admin->request('PATCH', '/api/sales-employees/' . $employee['id'], [
            'locationIds' => [$place['bihar']],
        ]);
        self::assertSame(200, $narrowed['status'], $narrowed['raw']);
        $row = $narrowed['body']['data']['employee'];
        self::assertSame([$place['bihar']], $row['locationIds']);
        self::assertSame(['Patna'], array_column($row['headquarters'], 'name'));
    }
}
