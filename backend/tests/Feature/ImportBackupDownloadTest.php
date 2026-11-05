<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Services\ImportBackupCsv;
use App\Services\ImportCsv;
use App\Services\ImportTypes;
use App\Services\ImportXlsx;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** The Backup download in Import History: a readable CSV; backup.json on disk stays for rollback. */
final class ImportBackupDownloadTest extends FeatureTestCase
{
    public function testBackupDownloadIsReadableCsvAndJsonStaysForRollback(): void
    {
        $admin = $this->signedInAs('admin');
        $this->importAndConfirm($admin, [
            ['Location', 'XA', 'Aland'],
            ['3rd Line Manager', 'ER-M3-7', 'Shyam', '', 'XA', '', '', '9876543210', '', ''],
        ]);
        $id = $this->importAndConfirm($admin, [
            ['Location', 'XA', 'Aland renamed'],
            ['Location', 'XB', 'Bland'],
            ['3rd Line Manager', 'ER-M3-7', 'Shyam', '', 'XA|XB', '', '', '9876500000', '', ''],
            ['Location HQ', 'HQ-960', 'Acity', 'XA', '', 'Main Road', '380001', '9876543210', 'a@example.com', ''],
        ]);
        $jsonPath = self::backupPath($id);
        $jsonBefore = (string) file_get_contents($jsonPath);

        $download = $admin->request('GET', "/api/imports/{$id}/backup");
        self::assertSame(200, $download['status'], $download['raw']);
        self::assertStringStartsWith('text/csv', $download['headers']['content-type']);
        self::assertStringContainsString("import-{$id}-backup.csv", $download['headers']['content-disposition']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $download['raw'], 'BOM keeps Excel on UTF-8');
        $rows = ImportCsv::parse($download['raw'])['rows'];
        $columns = ImportBackupCsv::columns();
        self::assertSame($columns, $rows[0]);
        self::assertContains('Previous Name', $columns);
        self::assertContains('New Name', $columns);
        self::assertCount(3, $rows, 'only the 2 replaced records; created XB and HQ-960 are left out');
        $byCode = [];
        foreach (array_slice($rows, 1) as $row) {
            $byCode[$row[1]] = array_combine($columns, $row);
        }
        self::assertSame(['XA', 'ER-M3-7'], array_keys($byCode));
        $location = $byCode['XA'];
        self::assertSame(['Location', 'Aland', 'Aland renamed', 'Name'], [$location['Data Type'], $location['Previous Name'], $location['New Name'], $location['Changed Fields']]);
        $manager = $byCode['ER-M3-7'];
        self::assertSame('3rd Line Manager', $manager['Data Type']);
        self::assertSame(['Shyam', 'Shyam'], [$manager['Previous Name'], $manager['New Name']], 'unchanged fields show the same value');
        self::assertSame(['XA', 'XA|XB'], [$manager['Previous State List'], $manager['New State List']]);
        self::assertSame(["\t9876543210", "\t9876500000"], [$manager['Previous Phone Number'], $manager['New Phone Number']], 'a tab keeps Excel from showing 9.88E+09');
        self::assertStringContainsString("\"\t9876500000\"", $download['raw']);
        self::assertDoesNotMatchRegularExpression('/(^|,)[0-9]{10}(,|\r)/m', $download['raw'], 'no bare 10-digit number Excel would turn into 9.88E+09');
        self::assertSame('State List, Phone Number', $manager['Changed Fields']);
        self::assertSame('', $manager['Notes']);
        self::assertStringNotContainsString('Bland', $download['raw']);
        self::assertStringNotContainsString('HQ-960', $download['raw']);
        foreach (['location_id', 'parent_manager_id', '_states', '{"', 'SELECT'] as $raw) {
            self::assertStringNotContainsString($raw, $download['raw'], 'no raw field names or JSON');
        }
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM import_audit_logs WHERE import_id = {$id} AND action = 'backup_download'")->fetchColumn());

        // backup.json and its checksum are unchanged, and rollback still uses them.
        self::assertSame($jsonBefore, (string) file_get_contents($jsonPath));
        self::assertSame(hash('sha256', $jsonBefore), Database::connection()->query("SELECT backup_sha256 FROM imports WHERE id = {$id}")->fetchColumn());
        $rollback = $admin->request('POST', "/api/imports/{$id}/rollback", []);
        self::assertSame(200, $rollback['status'], $rollback['raw']);
        self::assertSame('rolled_back', $rollback['body']['data']['rollback']['status'], $rollback['raw']);
        self::assertSame($jsonBefore, (string) file_get_contents($jsonPath));
        $after = $admin->request('GET', "/api/imports/{$id}/backup");
        self::assertSame(200, $after['status'], 'existing behaviour: the backup can still be downloaded after a rollback');
        self::assertStringContainsString('Rolled back: the previous values were put back.', $after['raw']);
    }

