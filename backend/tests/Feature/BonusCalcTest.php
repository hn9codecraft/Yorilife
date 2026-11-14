<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use Tests\Support\FeatureTestCase;

/** Bonus Calc list, month sheet, rate snapshot, totals and permissions. */
final class BonusCalcTest extends FeatureTestCase
{
    public function testBonusCalcFlowTotalsSnapshotAndPermissions(): void
    {
        $viewer = $this->signedInAs('sales_user', 'bonus-viewer@example.test');
        $listed = $viewer->request('GET', '/api/bonus-calc?financialYear=2026-27');
        self::assertSame(200, $listed['status'], $listed['raw']);
        self::assertSame('2026-27', $listed['body']['data']['financialYear']);
        self::assertSame([], $listed['body']['data']['rows']);
        self::assertError($viewer->request('POST', '/api/bonus-calc/rows', [
            'financialYear' => '2026-27',
            'employeeIds' => [1],
        ]), 403, 'forbidden');
        self::assertError($viewer->request('GET', '/api/bonus-calc?financialYear=2026-2027'), 422, 'validation_failed');

        $editor = $this->signedInAs('sales_manager', 'bonus-editor@example.test');
        $admin = $this->signedInAs('admin', 'bonus-admin@example.test');
        $asha = $this->employee($admin, 'Asha', 'Rao', 'EMP-1');
        $bina = $this->employee($admin, 'Bina', 'Shah', 'EMP-2');
        $cap = $admin->request('POST', '/api/products', [
            'name' => 'Yori Cap',
            'packagingUnit' => '10 x 10',
            'mrp' => '120.00',
            'ptr' => '96.00',
            'pts' => '86.00',
            'scheme' => '10+1',
            'netRate' => '80.00',
        ]);
        self::assertSame(201, $cap['status'], $cap['raw']);
        $capId = $cap['body']['data']['product']['id'];
        $tab = $admin->request('POST', '/api/products', [
            'name' => 'Yori Tab',
            'mrp' => '40.00',
            'netRate' => '10.50',
        ]);
        $tabId = $tab['body']['data']['product']['id'];
        $stockist = $admin->request('POST', '/api/stockists', [
            'name' => 'Ambica Medical',
            'code' => 'STK-1',
            'assignedEmployeeId' => $asha,
        ]);
        self::assertSame(201, $stockist['status'], $stockist['raw']);
        $stockistId = $stockist['body']['data']['stockist']['id'];

        $added = $editor->request('POST', '/api/bonus-calc/rows', [
            'financialYear' => '2026-27',
            'employeeIds' => [$asha, $bina],
        ]);
        self::assertSame(201, $added['status'], $added['raw']);
        self::assertCount(2, $added['body']['data']['rows']);
        $duplicate = $editor->request('POST', '/api/bonus-calc/rows', [
            'financialYear' => '2026-27',
            'employeeIds' => [$asha],
        ]);
        self::assertError($duplicate, 422, 'validation_failed');
        self::assertArrayHasKey('employeeIds', $duplicate['body']['error']['details']['fields']);

        $sheet = $editor->request('GET', '/api/bonus-calc/2026-27/' . $asha . '/4');
        self::assertSame(200, $sheet['status'], $sheet['raw']);
        self::assertSame('April', $sheet['body']['data']['monthLabel']);
        self::assertSame('Asha Rao', $sheet['body']['data']['employee']['name']);
        self::assertSame('EMP-1', $sheet['body']['data']['employee']['employeeCode']);
        self::assertCount(1, $sheet['body']['data']['stockists']);
        self::assertNull($sheet['body']['data']['stockists'][0]['headquartersName']);
        self::assertNull($sheet['body']['data']['stockists'][0]['headquartersCode']);
        self::assertCount(2, $sheet['body']['data']['stockists'][0]['lines']);
        self::assertFalse($sheet['body']['data']['stockists'][0]['lines'][0]['saved']);
        self::assertNull($sheet['body']['data']['stockists'][0]['lines'][0]['openingQty']);

        self::assertError($viewer->request('PUT', '/api/bonus-calc/2026-27/' . $asha . '/4', ['lines' => []]), 403, 'forbidden');
        self::assertError($editor->request('PUT', '/api/bonus-calc/2026-27/' . $asha . '/4', [
            'lines' => [[
                'stockistId' => $stockistId,
                'productId' => $capId,
                'openingQty' => '-1',
                'newPurchaseQty' => '0',
                'productSalesQty' => '1',
            ]],
        ]), 422, 'validation_failed');
        self::assertError($editor->request('PUT', '/api/bonus-calc/2026-27/' . $asha . '/4', [
            'lines' => [[
                'stockistId' => $stockistId,
                'productId' => $capId,
                'openingQty' => '1.234',
                'newPurchaseQty' => '0',
                'productSalesQty' => '1',
            ]],
        ]), 422, 'validation_failed');

        $saved = $editor->request('PUT', '/api/bonus-calc/2026-27/' . $asha . '/4', [
            'lines' => [
                [
                    'stockistId' => $stockistId,
                    'productId' => $capId,
                    'openingQty' => '10',
                    'newPurchaseQty' => '5.50',
                    'productSalesQty' => '4.00',
                ],
                [
                    'stockistId' => $stockistId,
                    'productId' => $tabId,
                    'openingQty' => '1.00',
                    'newPurchaseQty' => '1.00',
                    'productSalesQty' => '2.00',
                ],
            ],
        ]);
        self::assertSame(200, $saved['status'], $saved['raw']);
        $lines = $saved['body']['data']['stockists'][0]['lines'];
        $byName = [];
        foreach ($lines as $line) {
            $byName[$line['productName']] = $line;
        }
        self::assertSame('15.50', $byName['Yori Cap']['totalProduct']);
        self::assertSame('320.00', $byName['Yori Cap']['bonusAmount']);
        self::assertSame('80.00', $byName['Yori Cap']['netRate']);
        self::assertSame('10 x 10', $byName['Yori Cap']['packagingUnit']);
        self::assertSame('21.00', $byName['Yori Tab']['bonusAmount']);

        $renamed = $admin->request('PATCH', '/api/products/' . $capId, [
            'name' => 'Yori Cap',
            'netRate' => '10.00',
            'mrp' => '200.00',
        ]);
        self::assertSame(200, $renamed['status'], $renamed['raw']);
        $again = $editor->request('GET', '/api/bonus-calc/2026-27/' . $asha . '/4');
        $capLine = null;
        foreach ($again['body']['data']['stockists'][0]['lines'] as $line) {
            if ($line['productName'] === 'Yori Cap') {
                $capLine = $line;
            }
        }
        self::assertSame('80.00', $capLine['netRate']);
        self::assertSame('120.00', $capLine['mrp']);
        self::assertSame('320.00', $capLine['bonusAmount']);

        $list = $editor->request('GET', '/api/bonus-calc?financialYear=2026-27');
        $ashaRow = null;
        foreach ($list['body']['data']['rows'] as $row) {
            if ($row['employeeCode'] === 'EMP-1') {
                $ashaRow = $row;
            }
        }
        self::assertNotNull($ashaRow);
        self::assertSame('341.00', $ashaRow['months']['4']);
        self::assertNull($ashaRow['months']['1']);
        self::assertSame('341.00', $ashaRow['total']);

        $blocked = $admin->request('POST', '/api/stockists/' . $stockistId . '/delete', []);
        self::assertError($blocked, 422, 'validation_failed');

        $binaRow = null;
        foreach ($list['body']['data']['rows'] as $row) {
            if ($row['employeeCode'] === 'EMP-2') {
                $binaRow = $row;
            }
        }
        $swapped = $editor->request('PATCH', '/api/bonus-calc/rows/' . $ashaRow['id'], ['employeeId' => $bina]);
        self::assertError($swapped, 422, 'validation_failed');
        $removedBina = $editor->request('POST', '/api/bonus-calc/rows/' . $binaRow['id'] . '/delete', []);
        self::assertSame(200, $removedBina['status'], $removedBina['raw']);
        $swapped = $editor->request('PATCH', '/api/bonus-calc/rows/' . $ashaRow['id'], ['employeeId' => $bina]);
        self::assertSame(200, $swapped['status'], $swapped['raw']);
        $moved = $editor->request('GET', '/api/bonus-calc/2026-27/' . $bina . '/4');
        self::assertSame(200, $moved['status'], $moved['raw']);
        self::assertSame('Bina Shah', $moved['body']['data']['employee']['name']);
        self::assertSame('320.00', $this->bonusOf($moved['body']['data'], 'Yori Cap'));
        self::assertSame(404, $editor->request('GET', '/api/bonus-calc/2026-27/' . $asha . '/4')['status']);

        $binaOnList = null;
        foreach ($swapped['body']['data']['rows'] as $row) {
            if ((string) $row['employeeId'] === (string) $bina) {
                $binaOnList = $row;
            }
        }
        $gone = $editor->request('POST', '/api/bonus-calc/rows/' . $binaOnList['id'] . '/delete', []);
        self::assertSame(200, $gone['status'], $gone['raw']);
        self::assertSame([], $gone['body']['data']['rows']);
        $stillThere = $admin->request('GET', '/api/sales-employees');
        $codes = array_column($stillThere['body']['data']['employees'], 'employeeCode');
        self::assertContains('EMP-2', $codes);

        $restored = $editor->request('POST', '/api/bonus-calc/rows', [
            'financialYear' => '2026-27',
            'employeeIds' => [$bina],
        ]);
        self::assertSame(201, $restored['status'], $restored['raw']);
        self::assertSame('341.00', $restored['body']['data']['rows'][0]['total']);

        $pdo = Database::connection();
        $audit = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'sales' AND action = 'create' AND target_type = 'bonus_calc_row'")->fetchColumn();
        self::assertGreaterThan(0, $audit);
        $history = (int) $pdo->query("SELECT COUNT(*) FROM entity_changes WHERE entity_type = 'bonus_calc_line' AND field = 'Bonus amount'")->fetchColumn();
        self::assertGreaterThan(0, $history);
        $kept = $pdo->query('SELECT COUNT(*) FROM sales_employees WHERE employee_code = ' . $pdo->quote('EMP-2') . ' AND deleted_at IS NULL')->fetchColumn();
        self::assertSame(1, (int) $kept);
    }

