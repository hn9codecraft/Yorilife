<?php

declare(strict_types=1);

namespace App\Services;

/**
 * RFC 4180 CSV reading and CSV-injection-safe writing for the import module.
 * Every cell stays text, so leading zeros and "+91" phone numbers are kept.
 */
final class ImportCsv
{
    /**
     * @return array{rows: list<list<string>>, unterminatedQuote: bool}
     */
    public static function parse(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $rows = [];
        $row = [];
        $cell = '';
        $inQuotes = false;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($inQuotes) {
                if ($char === '"') {
                    if ($i + 1 < $length && $text[$i + 1] === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $cell .= $char;
                }
                continue;
            }
            if ($char === '"') {
                $inQuotes = true;
            } elseif ($char === ',') {
                $row[] = $cell;
                $cell = '';
            } elseif ($char === "\r" || $char === "\n") {
                if ($char === "\r" && $i + 1 < $length && $text[$i + 1] === "\n") {
                    $i++;
                }
                $row[] = $cell;
                $rows[] = $row;
                $row = [];
                $cell = '';
            } else {
                $cell .= $char;
            }
        }
        if ($cell !== '' || $row !== []) {
            $row[] = $cell;
            $rows[] = $row;
        }
        // Blank lines (for example a trailing line break) are not data rows.
        $rows = array_values(array_filter(
            $rows,
            static fn (array $cells): bool => !(count($cells) === 1 && trim($cells[0]) === ''),
        ));

        return ['rows' => $rows, 'unterminatedQuote' => $inQuotes];
    }

    /**
     * A cell a spreadsheet could run as a formula gets a leading apostrophe.
     * Plain numbers and phone numbers such as "+91 98765 43210" or "-5" stay as they are.
     */
    public static function safeCell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;
        if ($text === '') {
            return '';
        }
        $first = $text[0];
        if ($first === '=' || $first === '@' || $first === "\t" || $first === "\r") {
            return "'" . $text;
        }
        if (($first === '+' || $first === '-') && preg_match('/^[+-][0-9\s().-]*$/', $text) !== 1) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * One CSV line (CRLF), quoting cells that need it. Cells are made formula-safe.
     * With $keepDigits, a cell of 6 or more digits only (phone, pincode, numeric code)
     * gets a leading tab inside quotes so Excel keeps it as text instead of showing
     * 9.88E+09 or dropping leading zeros. The tab is added after safeCell, so it is
     * only ever followed by digits and cannot start a formula.
     */
    public static function line(array $cells, bool $keepDigits = false): string
    {
        return implode(',', array_map(static function (mixed $value) use ($keepDigits): string {
            $text = self::safeCell($value);
            if ($keepDigits && preg_match('/^[0-9]{6,}$/', $text) === 1) {
                $text = "\t" . $text;
            }
            if (preg_match('/[",\r\n]/', $text) === 1 || $text !== trim($text)) {
                return '"' . str_replace('"', '""', $text) . '"';
            }

            return $text;
        }, $cells)) . "\r\n";
    }

    /** @param list<list<mixed>> $rows */
    public static function document(array $header, array $rows, bool $keepDigits = false): string
    {
        $out = "\xEF\xBB\xBF" . self::line($header);
        foreach ($rows as $row) {
            $out .= self::line($row, $keepDigits);
        }

        return $out;
    }
}
