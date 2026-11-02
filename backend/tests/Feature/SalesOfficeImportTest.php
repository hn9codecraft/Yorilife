<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/** Sales Office CSV import adds new rows only, after the same rules as the forms. */
final class SalesOfficeImportTest extends FeatureTestCase
{
    public function testPreviewDoesNotSaveAndConfirmAddsOnlyValidManagerRows(): void
    {
        $admin = $this->signedInAs('admin', 'import-managers@example.test');
        $locations = $admin->request('GET', '/api/locations')['body']['data']['locations'];
        $gujarat = $locations[0]['id'];
        $andhra = $locations[1]['id'];
        $third = $admin->request('POST', '/api/managers', [
            'name' => 'Shyam', 'code' => 'ER-M3-001', 'level' => 3, 'stateIds' => [$gujarat, $andhra],
        ]);
        self::assertSame(201, $third['status'], $third['raw']);

        $headers = ['Name', 'Employee Code', 'Email', 'Phone Number', 'State List'];
        $rows = [
            ['Imported Third', 'ER-M3-041', 'third@example.test', '09000000141', 'Andhra Pradesh; Gujarat'],
            ['', 'ER-M3-042', 'bad-email', '123', ''],
            ['Duplicate', 'ER-M3-041', '', '', 'Gujarat'],
            ['Unknown State', 'ER-M3-043', '', '', 'Maharashtra'],
        ];
        $preview = $admin->request('POST', '/api/sales-office/import', [
            'module' => 'manager-3', 'headers' => $headers, 'rows' => $rows, 'confirm' => false,
        ]);
        self::assertSame(200, $preview['status'], $preview['raw']);
        $result = $preview['body']['data']['import'];
        self::assertSame(4, $result['total']);
        self::assertSame(1, $result['valid']);
        self::assertSame(3, $result['invalid']);
        self::assertSame(0, $result['imported']);
        self::assertNotEmpty($result['errors']);
        $listed = $admin->request('GET', '/api/managers')['body']['data']['managers'];
        self::assertFalse($this->hasCode($listed, 'ER-M3-041'));

        $confirmed = $admin->request('POST', '/api/sales-office/import', [
            'module' => 'manager-3', 'headers' => $headers, 'rows' => $rows, 'confirm' => true,
        ]);
        self::assertSame(200, $confirmed['status'], $confirmed['raw']);
        self::assertSame(1, $confirmed['body']['data']['import']['imported']);
        $saved = $this->findCode($admin->request('GET', '/api/managers')['body']['data']['managers'], 'ER-M3-041');
        self::assertNotNull($saved);
        self::assertSame('09000000141', $saved['phone']);
        self::assertSame('third@example.test', $saved['email']);
        self::assertSame(['Gujarat', 'Andhra Pradesh'], array_column($saved['states'], 'name'));

        $again = $admin->request('POST', '/api/sales-office/import', [
            'module' => 'manager-3', 'headers' => $headers, 'rows' => [$rows[0]], 'confirm' => true,
        ]);
        self::assertSame(0, $again['body']['data']['import']['imported']);
        self::assertSame(1, $again['body']['data']['import']['invalid']);

        $viewer = $this->signedInAs('sales_manager', 'import-viewer@example.test');
        self::assertError($viewer->request('POST', '/api/sales-office/import', [
            'module' => 'manager-3', 'headers' => $headers, 'rows' => [$rows[0]], 'confirm' => true,
        ]), 403, 'forbidden');
    }

