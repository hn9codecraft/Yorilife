<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Services\ImportTypes;
use App\Services\ImportXlsx;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Choosing which records of an import to roll back, and the links between created records. */
final class ExcelImportRollbackSelectionTest extends FeatureTestCase
{
    private const CHAIN = [
        ['Location', 'XL', 'Lland'],
        ['3rd Line Manager', 'ER-M3-70', 'Third', '', 'XL'],
        ['2nd Line Manager', 'ER-M2-70', 'Second', 'ER-M3-70', 'XL'],
        ['1st Line Manager', 'ER-M1-70', 'First', 'ER-M2-70', 'XL'],
        ['Location HQ', 'HQ-970', 'Lcity', 'XL', '', 'Main Road', '380001', '9876543210', 'l@example.com'],
        ['Headquarters', 'HQ-970', '', 'ER-M1-70'],
        ['Stockist', 'ST-970', 'Medico', 'HQ-970', '', 'Station Road', '380002', '9876543211'],
    ];

    public function testPreviewListsTheLinkedRecordsForEveryChain(): void
    {
        $admin = $this->signedInAs('admin');
        $id = $this->importAndConfirm($admin, self::CHAIN);
        $items = $this->previewItems($admin, $id);

        // Direct links (the UI follows them for every level).
        self::assertSame([], $this->childKeys($items, 'stockists ST-970'), 'Stockist is a leaf');
        self::assertSame(['stockists ST-970'], $this->childKeys($items, 'headquarters HQ-970'));
        self::assertSame(['headquarters HQ-970'], $this->childKeys($items, 'location_headquarters HQ-970'));
        self::assertSame(['headquarters HQ-970'], $this->childKeys($items, 'managers ER-M1-70'));
        self::assertSame(['managers ER-M1-70'], $this->childKeys($items, 'managers ER-M2-70'));
        self::assertSame(['managers ER-M2-70'], $this->childKeys($items, 'managers ER-M3-70'));
        // Everything below a Location, through every chain.
        self::assertEqualsCanonicalizing(
            ['location_headquarters HQ-970', 'managers ER-M3-70', 'managers ER-M2-70', 'managers ER-M1-70', 'headquarters HQ-970', 'stockists ST-970'],
            $this->descendants($items, 'locations XL'),
        );
        self::assertEqualsCanonicalizing(['managers ER-M2-70', 'managers ER-M1-70', 'headquarters HQ-970', 'stockists ST-970'], $this->descendants($items, 'managers ER-M3-70'));
        self::assertEqualsCanonicalizing(['managers ER-M1-70', 'headquarters HQ-970', 'stockists ST-970'], $this->descendants($items, 'managers ER-M2-70'));
        self::assertEqualsCanonicalizing(['headquarters HQ-970', 'stockists ST-970'], $this->descendants($items, 'location_headquarters HQ-970'));

        // Selecting a parent with all its linked records deletes exactly that branch.
        $branch = $this->ids($items, ['managers ER-M2-70', 'managers ER-M1-70', 'headquarters HQ-970', 'stockists ST-970']);
        $rollback = $admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => $branch]);
        self::assertSame(200, $rollback['status'], $rollback['raw']);
        $result = $rollback['body']['data']['rollback'];
        self::assertSame(['partially_rolled_back', 4, 3, 0], [$result['status'], $result['deleted'], $result['notSelected'], $result['skipped']]);
        self::assertSame(0, $this->codes('managers', ['ER-M2-70', 'ER-M1-70']) + $this->codes('headquarters', ['HQ-970']) + $this->codes('stockists', ['ST-970']));
        self::assertSame(1, $this->codes('managers', ['ER-M3-70']), 'unselected parent stays as imported');
        self::assertSame(1, $this->codes('locations', ['XL']));
        self::assertSame(1, $this->codes('location_headquarters', ['HQ-970']));
        $details = json_decode((string) Database::connection()->query("SELECT details FROM import_audit_logs WHERE import_id = {$id} AND action = 'rollback'")->fetchColumn(), true);
        self::assertSame(['partial', 3], [$details['selection'], $details['notSelected']]);
        // The same records cannot be rolled back twice.
        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => $branch]), 422, 'validation_failed');
        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback/preview", ['changeIds' => $branch]), 422, 'validation_failed');
    }

    public function testLeftoversCanBeRolledBackLaterAndEveryRunIsListed(): void
    {
        $admin = $this->signedInAs('admin');
        $id = $this->importAndConfirm($admin, self::CHAIN);
        $items = $this->previewItems($admin, $id);
        $first = $admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => $this->ids($items, ['managers ER-M2-70', 'managers ER-M1-70', 'headquarters HQ-970', 'stockists ST-970'])]);
        self::assertSame(200, $first['status'], $first['raw']);
        self::assertSame(3, $first['body']['data']['rollback']['pendingLeft']);

        // The Rollback button stays: the import still has records to roll back.
        $row = $this->listed($admin, $id);
        self::assertSame(['partially_rolled_back', true, 3], [$row['status'], $row['canRollback'], $row['pendingRollback']]);
        self::assertCount(1, $row['rollbacks']);
        self::assertSame([0, 4, 0, 3], [$row['rollbacks'][0]['restored'], $row['rollbacks'][0]['deleted'], $row['rollbacks'][0]['skipped'], $row['rollbacks'][0]['notSelected']]);
        self::assertNotEmpty($row['rollbacks'][0]['by']);
        self::assertNotNull($row['rollbacks'][0]['at']);
        self::assertEqualsCanonicalizing(['ER-M2-70', 'ER-M1-70', 'HQ-970', 'ST-970'], array_column($row['rollbacks'][0]['items'], 'code'));
        self::assertSame(['Delete'], array_values(array_unique(array_column($row['rollbacks'][0]['items'], 'action'))));

        // A second preview offers only the records still pending, with their links.
        $left = $this->previewItems($admin, $id);
        self::assertEqualsCanonicalizing(['locations XL', 'managers ER-M3-70', 'location_headquarters HQ-970'], array_keys($left));
        self::assertEqualsCanonicalizing(['location_headquarters HQ-970', 'managers ER-M3-70'], $this->descendants($left, 'locations XL'));

        $second = $admin->request('POST', "/api/imports/{$id}/rollback", []);
        self::assertSame(200, $second['status'], $second['raw']);
        self::assertSame(['rolled_back', 3, 0, 0], [$second['body']['data']['rollback']['status'], $second['body']['data']['rollback']['deleted'], $second['body']['data']['rollback']['skipped'], $second['body']['data']['rollback']['notSelected']]);
        self::assertSame(0, $this->codes('locations', ['XL']) + $this->codes('managers', ['ER-M3-70']) + $this->codes('location_headquarters', ['HQ-970']));

        $row = $this->listed($admin, $id);
        self::assertSame(['rolled_back', false, 0], [$row['status'], $row['canRollback'], $row['pendingRollback']]);
        self::assertCount(2, $row['rollbacks']);
        self::assertEqualsCanonicalizing(['XL', 'ER-M3-70', 'HQ-970'], array_column($row['rollbacks'][1]['items'], 'code'));
        $detail = $admin->request('GET', "/api/imports/{$id}");
        self::assertCount(2, $detail['body']['data']['import']['rollbacks']);
        self::assertSame(2, (int) Database::connection()->query("SELECT COUNT(*) FROM import_audit_logs WHERE import_id = {$id} AND action = 'rollback'")->fetchColumn());

        // Nothing is left, so a further rollback or preview is refused.
        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback", []), 409, 'rollback_not_allowed');
        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback/preview", []), 409, 'rollback_not_allowed');
    }

    public function testSkippedRecordsStaySkippedAndAreNotOfferedAgain(): void
    {
        $admin = $this->signedInAs('admin');
        $id = $this->importAndConfirm($admin, self::CHAIN);
        $items = $this->previewItems($admin, $id);
        // Only the 3rd line: skipped because its children stay. Everything else stays pending.
        $first = $admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => $this->ids($items, ['managers ER-M3-70'])]);
        self::assertSame([0, 1, 6], [$first['body']['data']['rollback']['deleted'], $first['body']['data']['rollback']['skipped'], $first['body']['data']['rollback']['notSelected']], $first['raw']);
        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => $this->ids($items, ['managers ER-M3-70'])]), 422, 'validation_failed');
        self::assertArrayNotHasKey('managers ER-M3-70', $this->previewItems($admin, $id));

        // Rolling back the rest: the skipped 3rd line keeps the Location in place.
        $rest = $admin->request('POST', "/api/imports/{$id}/rollback", []);
        self::assertSame(200, $rest['status'], $rest['raw']);
        $row = $this->listed($admin, $id);
        self::assertSame(['partially_rolled_back', false, 0], [$row['status'], $row['canRollback'], $row['pendingRollback']]);
        self::assertSame(1, $this->codes('managers', ['ER-M3-70']));
        self::assertSame(1, $this->codes('locations', ['XL']));
        self::assertSame(0, $this->codes('stockists', ['ST-970']) + $this->codes('managers', ['ER-M2-70', 'ER-M1-70']));
        self::assertCount(2, $row['rollbacks']);
    }

    /** @return array<string, mixed> the import as the history list shows it */
    private function listed(ApiClient $admin, int $id): array
    {
        $list = $admin->request('GET', '/api/imports');
        self::assertSame(200, $list['status'], $list['raw']);
        foreach ($list['body']['data']['imports'] as $import) {
            if ((int) $import['id'] === $id) {
                return $import;
            }
        }
        self::fail("Import {$id} is not listed.");
    }

    public function testServerStillSkipsAParentWhoseChildrenWereNotSelected(): void
    {
        $admin = $this->signedInAs('admin');
        $id = $this->importAndConfirm($admin, self::CHAIN);
        $items = $this->previewItems($admin, $id);

        // A client that sends only the 3rd line and the Location HQ (not their children) cannot break links.
        $rollback = $admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => $this->ids($items, ['managers ER-M3-70', 'location_headquarters HQ-970', 'stockists ST-970'])]);
        self::assertSame(200, $rollback['status'], $rollback['raw']);
        $byKey = [];
        foreach ($rollback['body']['data']['rollback']['items'] as $item) {
            $byKey[$item['table'] . ' ' . $item['code']] = $item;
        }
        self::assertSame('deleted', $byKey['stockists ST-970']['result'], 'a leaf can go on its own');
        self::assertSame('skipped', $byKey['managers ER-M3-70']['result']);
        self::assertStringStartsWith('Not deleted:', $byKey['managers ER-M3-70']['note']);
        self::assertSame('skipped', $byKey['location_headquarters HQ-970']['result']);
        self::assertSame('not_selected', $byKey['managers ER-M2-70']['result']);
        self::assertSame('Not selected, so it was left as imported. It can be rolled back later.', $byKey['managers ER-M2-70']['note']);
        self::assertSame(1, $this->codes('managers', ['ER-M3-70']));
        self::assertSame(1, $this->codes('headquarters', ['HQ-970']));
        self::assertSame(0, $this->codes('stockists', ['ST-970']));
    }

    public function testRestoreDoesNotCascadeAndSelectionIsValidated(): void
    {
        $admin = $this->signedInAs('admin');
        $this->importAndConfirm($admin, [['Location', 'XR', 'Rland'], ['3rd Line Manager', 'ER-M3-71', 'Nirav Patel', '', 'XR', '', '', '9876510802']]);
        $id = $this->importAndConfirm($admin, [
            ['3rd Line Manager', 'ER-M3-71', 'Nirav Patel', '', 'XR', '', '', '9876510812'],
            ['2nd Line Manager', 'ER-M2-71', 'Below', 'ER-M3-71', 'XR'],
        ]);
        $items = $this->previewItems($admin, $id);
        self::assertSame([], $this->childKeys($items, 'managers ER-M3-71'), 'an updated record has no forced children');

        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => []]), 422, 'validation_failed');
        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => 'all']), 422, 'validation_failed');
        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => [999999]]), 422, 'validation_failed');
        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback/preview", ['changeIds' => []]), 422, 'validation_failed');
        $sales = $this->signedInAs('sales_manager', 'selection-sales@example.com');
        self::assertError($sales->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => $this->ids($items, ['managers ER-M3-71'])]), 403, 'forbidden');
        self::assertSame('completed', Database::connection()->query("SELECT status FROM imports WHERE id = {$id}")->fetchColumn());

        // The preview can show the plan for a selection without changing anything.
        $plan = $admin->request('POST', "/api/imports/{$id}/rollback/preview", ['changeIds' => $this->ids($items, ['managers ER-M3-71'])]);
        self::assertSame(200, $plan['status'], $plan['raw']);
        self::assertSame([1, 0, 1], [$plan['body']['data']['rollback']['restored'], $plan['body']['data']['rollback']['deleted'], $plan['body']['data']['rollback']['notSelected']]);

        $rollback = $admin->request('POST', "/api/imports/{$id}/rollback", ['changeIds' => $this->ids($items, ['managers ER-M3-71'])]);
        self::assertSame(200, $rollback['status'], $rollback['raw']);
        self::assertSame([1, 0, 1, 'partially_rolled_back'], [$rollback['body']['data']['rollback']['restored'], $rollback['body']['data']['rollback']['deleted'], $rollback['body']['data']['rollback']['notSelected'], $rollback['body']['data']['rollback']['status']]);
        self::assertSame('9876510802', Database::connection()->query("SELECT phone FROM managers WHERE code = 'ER-M3-71'")->fetchColumn());
        self::assertSame(1, $this->codes('managers', ['ER-M2-71']), 'the 2nd line is left as imported');
        self::assertSame('Restore', $this->listed($admin, $id)['rollbacks'][0]['items'][0]['action']);

        // Without changeIds, everything is rolled back as before.
        $all = $this->importAndConfirm($admin, [['Location', 'XS', 'Sland']]);
        $full = $admin->request('POST', "/api/imports/{$all}/rollback", []);
        self::assertSame('rolled_back', $full['body']['data']['rollback']['status'], $full['raw']);
        $details = json_decode((string) Database::connection()->query("SELECT details FROM import_audit_logs WHERE import_id = {$all} AND action = 'rollback'")->fetchColumn(), true);
        self::assertSame('all', $details['selection']);
    }

    /** @return array<string, array<string, mixed>> "table code" => item */
    private function previewItems(ApiClient $admin, int $id): array
    {
        $preview = $admin->request('POST', "/api/imports/{$id}/rollback/preview", []);
        self::assertSame(200, $preview['status'], $preview['raw']);
        $items = [];
        foreach ($preview['body']['data']['rollback']['items'] as $item) {
            $items[$item['table'] . ' ' . $item['code']] = $item;
        }

        return $items;
    }

    /** @return list<string> */
    private function childKeys(array $items, string $key): array
    {
        $byId = array_column($items, null, 'id');

        return array_map(static fn (int $child): string => $byId[$child]['table'] . ' ' . $byId[$child]['code'], $items[$key]['children']);
    }

    /** @return list<string> */
    private function descendants(array $items, string $key): array
    {
        $found = [];
        $queue = $this->childKeys($items, $key);
        while ($queue !== []) {
            $next = array_shift($queue);
            if (!in_array($next, $found, true)) {
                $found[] = $next;
                array_push($queue, ...$this->childKeys($items, $next));
            }
        }

        return $found;
    }

    /** @return list<int> */
    private function ids(array $items, array $keys): array
    {
        return array_map(static fn (string $key): int => $items[$key]['id'], $keys);
    }

    private function codes(string $table, array $codes): int
    {
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM {$table} WHERE code IN (" . implode(',', array_fill(0, count($codes), '?')) . ')');
        $stmt->execute($codes);

        return (int) $stmt->fetchColumn();
    }

    /** @param list<list<string>> $rows */
    private function importAndConfirm(ApiClient $client, array $rows): int
    {
        $padded = array_map(static fn (array $r): array => array_pad($r, count(ImportTypes::UNIFIED_COLUMNS), ''), $rows);
        $file = ImportXlsx::write(['Import' => [ImportTypes::UNIFIED_COLUMNS, ...$padded]]);
        $boundary = '----yorilife' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\nunified\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"data.xlsx\"\r\nContent-Type: application/octet-stream\r\n\r\n{$file}\r\n"
            . "--{$boundary}--\r\n";
        $upload = $client->request('POST', '/api/imports', $body, ['Content-Type' => "multipart/form-data; boundary={$boundary}"]);
        self::assertSame(201, $upload['status'], $upload['raw']);
        $id = (int) $upload['body']['data']['import']['id'];
        $confirm = $client->request('POST', "/api/imports/{$id}/confirm", []);
        self::assertSame(200, $confirm['status'], $confirm['raw']);
        self::assertSame(0, $confirm['body']['data']['import']['errorRows'], $confirm['raw']);

        return $id;
    }
}