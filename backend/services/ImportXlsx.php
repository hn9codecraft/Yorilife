<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\HttpException;
use XMLReader;
use ZipArchive;

/**
 * Small, safe .xlsx reader and template writer built on ext-zip and XMLReader.
 * No formulas are evaluated, no DTDs or external entities are loaded, macro
 * workbooks are rejected and the archive is checked for zip bombs.
 */
final class ImportXlsx
{
    public const SHEET_NAME = 'Import';
    public const MAX_ENTRIES = 200;
    public const MAX_UNCOMPRESSED = 50 * 1024 * 1024;
    public const MAX_RATIO = 200;
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return array{headers: list<string>, headerLine: int, rows: list<array{line: int, cells: list<string>, hidden: bool, formula: list<int>, numeric: list<int>, error: list<int>}>}
     */
    public static function read(string $content): array
    {
        if (!str_starts_with($content, "PK\x03\x04")) {
            throw HttpException::validation(['file' => 'This is not a valid .xlsx file.']);
        }
        $path = tempnam(sys_get_temp_dir(), 'yxl');
        if ($path === false || file_put_contents($path, $content) !== strlen($content)) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw HttpException::validation(['file' => 'This is not a valid .xlsx file.']);
            }
            $opened = true;
            self::checkArchive($zip);
            $types = self::entry($zip, '[Content_Types].xml');
            if ($types === null || stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                throw HttpException::validation(['file' => 'Macro-enabled or unsupported workbooks are not allowed. Save the file as .xlsx.']);
            }
            $sheetPath = self::sheetPath($zip);
            $shared = self::sharedStrings($zip);
            $xml = self::entry($zip, $sheetPath) ?? throw HttpException::validation(['file' => 'The Import sheet could not be read.']);