    public function testLongNumbersStayTextAndHiddenOnlyChangesAreLeftOut(): void
    {
        $admin = $this->signedInAs('admin');
        $this->importAndConfirm($admin, [
            ['Location', 'XN', 'Nland'],
            ['3rd Line Manager', 'ER-M3-108', 'Nirav Patel', '', 'XN', '', '', '9876510802', 'nirav@example.com', ''],
            ['2nd Line Manager', 'ER-M2-108', 'Ravi', 'ER-M3-108', 'XN', '', '', '', '', ''],
            ['1st Line Manager', 'ER-M1-108', 'Asha', 'ER-M2-108', 'XN', '', '', '', '', ''],
            ['Location HQ', 'HQ-108', 'Ncity', 'XN', '', 'Old Road', '380001', '9876510803', 'hq@example.com', ''],
            ['Headquarters', 'HQ-108', '', 'ER-M1-108', '', '', '', '', '', ''],
            ['Stockist', 'ST-108', 'Medico', 'HQ-108', '', 'Station Road', '380002', '9876510804', '', ''],
        ]);
        $id = $this->importAndConfirm($admin, [
            ['3rd Line Manager', 'ER-M3-108', 'Nirav Patel', '', 'XN', '', '', '9876510812', 'nirav@example.com', ''],
            ['Location HQ', 'HQ-108', 'Ncity', 'XN', '', 'New Road', '380009', '9876510813', 'hq@example.com', ''],
            ['Stockist', 'ST-108', 'Medico', 'HQ-108', '', 'Station Road', '380002', '9876510814', '', ''],
        ]);

        $download = $admin->request('GET', "/api/imports/{$id}/backup");
        self::assertSame(200, $download['status'], $download['raw']);
        $columns = ImportBackupCsv::columns();
        $rows = array_map(static fn (array $r): array => array_combine($columns, $r), array_slice(ImportCsv::parse($download['raw'])['rows'], 1));
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM import_changes WHERE import_id = {$id} AND data_type = 'headquarters' AND action = 'updated'")->fetchColumn(), 'the linked headquarters row was updated too');
        $rows = array_column($rows, null, 'Data Type');
        self::assertEqualsCanonicalizing(['3rd Line Manager', 'Location HQ', 'Stockist'], array_keys($rows), 'the headquarters copy of the Location HQ change is not listed');
        $rows = [$rows['3rd Line Manager'], $rows['Location HQ'], $rows['Stockist']];
        foreach ($rows as $row) {
            self::assertNotSame('', $row['Changed Fields']);
        }
        self::assertSame(['ER-M3-108', "\t9876510802", "\t9876510812", 'Phone Number'], [$rows[0]['Code'], $rows[0]['Previous Phone Number'], $rows[0]['New Phone Number'], $rows[0]['Changed Fields']]);
        self::assertSame(["\t380001", "\t380009"], [$rows[1]['Previous Pincode'], $rows[1]['New Pincode']]);
        self::assertSame(["\t9876510804", "\t9876510814"], [$rows[2]['Previous Phone Number'], $rows[2]['New Phone Number']]);
        foreach (['9876510802', '9876510812', '9876510813', '9876510814', '380009'] as $number) {
            self::assertStringContainsString("\"\t{$number}\"", $download['raw']);
        }
        self::assertDoesNotMatchRegularExpression('/(^|,)[0-9]{6,}(,|\r)/m', $download['raw'], 'no bare long number Excel would coerce');
    }

