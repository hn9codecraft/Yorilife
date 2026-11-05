<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Services\ImportTypes;
use App\Services\ImportXlsx;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Single-sheet Excel import: every Sales Office data type in one file, one preview, one confirm, one rollback. */
final class ExcelImportTest extends FeatureTestCase
{
    public function testTypesTemplateAndPermissions(): void
    {
        $sales = $this->signedInAs('sales_manager');
        self::assertError($sales->request('GET', '/api/imports/template'), 403, 'forbidden');

        $admin = $this->signedInAs('admin');
        $types = $admin->request('GET', '/api/imports/types');
        self::assertSame(200, $types['status'], $types['raw']);
        self::assertArrayNotHasKey('types', $types['body']['data'], 'the per-type CSV import is gone');
        self::assertSame(2000, $types['body']['data']['unified']['maxRows']);
        self::assertSame(ImportTypes::UNIFIED_COLUMNS, $types['body']['data']['unified']['columns']);
        self::assertCount(7, $types['body']['data']['unified']['dataTypes']);

        $template = $admin->request('GET', '/api/imports/template');
        self::assertSame(200, $template['status']);
        $sheet = ImportXlsx::read($template['raw']);
        self::assertSame(ImportTypes::UNIFIED_COLUMNS, $sheet['headers']);
        self::assertCount(7, $sheet['rows']);
    }

