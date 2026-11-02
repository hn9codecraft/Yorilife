<?php

declare(strict_types=1);

namespace App\Database;

use App\Repositories\SalesOfficeRepository;
use App\Services\SalesOfficeService;
use RuntimeException;

/**
 * Fictional Sales Office demo rows. Idempotent by employee and office codes:
 * a second run updates those rows and does not insert another copy. Other
 * records, users, and roles are left alone.
 */
final class DemoSalesOfficeSeeder
{
    public function __construct(
        private readonly SalesOfficeRepository $records = new SalesOfficeRepository(),
        private readonly SalesOfficeService $office = new SalesOfficeService(),
    ) {
    }

    /** @return array{created: int, updated: int, headquarters: int, managers: int, stockists: int} */
    public function seed(): array
    {
        return $this->records->transaction(function (): array {
            $headquarters = $this->headquarters();
            $managers = $this->managers();
            $stockists = $this->stockists();
            $this->records->deleteSalesForRemovedDemoStockists(array_column($stockists, 'code'));
            $this->records->detachDemoHeadquarters();
            $this->records->deleteDemoStockistsExcept(array_column($stockists, 'code'));
            $this->records->deleteDemoHeadquartersExcept(array_column($headquarters, 'code'));
            $this->records->deleteDemoManagersExcept(array_column($managers, 'code'));

            $created = 0;
            $updated = 0;
            $count = static function (string $result) use (&$created, &$updated): void {
                $result === 'created' ? $created++ : $updated++;
            };

            foreach ($managers as $row) {
                $count($this->saveManager($row));
            }
            foreach ($headquarters as $row) {
                $count($this->saveHeadquarters($row));
            }
            foreach ($stockists as $row) {
                $count($this->saveStockist($row));
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'headquarters' => count($headquarters),
                'managers' => count($managers),
                'stockists' => count($stockists),
            ];
        });
    }

    /** @param array<string, string> $row */
    private function saveHeadquarters(array $row): string
    {
        $parent = $this->records->findManagerByCode($row['parentCode']);
        if ($parent === null) {
            throw new RuntimeException('Demo parent ' . $row['parentCode'] . ' was not found.');
        }
        $input = $row;
        unset($input['parentCode']);
        $input['parentManagerId'] = (int) $parent['id'];
        $existing = $this->records->findHeadquartersByCode($row['code']);
        if ($existing === null) {
            $this->office->createHeadquarters($input);

            return 'created';
        }
        $this->office->updateHeadquarters((int) $existing['id'], $input);

        return 'updated';
    }

    /** @param array<string, mixed> $row */
    private function saveManager(array $row): string
    {
        $input = [
            'name' => $row['name'],
            'code' => $row['code'],
            'level' => $row['level'],
        ];
        if ($row['level'] !== 3) {
            $parent = $this->records->findManagerByCode($row['parentCode']);
            if ($parent === null) {
                throw new RuntimeException('Demo parent ' . $row['parentCode'] . ' was not found.');
            }
            $input['parentType'] = $row['parentType'];
            $input['parentId'] = (int) $parent['id'];
        }
        $existing = $this->records->findManagerByCode($row['code']);
        if ($existing === null) {
            $this->office->createManager($input);

            return 'created';
        }
        $this->office->updateManager((int) $existing['id'], $input);

        return 'updated';
    }

    /** @param array<string, string> $row */
    private function saveStockist(array $row): string
    {
        $hq = $this->records->findHeadquartersByCode($row['headquartersCode']);
        if ($hq === null) {
            throw new RuntimeException('Demo headquarters ' . $row['headquartersCode'] . ' was not found.');
        }
        $input = [
            'name' => $row['name'],
            'code' => $row['code'],
            'headquartersId' => (int) $hq['id'],
        ];
        $existing = $this->records->findStockistByCode($row['code']);
        if ($existing === null) {
            $this->office->createStockist($input);

            return 'created';
        }
        $this->office->updateStockist((int) $existing['id'], $input);

        return 'updated';
    }

