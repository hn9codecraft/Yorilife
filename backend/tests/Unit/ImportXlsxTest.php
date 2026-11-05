<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImportTypes;
use App\Services\ImportXlsx;
use App\Utils\HttpException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Safe .xlsx reader/writer used by the single-sheet Excel import. */
final class ImportXlsxTest extends TestCase
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function testTemplateRoundTripKeepsTextAndLeadingZeros(): void
    {
        $file = ImportXlsx::write([
            'Instructions' => [['Help'], ['Only the Import sheet is read']],
            'Import' => [ImportTypes::UNIFIED_COLUMNS, ['Location', '007', 'Zero State', '', '', '', '012345', '0987654321', '', '']],
        ]);
        $sheet = ImportXlsx::read($file);
        self::assertSame(ImportTypes::UNIFIED_COLUMNS, $sheet['headers']);
        self::assertCount(1, $sheet['rows']);
        self::assertSame(2, $sheet['rows'][0]['line']);
        self::assertSame('007', $sheet['rows'][0]['cells'][1]);
        self::assertSame('012345', $sheet['rows'][0]['cells'][6]);
        self::assertSame('0987654321', $sheet['rows'][0]['cells'][7]);
    }

    public function testNumericCellsFormulasHiddenRowsAndSharedStrings(): void
    {
        $rows = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            . '<row r="3" hidden="1"><c r="A3" t="s"><v>2</v></c><c r="B3"><v>9.87654321E+9</v></c></row>'
            . '<row r="4"><c r="A4" t="inlineStr"><is><t>Sum</t></is></c><c r="B4"><f>1+1</f><v>2</v></c></row>'
            . '<row r="5"><c r="A5" t="b"><v>1</v></c><c r="B5" t="e"><v>#N/A</v></c></row>';
        $sheet = ImportXlsx::read(self::workbook($rows, ['Data Type', 'Code', 'Phone']));
        self::assertSame(['Data Type', 'Code'], $sheet['headers']);
        self::assertCount(3, $sheet['rows'], 'blank row 2 is dropped');
        self::assertSame(3, $sheet['rows'][0]['line']);
        self::assertTrue($sheet['rows'][0]['hidden']);
        self::assertSame('9876543210', $sheet['rows'][0]['cells'][1], 'scientific notation is expanded');
        self::assertNotEmpty($sheet['rows'][1]['formula'], 'formula cell is flagged, never evaluated');
        self::assertNotEmpty($sheet['rows'][2]['error']);
    }

    public function testNumberText(): void
    {
        self::assertSame('9876543210', ImportXlsx::numberText('9.87654321E+9'));
        self::assertSame('380001', ImportXlsx::numberText('380001'));
        self::assertSame('380001', ImportXlsx::numberText('380001.0'));
        self::assertSame('12.5', ImportXlsx::numberText('12.5'));
    }

    public function testRejectsBadFiles(): void
    {
        self::assertRejected('not a zip at all');
        self::assertRejected("PK\x03\x04garbage");

        $macro = self::workbook('<row r="1"><c r="A1" t="inlineStr"><is><t>Data Type</t></is></c></row>', [], ['xl/vbaProject.bin' => 'x']);
        self::assertRejected($macro, 'Macro');

        $xxe = self::workbook('<row r="1"><c r="A1" t="inlineStr"><is><t>&xxe;</t></is></c></row>', [], [], '<!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///c:/windows/win.ini">]>');
        self::assertRejected($xxe, 'unsupported XML');

        $bomb = self::workbook('<row r="1"><c r="A1" t="inlineStr"><is><t>Data Type</t></is></c></row>', [], ['xl/media/big.txt' => str_repeat('A', 60 * 1024 * 1024)]);
        self::assertRejected($bomb, 'too large');

        self::assertRejected(self::workbook(''), 'empty');
        self::assertRejected(ImportXlsx::write(['Data' => [['Data Type'], ['Location']], 'More' => [['x']]]), 'Import');
        self::assertSame('Location', ImportXlsx::read(self::workbook('<row r="1"><c r="A1" t="inlineStr"><is><t>Data Type</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Location</t></is></c></row>', [], [], '', 'Data'))['rows'][0]['cells'][0], 'a single sheet with any name is accepted');
    }

    public function testOnlyTheImportSheetIsReadWhenThereAreSeveral(): void
    {
        $file = ImportXlsx::write(['Notes' => [['x'], ['y']], 'Import' => [['Data Type', 'Code'], ['Location', 'A1']], 'Other' => [['z']]]);
        self::assertSame([['Location', 'A1']], array_map(static fn (array $r): array => $r['cells'], ImportXlsx::read($file)['rows']));
    }

    public function testDataTypeAliases(): void
    {
        self::assertSame('location-headquarters', ImportTypes::dataType('Location HQ'));
        self::assertSame('manager-3', ImportTypes::dataType(' 3rd line manager '));
        self::assertSame('manager-1', ImportTypes::dataType('M1'));
        self::assertSame('headquarters', ImportTypes::dataType('HQ'));
        self::assertNull(ImportTypes::dataType('Doctor'));
        $unified = ImportTypes::unified();
        self::assertCount(7, $unified['dataTypes']);
        self::assertSame('blank', $unified['dataTypes'][0]['columns']['Address']);
        self::assertSame('required', $unified['dataTypes'][6]['columns']['Parent Code']);
    }

    private static function assertRejected(string $content, string $message = ''): void
    {
        try {
            ImportXlsx::read($content);
            self::fail('Expected the file to be rejected.');
        } catch (HttpException $e) {
            $text = json_encode($e->details ?? [], JSON_UNESCAPED_SLASHES) . $e->getMessage();
            if ($message !== '') {
                self::assertStringContainsString($message, $text);
            }
        }
    }

    /** Minimal workbook whose single sheet holds the given <row> XML. */
    private static function workbook(string $rows, array $shared = [], array $extra = [], string $doctype = '', string $sheetName = 'Import'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $zip->addFromString('[Content_Types].xml', $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', $head . '<workbook xmlns="' . self::NS . '" xmlns:r="' . $rel . '"><sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $sst = '';
        foreach ($shared as $text) {
            $sst .= '<si><t>' . htmlspecialchars($text) . '</t></si>';
        }
        if ($shared !== []) {
            $zip->addFromString('xl/sharedStrings.xml', $head . '<sst xmlns="' . self::NS . '">' . $sst . '</sst>');
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $head . $doctype . '<worksheet xmlns="' . self::NS . '"><sheetData>' . $rows . '</sheetData></worksheet>');
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }
}