    public function testFileLevelValidation(): void
    {
        $admin = $this->signedInAs('admin');
        $good = self::xlsx([['Location', 'XA', 'Xland']]);
        self::assertError($this->upload($admin, "Location Code,State Name\r\nXA,Xland\r\n", 'states.csv', 'location'), 422, 'validation_failed');
        [$body, $headers] = self::multipart($good, 'data.xlsx', 'unified');
        self::assertSame(403, $admin->request('POST', '/api/imports', $body, $headers, false)['status'], 'CSRF token is required');
        self::assertError($this->upload($admin, $good, 'data.csv'), 422, 'validation_failed');
        self::assertError($this->upload($admin, $good, 'data.xlsm'), 422, 'validation_failed');
        self::assertError($this->upload($admin, 'Location Code,State Name', 'data.xlsx'), 422, 'validation_failed');
        self::assertError($this->upload($admin, ImportXlsx::write(['Import' => [['Data Type', 'Code'], ['Location', 'XA']]])), 422, 'validation_failed');
        self::assertError($this->upload($admin, ImportXlsx::write(['Import' => [[...ImportTypes::UNIFIED_COLUMNS, 'Extra'], ['Location', 'XA']]])), 422, 'validation_failed');
        self::assertError($this->upload($admin, self::xlsx([])), 422, 'validation_failed');
        $many = [];
        for ($i = 0; $i < 2001; $i++) {
            $many[] = ['Location', "Z{$i}", "Zone {$i}"];
        }
        $response = $this->upload($admin, self::xlsx($many));
        self::assertError($response, 422, 'validation_failed');
        self::assertStringContainsString('2000', $response['body']['error']['details']['fields']['file']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM imports'));
    }

    public function testShuffledFullHierarchyPreviewConfirmAndRollback(): void
    {
        $admin = $this->signedInAs('admin');
        // Children come before their parents in the file; the import sorts them by type.
        $rows = [
            ['Stockist', 'ST-9001', 'Shree Medico', 'HQ-901', '', 'Station Road', '380002', '9876500003', 'medico@example.com', ''],
            ['Headquarters', 'HQ-901', '', 'ER-M1-9', '', '', '', '', '', ''],
            ['1st Line Manager', 'ER-M1-9', 'Asha', 'ER-M2-9', 'XG', '', '', '', '', ''],
            ['2nd Line Manager', 'ER-M2-9', 'Ravi', 'ER-M3-9', 'XG', '', '', '', '', ''],
            ['3rd Line Manager', 'ER-M3-9', 'Shyam', '', 'XG', '', '', '9876543210', '', ''],
            ['Location HQ', 'HQ-901', 'Xcity', 'XG', '', 'Ring Road', '380001', '9876543210', 'xcity@example.com', ''],
            ['location', 'XG', 'Xgujarat', '', '', 'ignored address', '', '', '', 'a note'],
        ];
        $upload = $this->upload($admin, self::xlsx($rows));
        self::assertSame(201, $upload['status'], $upload['raw']);
        $import = $upload['body']['data']['import'];
        self::assertSame(0, $import['errorRows'], $upload['raw']);
        self::assertSame(7, $import['created'], $upload['raw']);
        self::assertSame('All data (Excel)', $import['typeLabel']);
        self::assertSame(0, $this->codeCount('locations', 'XG'), 'preview saves nothing');
        $preview = $upload['body']['data']['import']['preview'];
        self::assertSame(range(2, 8), array_column($preview, 'line'));
        $location = $preview[6];
        self::assertSame('location', $location['type']);
        self::assertNotEmpty($location['warnings'], 'Address is ignored for a Location row');

        $confirm = $admin->request('POST', '/api/imports/' . $import['id'] . '/confirm', []);
        self::assertSame(200, $confirm['status'], $confirm['raw']);
        self::assertSame(7, $confirm['body']['data']['import']['created']);
        self::assertSame(1, $this->codeCount('locations', 'XG'));
        self::assertSame(1, $this->codeCount('stockists', 'ST-9001'));
        self::assertSame(3, (int) $this->scalar("SELECT COUNT(*) FROM managers WHERE code LIKE 'ER-M_-9'"));
        self::assertSame(7, (int) $this->scalar('SELECT COUNT(*) FROM import_changes WHERE import_id = ' . (int) $import['id'] . ' AND data_type IS NOT NULL'));

        $original = $admin->request('GET', '/api/imports/' . $import['id'] . '/file');
        self::assertSame(200, $original['status']);
        self::assertStringStartsWith("PK\x03\x04", $original['raw']);

        $rollback = $admin->request('POST', '/api/imports/' . $import['id'] . '/rollback', []);
        self::assertSame(200, $rollback['status'], $rollback['raw']);
        self::assertSame('rolled_back', $rollback['body']['data']['rollback']['status'], $rollback['raw']);
        self::assertSame(0, $this->codeCount('locations', 'XG'));
        self::assertSame(0, $this->codeCount('stockists', 'ST-9001'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM managers WHERE code LIKE 'ER-M_-9'"));
    }

    public function testRowErrorsParentsDuplicatesAndErrorReport(): void
    {
        $admin = $this->signedInAs('admin');
        $rows = [
            ['Doctor', 'D-1', 'Unknown type', '', '', '', '', '', '', ''],
            ['Location', 'XK', '', '', '', '', '', '', '', ''],
            ['Location HQ', 'HQ-902', 'Kcity', 'XK', '', 'Main Road', '380001', '9876543210', 'k@example.com', ''],
            ['Stockist', 'ST-9002', 'Orphan', 'HQ-NOPE', '', '', '', '', '', ''],
            ['Location', 'XM', 'Mland', '', '', '', '', '', '', ''],
            ['Location', 'XM', 'Mland again', '', '', '', '', '', '', ''],
            ['3rd Line Manager', 'ER-M3-8', 'Lead', '', 'XM', '', '', '', '', ''],
        ];
        $upload = $this->upload($admin, self::xlsx($rows));
        self::assertSame(201, $upload['status'], $upload['raw']);
        $import = $upload['body']['data']['import'];
        $errors = $upload['body']['data']['import']['errors'];
        $byLine = [];
        foreach ($errors as $error) {
            $byLine[$error['line']][] = $error;
        }
        self::assertArrayHasKey(2, $byLine, 'unknown Data Type');
        self::assertSame('Data Type', $byLine[2][0]['field']);
        self::assertArrayHasKey(3, $byLine, 'missing required Name');
        self::assertArrayHasKey(4, $byLine, 'child of a failed parent fails too');
        self::assertStringContainsString('Parent row 3', implode(' ', array_column($byLine[4], 'message')));
        self::assertSame('location-headquarters', $byLine[4][0]['type']);
        self::assertArrayHasKey(5, $byLine, 'unknown parent');
        self::assertArrayHasKey(7, $byLine, 'duplicate code');
        self::assertArrayNotHasKey(6, $byLine);
        self::assertArrayNotHasKey(8, $byLine);

        $report = $admin->request('GET', '/api/imports/' . $import['id'] . '/errors');
        self::assertSame(200, $report['status']);
        self::assertStringContainsString('Row,Data Type,Column,Error,Data Type,Code', $report['raw']);
        self::assertStringContainsString('Location HQ', $report['raw']);

        // Only the valid rows are saved on confirm.
        $confirm = $admin->request('POST', '/api/imports/' . $import['id'] . '/confirm', []);
        self::assertSame(200, $confirm['status'], $confirm['raw']);
        self::assertSame(1, $this->codeCount('locations', 'XM'));
        self::assertSame('Mland', $this->scalar("SELECT name FROM locations WHERE code = 'XM'"));
        self::assertSame(1, $this->codeCount('managers', 'ER-M3-8'));
        self::assertSame(0, $this->codeCount('locations', 'XK'));
        self::assertSame(0, $this->codeCount('stockists', 'ST-9002'));
    }

    public function testFormulaCellsFailTheirRow(): void
    {
        $admin = $this->signedInAs('admin');
        $file = self::xlsx([['Location', 'XF', 'Fland']]);
        $file = self::withSheet($file, '<row r="1">' . self::headerCells() . '</row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>Location</t></is></c><c r="B2" t="inlineStr"><is><t>XF</t></is></c><c r="C2" t="str"><f>"F"&amp;"land"</f><v>Fland</v></c></row>'
            . '<row r="3" hidden="1"><c r="A3" t="inlineStr"><is><t>Location</t></is></c><c r="B3" t="inlineStr"><is><t>XH</t></is></c><c r="C3" t="inlineStr"><is><t>Hland</t></is></c></row>'
            . '<row r="4"><c r="A4" t="inlineStr"><is><t>Location HQ</t></is></c><c r="B4" t="inlineStr"><is><t>HQ-903</t></is></c><c r="C4" t="inlineStr"><is><t>Pcity</t></is></c><c r="D4" t="inlineStr"><is><t>XH</t></is></c><c r="F4" t="inlineStr"><is><t>Road</t></is></c><c r="G4"><v>380001</v></c><c r="H4"><v>9876543210</v></c><c r="I4" t="inlineStr"><is><t>p@example.com</t></is></c></row>');
        $upload = $this->upload($admin, $file);
        self::assertSame(201, $upload['status'], $upload['raw']);
        self::assertSame(1, $upload['body']['data']['import']['errorRows'], $upload['raw']);
        self::assertSame(2, $upload['body']['data']['import']['errors'][0]['line']);
        $hidden = array_values(array_filter($upload['body']['data']['import']['preview'], static fn (array $r): bool => $r['line'] === 3))[0];
        self::assertNotEmpty($hidden['warnings'], 'hidden row is imported with a warning');
        $id = $upload['body']['data']['import']['id'];
        self::assertSame(200, $admin->request('POST', '/api/imports/' . $id . '/confirm', [])['status']);
        self::assertSame('380001', $this->scalar("SELECT pincode FROM location_headquarters WHERE code = 'HQ-903'"), 'numeric pincode is read as plain text');
        self::assertSame('9876543210', $this->scalar("SELECT phone FROM location_headquarters WHERE code = 'HQ-903'"));
        self::assertSame(0, $this->codeCount('locations', 'XF'));
    }

    /** @param list<list<string>> $rows */
    private static function xlsx(array $rows): string
    {
        $padded = array_map(static fn (array $r): array => array_pad($r, count(ImportTypes::UNIFIED_COLUMNS), ''), $rows);

        return ImportXlsx::write(['Instructions' => [['Help only']], 'Import' => [ImportTypes::UNIFIED_COLUMNS, ...$padded]]);
    }

    private static function headerCells(): string
    {
        $out = '';
        foreach (ImportTypes::UNIFIED_COLUMNS as $i => $header) {
            $out .= '<c r="' . ImportXlsx::letter($i) . '1" t="inlineStr"><is><t>' . $header . '</t></is></c>';
        }

        return $out;
    }

    /** Replace the Import sheet XML (sheet2 in the generated file). */
    private static function withSheet(string $file, string $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsxft');
        file_put_contents($path, $file);
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
        $zip->close();
        $out = (string) file_get_contents($path);
        unlink($path);

        return $out;
    }

    private function upload(ApiClient $client, string $content, string $filename = 'sales-office.xlsx', string $type = 'unified'): array
    {
        [$body, $headers] = self::multipart($content, $filename, $type);

        return $client->request('POST', '/api/imports', $body, $headers);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function multipart(string $content, string $filename, string $type): array
    {
        $boundary = '----yorilife' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\n{$type}\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$content}\r\n"
            . "--{$boundary}--\r\n";

        return [$body, ['Content-Type' => "multipart/form-data; boundary={$boundary}"]];
    }

    private function scalar(string $sql): mixed
    {
        return Database::connection()->query($sql)->fetchColumn();
    }

    private function codeCount(string $table, string $code): int
    {
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM {$table} WHERE code = ?");
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }
}