    public function testSecondLineStatesMustBelongToTheParentAndLowerLevelsImport(): void
    {
        $admin = $this->signedInAs('admin', 'import-chain@example.test');
        $locations = $admin->request('GET', '/api/locations')['body']['data']['locations'];
        $gujarat = $locations[0]['id'];
        $bihar = $locations[4]['id'];
        $third = $admin->request('POST', '/api/managers', [
            'name' => 'Shyam', 'code' => 'ER-M3-051', 'level' => 3, 'stateIds' => [$gujarat],
        ]);
        self::assertSame(201, $third['status'], $third['raw']);

        $rejected = $admin->request('POST', '/api/sales-office/import', [
            'module' => 'manager-2',
            'headers' => ['Name', 'Employee Code', 'Reports To', 'State List'],
            'rows' => [['Shubham', 'ER-M2-051', 'ER-M3-051', 'Bihar']],
            'confirm' => true,
        ]);
        self::assertSame(200, $rejected['status'], $rejected['raw']);
        self::assertSame(0, $rejected['body']['data']['import']['imported']);
        self::assertSame(1, $rejected['body']['data']['import']['invalid']);

        $second = $admin->request('POST', '/api/sales-office/import', [
            'module' => 'manager-2',
            'headers' => ['Reports To', 'State List', 'Employee Code', 'Name'],
            'rows' => [['ER-M3-051', 'Gujarat', 'ER-M2-051', 'Shubham']],
            'confirm' => true,
        ]);
        self::assertSame(1, $second['body']['data']['import']['imported'], $second['raw']);

        $first = $admin->request('POST', '/api/sales-office/import', [
            'module' => 'manager-1',
            'headers' => ['Name', 'Employee Code', 'Reports To'],
            'rows' => [
                ['Aarav', 'ER-M1-051', 'ER-M2-051'],
                ['Nobody', 'ER-M1-052', 'ER-M2-999'],
            ],
            'confirm' => true,
        ]);
        self::assertSame(1, $first['body']['data']['import']['imported'], $first['raw']);
        self::assertSame(1, $first['body']['data']['import']['invalid']);

        $hq = $admin->request('POST', '/api/sales-office/import', [
            'module' => 'headquarters',
            'headers' => ['Headquarters name', '1st line manager', 'State', 'Code', 'Headquarters address', 'Pincode', 'Phone number'],
            'rows' => [['Imported HQ', 'ER-M1-051', 'Gujarat', 'IMP-HQ-51', '12 Demo Estate', '0380009', '9000000151']],
            'confirm' => true,
        ]);
        self::assertSame(0, $hq['body']['data']['import']['imported'], $hq['raw']);

        $hqOk = $admin->request('POST', '/api/sales-office/import', [
            'module' => 'headquarters',
            'headers' => ['Headquarters name', '1st line manager', 'State', 'Code', 'Headquarters address', 'Pincode', 'Phone number'],
            'rows' => [['Imported HQ', 'ER-M1-051', 'Gujarat', 'IMP-HQ-51', '12 Demo Estate', '380009', '9000000151']],
            'confirm' => true,
        ]);
        self::assertSame(1, $hqOk['body']['data']['import']['imported'], $hqOk['raw']);

        $stock = $admin->request('POST', '/api/sales-office/import', [
            'module' => 'stockist',
            'headers' => ['Stockist name', 'Stockist code', 'Headquarters'],
            'rows' => [
                ['Imported Stockist', 'IMP-STK-51', 'IMP-HQ-51'],
                ['Missing HQ', 'IMP-STK-52', ''],
                ['Unknown HQ', 'IMP-STK-53', 'NO-SUCH-HQ'],
            ],
            'confirm' => true,
        ]);
        self::assertSame(1, $stock['body']['data']['import']['imported'], $stock['raw']);
        self::assertSame(2, $stock['body']['data']['import']['invalid']);
        $saved = $this->findCode($admin->request('GET', '/api/stockists')['body']['data']['stockists'], 'IMP-STK-51');
        self::assertNotNull($saved);
        self::assertSame('IMP-STK-51', $saved['code']);

        self::assertError($admin->request('POST', '/api/sales-office/import', [
            'module' => 'manager-1', 'headers' => ['Name'], 'rows' => [['Aarav']], 'confirm' => false,
        ]), 422, 'validation_failed');
        self::assertFalse($this->hasCode($admin->request('GET', '/api/managers')['body']['data']['managers'], 'ER-M2-052'));
        self::assertNotContains($bihar, array_column(
            $this->findCode($admin->request('GET', '/api/managers')['body']['data']['managers'], 'ER-M2-051')['states'] ?? [],
            'id',
        ));
    }

    /** @param list<array<string, mixed>> $rows */
    private function hasCode(array $rows, string $code): bool
    {
        return $this->findCode($rows, $code) !== null;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed>|null */
    private function findCode(array $rows, string $code): ?array
    {
        foreach ($rows as $row) {
            if (($row['code'] ?? null) === $code) {
                return $row;
            }
        }

        return null;
    }
}