            return self::parseSheet($xml, $shared);
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }

    private static function checkArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ENTRIES) {
            throw HttpException::validation(['file' => 'This .xlsx file has an unexpected structure.']);
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw HttpException::validation(['file' => 'This .xlsx file is damaged.']);
            }
            $name = strtolower((string) $stat['name']);
            if (str_contains($name, 'vbaproject') || str_contains($name, 'activex') || str_ends_with($name, '.bin')) {
                throw HttpException::validation(['file' => 'Macro-enabled workbooks are not allowed. Save the file as .xlsx.']);
            }
            $size = (int) $stat['size'];
            $total += $size;
            $compressed = max(1, (int) $stat['comp_size']);
            if ($total > self::MAX_UNCOMPRESSED || ($size > 1024 * 1024 && $size / $compressed > self::MAX_RATIO)) {
                throw HttpException::validation(['file' => 'This .xlsx file is too large when unpacked.']);
            }
        }
    }

    private static function entry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ((int) $stat['size'] > self::MAX_UNCOMPRESSED) {
            throw HttpException::validation(['file' => 'This .xlsx file is too large when unpacked.']);
        }
        $data = $zip->getFromName($name);
        if ($data === false) {
            return null;
        }
        if (stripos($data, '<!DOCTYPE') !== false || stripos($data, '<!ENTITY') !== false) {
            throw HttpException::validation(['file' => 'This .xlsx file contains unsupported XML.']);
        }

        return $data;
    }

    private static function reader(string $xml): XMLReader
    {
        $reader = new XMLReader();
        if ($xml === '' || !$reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            throw HttpException::validation(['file' => 'This .xlsx file is damaged.']);
        }

        return $reader;
    }

    /** Finds the worksheet named "Import", or the only sheet in the workbook. */
    private static function sheetPath(ZipArchive $zip): string
    {
        $workbook = self::entry($zip, 'xl/workbook.xml') ?? throw HttpException::validation(['file' => 'This .xlsx file is damaged.']);
        $sheets = [];
        $r = self::reader($workbook);
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'sheet') {
                $rid = $r->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships') ?? $r->getAttribute('r:id');
                $sheets[] = ['name' => (string) $r->getAttribute('name'), 'rid' => (string) $rid];
            }
        }
        $r->close();
        $chosen = null;
        foreach ($sheets as $sheet) {
            if (strcasecmp(trim($sheet['name']), self::SHEET_NAME) === 0) {
                $chosen = $sheet;
            }
        }
        if ($chosen === null && count($sheets) === 1) {
            $chosen = $sheets[0];
        }
        if ($chosen === null) {
            throw HttpException::validation(['file' => 'Put the data on a sheet named "Import". Download the Excel template.']);
        }
        $rels = self::entry($zip, 'xl/_rels/workbook.xml.rels') ?? throw HttpException::validation(['file' => 'This .xlsx file is damaged.']);
        $target = null;
        $r = self::reader($rels);
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship' && $r->getAttribute('Id') === $chosen['rid']) {
                $target = (string) $r->getAttribute('Target');
            }
        }
        $r->close();
        if ($target === null || $target === '' || str_contains($target, '..')) {
            throw HttpException::validation(['file' => 'The Import sheet could not be found.']);
        }

        return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::entry($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $out = [];
        $r = self::reader($xml);
        $current = null;
        $phonetic = 0;
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'si') {
                    $current = '';
                } elseif ($r->localName === 'rPh' && !$r->isEmptyElement) {
                    $phonetic++;
                } elseif ($r->localName === 't' && $current !== null && $phonetic === 0) {
                    $current .= $r->readString();
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                if ($r->localName === 'si') {
                    $out[] = (string) $current;
                    $current = null;
                } elseif ($r->localName === 'rPh') {
                    $phonetic--;
                }
            }
        }
        $r->close();

        return $out;
    }

    private static function column(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }

    /** Number without exponent: 9.87654321E+9 becomes 9876543210. */
    public static function numberText(string $value): string
    {
        $value = trim($value);
        if (!is_numeric($value)) {
            return $value;
        }
        $f = (float) $value;
        if (floor($f) === $f && abs($f) < 1e15) {
            return sprintf('%.0f', $f);
        }
        $text = rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    private static function parseSheet(string $xml, array $shared): array
    {
        $r = self::reader($xml);
        $rows = [];
        $merges = [];
        $previous = 0;
        $ok = @$r->read();
        while ($ok) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
                $line = (int) $r->getAttribute('r') ?: $previous + 1;
                $previous = $line;
                $row = ['line' => $line, 'cells' => [], 'hidden' => in_array($r->getAttribute('hidden'), ['1', 'true'], true), 'formula' => [], 'numeric' => [], 'error' => []];
                if (!$r->isEmptyElement) {
                    self::parseRow($r->readOuterXml(), $row, $shared);
                }
                $rows[] = $row;
                $ok = @$r->next();
                continue;
            }
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'mergeCell') {
                $merges[] = (string) $r->getAttribute('ref');
            }
            $ok = @$r->read();
        }
        $r->close();

        $rows = array_values(array_filter($rows, static fn (array $row): bool => implode('', array_map('trim', $row['cells'])) !== '' || $row['formula'] !== [] || $row['error'] !== []));
        if ($rows === []) {
            throw HttpException::validation(['file' => 'The Import sheet is empty.']);
        }
        $header = array_shift($rows);
        foreach ($merges as $ref) {
            if (preg_match('/^[A-Z]+' . $header['line'] . ':/i', $ref) === 1) {
                throw HttpException::validation(['file' => 'The header row has merged cells. Use the template header row.']);
            }
        }
        $width = $header['cells'] === [] ? 0 : max(array_keys($header['cells'])) + 1;
        $headers = [];
        for ($i = 0; $i < $width; $i++) {
            $headers[] = trim($header['cells'][$i] ?? '');
        }
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }
        $out = [];
        foreach ($rows as $row) {
            $cells = [];
            $max = $row['cells'] === [] ? 0 : max(array_keys($row['cells'])) + 1;
            for ($i = 0; $i < max(count($headers), $max); $i++) {
                $cells[] = $row['cells'][$i] ?? '';
            }
            $row['cells'] = $cells;
            $out[] = $row;
        }

        return ['headers' => $headers, 'headerLine' => $header['line'], 'rows' => $out];
    }

    private static function parseRow(string $xml, array &$row, array $shared): void
    {
        $c = self::reader($xml);
        $col = -1;
        $t = 'n';
        $value = '';
        $formula = false;
        $inCell = false;
        while (@$c->read()) {
            if ($c->nodeType === XMLReader::ELEMENT) {
                if ($c->localName === 'c') {
                    $ref = $c->getAttribute('r');
                    $col = $ref !== null ? self::column($ref) : $col + 1;
                    $t = (string) ($c->getAttribute('t') ?? 'n');
                    $value = '';
                    $formula = false;
                    $inCell = true;
                    if ($c->isEmptyElement) {
                        self::store($row, $col, $t, '', false, $shared);
                        $inCell = false;
                    }
                } elseif ($inCell && $c->localName === 'f') {
                    $formula = true;
                } elseif ($inCell && $c->localName === 'v') {
                    $value = $c->readString();
                } elseif ($inCell && $c->localName === 't' && $t === 'inlineStr') {
                    $value .= $c->readString();
                }
            } elseif ($c->nodeType === XMLReader::END_ELEMENT && $c->localName === 'c' && $inCell) {
                self::store($row, $col, $t, $value, $formula, $shared);
                $inCell = false;
            }
        }
        $c->close();
    }

    private static function store(array &$row, int $col, string $t, string $value, bool $formula, array $shared): void
    {
        if ($col > 200) {
            return;
        }
        if ($formula) {
            $row['formula'][] = $col;
            $value = '';
        } elseif ($t === 's') {
            $value = $shared[(int) $value] ?? '';
        } elseif ($t === 'e') {
            $row['error'][] = $col;
            $value = '';
        } elseif ($t === 'b') {
            $value = $value === '1' ? 'TRUE' : 'FALSE';
        } elseif ($t === 'n' || $t === 'd') {
            if ($value !== '') {
                $row['numeric'][] = $col;
                $value = self::numberText($value);
            }
        }
        $row['cells'][$col] = $value;
    }

    /**
     * Builds an .xlsx with every cell formatted as Text.
     *
     * @param array<string, list<list<string>>> $sheets sheet name => rows (first row = header)
     */
    public static function write(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yxw');
        if ($path === false) {
            throw new \RuntimeException('Could not create the template.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the template.');
        }
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ct = $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = '';
        $wbRels = '';
        $n = 0;
        foreach ($sheets as $name => $rows) {
            $n++;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . $esc((string) $name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="' . $rel . '/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $rowsXml = '';
            foreach ($rows as $r => $cells) {
                $rowsXml .= '<row r="' . ($r + 1) . '">';
                foreach ($cells as $c => $value) {
                    $rowsXml .= '<c r="' . self::letter($c) . ($r + 1) . '" t="inlineStr" s="' . ($r === 0 ? 2 : 1) . '"><is><t xml:space="preserve">' . $esc((string) $value) . '</t></is></c>';
                }
                $rowsXml .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $head . '<worksheet xmlns="' . self::NS . '">'
                . ($n === 1 ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '')
                . '<cols><col min="1" max="26" width="24" style="1" customWidth="1"/></cols><sheetData>' . $rowsXml . '</sheetData></worksheet>');
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $head . '<workbook xmlns="' . self::NS . '" xmlns:r="' . $rel . '"><sheets>' . $wbSheets . '</sheets></workbook>');
        $wbRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="' . $rel . '/styles" Target="styles.xml"/>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $head . '<styleSheet xmlns="' . self::NS . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/></cellXfs>'
            . '</styleSheet>');
        $zip->close();
        $data = (string) file_get_contents($path);
        @unlink($path);

        return $data;
    }

    public static function letter(int $index): string
    {
        $s = '';
        $index++;
        while ($index > 0) {
            $m = ($index - 1) % 26;
            $s = chr(65 + $m) . $s;
            $index = intdiv($index - 1, 26);
        }

        return $s;
    }
}