    /** @return list<array<string, string>> */
    private function headquarters(): array
    {
        return $this->rows()['headquarters'];
    }

    /** @return list<array<string, mixed>> */
    private function managers(): array
    {
        return $this->rows()['managers'];
    }

    /** @return list<array<string, string>> */
    private function stockists(): array
    {
        return $this->rows()['stockists'];
    }

    /**
     * Shyam, four 2nd line managers, and four 1st line managers under Shubham.
     * Only the first of those (Aarav Mehta) has headquarters and stockists.
     * Codes stay stable so a second run updates the same rows.
     *
     * @return array{managers: list<array<string, mixed>>, headquarters: list<array<string, string>>, stockists: list<array<string, string>>}
     */
    private function rows(): array
    {
        $managers = [
            ['code' => 'ER-M3-001', 'name' => 'Shyam', 'level' => 3],
            ['code' => 'ER-M2-001', 'name' => 'Shubham', 'level' => 2, 'parentType' => 'manager3', 'parentCode' => 'ER-M3-001'],
            ['code' => 'ER-M2-002', 'name' => 'Ali', 'level' => 2, 'parentType' => 'manager3', 'parentCode' => 'ER-M3-001'],
            ['code' => 'ER-M2-003', 'name' => 'Mohit', 'level' => 2, 'parentType' => 'manager3', 'parentCode' => 'ER-M3-001'],
            ['code' => 'ER-M2-004', 'name' => 'Raj', 'level' => 2, 'parentType' => 'manager3', 'parentCode' => 'ER-M3-001'],
            ['code' => 'ER-M1-001', 'name' => 'Aarav Mehta', 'level' => 1, 'parentType' => 'manager2', 'parentCode' => 'ER-M2-001'],
            ['code' => 'ER-M1-002', 'name' => 'Neha Shah', 'level' => 1, 'parentType' => 'manager2', 'parentCode' => 'ER-M2-001'],
            ['code' => 'ER-M1-003', 'name' => 'Vikram Patel', 'level' => 1, 'parentType' => 'manager2', 'parentCode' => 'ER-M2-001'],
            ['code' => 'ER-M1-004', 'name' => 'Pooja Desai', 'level' => 1, 'parentType' => 'manager2', 'parentCode' => 'ER-M2-001'],
        ];
        $headquarters = [
            [
                'code' => 'DEMO-HQ-01',
                'name' => 'HQ 1',
                'parentCode' => 'ER-M1-001',
                'state' => 'Gujarat',
                'address' => 'Demo Estate, Navrangpura',
                'pincode' => '380009',
                'phone' => '9000000001',
            ],
            [
                'code' => 'DEMO-HQ-02',
                'name' => 'HQ 2',
                'parentCode' => 'ER-M1-001',
                'state' => 'Gujarat',
                'address' => 'Demo Estate, Athwa',
                'pincode' => '395001',
                'phone' => '9000000002',
            ],
        ];
        $stockists = [
            ['code' => 'DEMO-STK-01', 'name' => 'Stockist 1', 'headquartersCode' => 'DEMO-HQ-01'],
            ['code' => 'DEMO-STK-02', 'name' => 'Stockist 2', 'headquartersCode' => 'DEMO-HQ-01'],
            ['code' => 'DEMO-STK-03', 'name' => 'Stockist 3', 'headquartersCode' => 'DEMO-HQ-01'],
            ['code' => 'DEMO-STK-04', 'name' => 'Stockist 4', 'headquartersCode' => 'DEMO-HQ-02'],
            ['code' => 'DEMO-STK-05', 'name' => 'Stockist 5', 'headquartersCode' => 'DEMO-HQ-02'],
        ];

        return [
            'managers' => $managers,
            'headquarters' => $headquarters,
            'stockists' => $stockists,
        ];
    }
}
