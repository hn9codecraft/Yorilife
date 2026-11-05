<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImportCsv;
use App\Services\ImportTypes;
use PHPUnit\Framework\TestCase;

final class ImportCsvTest extends TestCase
{
    public function testParsesQuotesBomCrlfMultilineAndBlankLines(): void
    {
        $parsed = ImportCsv::parse("\xEF\xBB\xBFa,b\r\n\"x, y\",\"say \"\"hi\"\"\"\r\n\r\n\"two\nlines\",z\n");
        self::assertFalse($parsed['unterminatedQuote']);
        self::assertSame([['a', 'b'], ['x, y', 'say "hi"'], ["two\nlines", 'z']], $parsed['rows']);
    }

    public function testReportsUnterminatedQuote(): void
    {
        self::assertTrue(ImportCsv::parse("a,b\r\n\"open,1\r\n")['unterminatedQuote']);
    }

    public function testFormulaCellsAreNeutralised(): void
    {
        self::assertSame("'=1+1", ImportCsv::safeCell('=1+1'));
        self::assertSame("'@SUM(A1)", ImportCsv::safeCell('@SUM(A1)'));
        self::assertSame("'+cmd|' /C calc'!A0", ImportCsv::safeCell("+cmd|' /C calc'!A0"));
        self::assertSame("'-2+3", ImportCsv::safeCell('-2+3'));
        self::assertSame("'\tx", ImportCsv::safeCell("\tx"));
        self::assertSame('+91 98765 43210', ImportCsv::safeCell('+91 98765 43210'));
        self::assertSame('-5', ImportCsv::safeCell('-5'));
        self::assertSame('Plain', ImportCsv::safeCell('Plain'));
    }

    public function testDocumentHasBomAndQuotesWhereNeeded(): void
    {
        $doc = ImportCsv::document(['A', 'B'], [['x,y', '=bad'], ['say "hi"', '']]);
        self::assertSame("\xEF\xBB\xBFA,B\r\n\"x,y\",'=bad\r\n\"say \"\"hi\"\"\",\r\n", $doc);
        self::assertSame([['A', 'B'], ['x,y', "'=bad"], ['say "hi"', '']], ImportCsv::parse($doc)['rows']);
    }

    public function testTypeDefinitionsMatchTheExcelColumnMap(): void
    {
        foreach (ImportTypes::all() as $type => $definition) {
            foreach (ImportTypes::UNIFIED_MAP[$type] as $header) {
                self::assertContains($header, array_column($definition['columns'], 'header'), $type);
            }
            self::assertContains($definition['key'], array_column($definition['columns'], 'header'), $type);
            foreach ($definition['fields'] as $header) {
                self::assertContains($header, array_column($definition['columns'], 'header'), $type);
            }
        }
        self::assertSame(ImportTypes::ORDER, array_keys(ImportTypes::all()));
    }
}