    public function testFormulaSafetyStillAppliesWithDigitCells(): void
    {
        self::assertSame("\"\t9876510812\",'=1+1,'\t5,+91 98765 43210,12345\r\n", ImportCsv::line(['9876510812', '=1+1', "\t5", '+91 98765 43210', '12345'], true));
        self::assertSame("9876510812\r\n", ImportCsv::line(['9876510812']), 'other CSV downloads are unchanged');
    }

    public function testBackupDownloadErrorsAndPermissions(): void
    {
        $admin = $this->signedInAs('admin');
        $id = $this->importAndConfirm($admin, [['Location', 'XC', 'Cland']]);
        // An import that only created records still gives a valid file with a clear note.
        $created = $admin->request('GET', "/api/imports/{$id}/backup");
        self::assertSame(200, $created['status'], $created['raw']);
        $rows = ImportCsv::parse($created['raw'])['rows'];
        self::assertSame(ImportBackupCsv::columns(), $rows[0]);
        self::assertCount(2, $rows);
        self::assertSame(ImportBackupCsv::NO_REPLACEMENTS, end($rows[1]));
        self::assertStringNotContainsString('Cland', $created['raw']);

        $sales = $this->signedInAs('sales_manager', 'backup-sales@example.com');
        self::assertError($sales->request('GET', "/api/imports/{$id}/backup"), 403, 'forbidden');

        $previewOnly = $this->upload($admin, [['Location', 'XD', 'Dland']]);
        self::assertError($admin->request('GET', "/api/imports/{$previewOnly}/backup"), 404, 'not_found');

        $path = self::backupPath($id);
        $original = (string) file_get_contents($path);
        try {
            file_put_contents($path, $original . ' ');
            self::assertError($admin->request('GET', "/api/imports/{$id}/backup"), 409, 'backup_integrity_failed');
        } finally {
            file_put_contents($path, $original);
        }
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM import_audit_logs WHERE action = 'backup_download'")->fetchColumn(), 'only the successful download is logged');
    }

    /** backup.json as the test server stores it (TestServer sets IMPORT_STORAGE_PATH). */
    private static function backupPath(int $id): string
    {
        return dirname(__DIR__, 2) . '/storage/test-imports/' . $id . '/backup.json';
    }

    /** @param list<list<string>> $rows */
    private function importAndConfirm(ApiClient $client, array $rows): int
    {
        $id = $this->upload($client, $rows);
        $confirm = $client->request('POST', "/api/imports/{$id}/confirm", []);
        self::assertSame(200, $confirm['status'], $confirm['raw']);
        self::assertSame(0, $confirm['body']['data']['import']['errorRows'], $confirm['raw']);

        return $id;
    }

    /** @param list<list<string>> $rows */
    private function upload(ApiClient $client, array $rows): int
    {
        $padded = array_map(static fn (array $r): array => array_pad($r, count(ImportTypes::UNIFIED_COLUMNS), ''), $rows);
        $file = ImportXlsx::write(['Import' => [ImportTypes::UNIFIED_COLUMNS, ...$padded]]);
        $boundary = '----yorilife' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\nunified\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"data.xlsx\"\r\nContent-Type: application/octet-stream\r\n\r\n{$file}\r\n"
            . "--{$boundary}--\r\n";
        $response = $client->request('POST', '/api/imports', $body, ['Content-Type' => "multipart/form-data; boundary={$boundary}"]);
        self::assertSame(201, $response['status'], $response['raw']);

        return (int) $response['body']['data']['import']['id'];
    }
}