    public function testBulkRemoveLeavesSavedMonthsAndNamesTheHeadquarters(): void
    {
        $viewer = $this->signedInAs('sales_user', 'bonus-bulk-view@example.test');
        $editor = $this->signedInAs('sales_manager', 'bonus-bulk@example.test');
        $admin = $this->signedInAs('admin', 'bonus-bulk-admin@example.test');
        $asha = $this->employee($admin, 'Asha', 'Rao', 'EMP-B1');
        $bina = $this->employee($admin, 'Bina', 'Shah', 'EMP-B2');
        $locations = $admin->request('GET', '/api/locations')['body']['data']['locations'];
        $gujarat = null;
        foreach ($locations as $location) {
            if ($location['name'] === 'Gujarat') {
                $gujarat = $location['id'];
            }
        }
        self::assertNotNull($gujarat);
        $offices = $admin->request('POST', '/api/location-headquarters', [
            'locationId' => $gujarat,
            'name' => 'Surat',
            'code' => '12',
        ]);
        self::assertSame(201, $offices['status'], $offices['raw']);
        $hqId = null;
        foreach ($offices['body']['data']['locations'] as $location) {
            foreach ($location['headquarters'] as $office) {
                if ($office['name'] === 'Surat') {
                    $hqId = $office['id'];
                }
            }
        }
        self::assertNotNull($hqId);
        $cap = $admin->request('POST', '/api/products', ['name' => 'Yori Cap', 'netRate' => '80.00']);
        $capId = $cap['body']['data']['product']['id'];
        $stockist = $admin->request('POST', '/api/stockists', [
            'name' => 'Krishna Pharma',
            'code' => 'surat 12',
            'locationHeadquartersId' => $hqId,
            'assignedEmployeeId' => $asha,
        ]);
        self::assertSame(201, $stockist['status'], $stockist['raw']);
        $stockistId = $stockist['body']['data']['stockist']['id'];
        $added = $editor->request('POST', '/api/bonus-calc/rows', [
            'financialYear' => '2026-27',
            'employeeIds' => [$asha, $bina],
        ]);
        self::assertSame(201, $added['status'], $added['raw']);
        $ids = array_column($added['body']['data']['rows'], 'id');
        $saved = $editor->request('PUT', '/api/bonus-calc/2026-27/' . $asha . '/4', ['lines' => [[
            'stockistId' => $stockistId,
            'productId' => $capId,
            'openingQty' => '1',
            'newPurchaseQty' => '0',
            'productSalesQty' => '2',
        ]]]);
        self::assertSame(200, $saved['status'], $saved['raw']);
        $section = $saved['body']['data']['stockists'][0];
        self::assertSame('Krishna Pharma', $section['name']);
        self::assertSame('surat 12', $section['code']);
        self::assertSame('Surat', $section['headquartersName']);
        self::assertSame('12', $section['headquartersCode']);

        self::assertError($viewer->request('POST', '/api/bonus-calc/rows/delete', [
            'financialYear' => '2026-27',
            'ids' => $ids,
        ]), 403, 'forbidden');
        self::assertError($editor->request('POST', '/api/bonus-calc/rows/delete', [
            'financialYear' => '2026-27',
            'ids' => [],
        ]), 422, 'validation_failed');

        $pdo = Database::connection();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM bonus_calc_lines')->fetchColumn();
        $removed = $editor->request('POST', '/api/bonus-calc/rows/delete', [
            'financialYear' => '2026-27',
            'ids' => $ids,
        ]);
        self::assertSame(200, $removed['status'], $removed['raw']);
        self::assertSame([], $removed['body']['data']['rows']);
        self::assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM bonus_calc_lines')->fetchColumn());

        $restored = $editor->request('POST', '/api/bonus-calc/rows', [
            'financialYear' => '2026-27',
            'employeeIds' => [$asha],
        ]);
        self::assertSame('160.00', $restored['body']['data']['rows'][0]['total']);
        $blocked = $editor->request('POST', '/api/bonus-calc/rows/delete', [
            'financialYear' => '2026-27',
            'ids' => [$ids[0], 999999],
        ]);
        self::assertError($blocked, 422, 'validation_failed');
        self::assertCount(1, $editor->request('GET', '/api/bonus-calc?financialYear=2026-27')['body']['data']['rows']);
    }

    /** @param array<string, mixed> $sheet */
    private function bonusOf(array $sheet, string $name): string
    {
        foreach ($sheet['stockists'][0]['lines'] as $line) {
            if ($line['productName'] === $name) {
                return (string) $line['bonusAmount'];
            }
        }
        self::fail('Missing product ' . $name);
    }

    private function employee(\Tests\Support\ApiClient $client, string $first, string $last, string $code): int
    {
        $response = $client->request('POST', '/api/sales-employees', [
            'firstName' => $first,
            'lastName' => $last,
            'employeeCode' => $code,
        ]);
        self::assertSame(201, $response['status'], $response['raw']);

        return (int) $response['body']['data']['employee']['id'];
    }
}
