<?php

declare(strict_types=1);

namespace Aicountly\Api\Reports;

use Aicountly\Api\Support\Decimal;
use ZipArchive;

/**
 * A single-sheet .xlsx, written directly.
 *
 * WHY NOT A LIBRARY. This API has no composer and no vendor directory, and an
 * export of one table is not the thing to break that for. An xlsx is a zip of
 * a handful of XML parts, and the subset a report needs — a sheet, a shared
 * string table, two number formats and a bold header — is small enough to keep
 * honest.
 *
 * THE TWO THINGS THAT MATTER, and both are reasons a spreadsheet export goes
 * wrong in practice:
 *
 *  1. NUMBERS ARE NUMBERS. A money column written as text does not sum, and
 *     the first thing anybody does with an exported table is put a SUM under
 *     it. Every decimal value is written as `t="n"` with the raw digits, and
 *     the display format is applied through a cell style — so the cell reads
 *     ₹12,34,567.89 and behaves like 1234567.89.
 *
 *  2. FORMULA INJECTION IS NEUTRALISED. A cell beginning =, +, - or @ is
 *     executed by Excel when the file is opened, and a customer name is
 *     another product's user input. Text cells starting with one are prefixed
 *     with an apostrophe, which Excel strips on display and does not execute.
 *     A NUMERIC cell is not touched: -1500 is a negative number, not an
 *     injection, and quoting it would break the very summing this exists for.
 */
final class XlsxWriter
{
    /** Style indexes, in the order they are declared in styles.xml below. */
    private const STYLE_DEFAULT = 0;
    private const STYLE_HEADER  = 1;
    private const STYLE_MONEY   = 2;
    private const STYLE_PERCENT = 3;
    private const STYLE_INT     = 4;
    private const STYLE_TITLE   = 5;

    /** @var list<array{value:mixed, style:int, numeric:bool}> */
    private array $rows = [];

    /** @var array<string, int> */
    private array $sharedStrings = [];

    private int $sharedCount = 0;

    /** @var list<list<array{value:string, style:int, numeric:bool}>> */
    private array $sheet = [];

    public function __construct(private readonly string $sheetName = 'Report')
    {
    }

    /** A title or note line, spanning the sheet. */
    public function addTitle(string $text): self
    {
        $this->sheet[] = [['value' => $this->safeText($text), 'style' => self::STYLE_TITLE, 'numeric' => false]];

        return $this;
    }

    public function addBlankRow(): self
    {
        $this->sheet[] = [];

        return $this;
    }

    /** @param list<string> $headers */
    public function addHeader(array $headers): self
    {
        $row = [];
        foreach ($headers as $header) {
            $row[] = ['value' => $this->safeText($header), 'style' => self::STYLE_HEADER, 'numeric' => false];
        }
        $this->sheet[] = $row;

        return $this;
    }

    /**
     * One row.
     *
     * @param list<array{value:mixed, type?:string}> $cells type: text | currency | percent | integer | decimal
     */
    public function addRow(array $cells): self
    {
        $row = [];
        foreach ($cells as $cell) {
            $type = (string) ($cell['type'] ?? 'text');
            $value = $cell['value'];

            if ($type === 'text' || $value === null) {
                $row[] = ['value' => $value === null ? '' : $this->safeText((string) $value), 'style' => self::STYLE_DEFAULT, 'numeric' => false];
                continue;
            }

            $decimal = Decimal::parse($value);
            if ($decimal === null) {
                $row[] = ['value' => $this->safeText((string) $value), 'style' => self::STYLE_DEFAULT, 'numeric' => false];
                continue;
            }

            $style = match ($type) {
                'currency' => self::STYLE_MONEY,
                'percent'  => self::STYLE_PERCENT,
                'integer'  => self::STYLE_INT,
                default    => self::STYLE_DEFAULT,
            };

            $row[] = ['value' => $decimal, 'style' => $style, 'numeric' => true];
        }
        $this->sheet[] = $row;

        return $this;
    }

    /**
     * The workbook, as bytes.
     *
     * Built through a temporary file because ZipArchive writes to disk; the
     * file is read back and removed immediately, so nothing is left behind in
     * a world-readable temp directory.
     */
    public function build(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'insights-xlsx-');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the workbook.');
        }

        // Written before anything goes into it: a world-readable export in a
        // shared /tmp is a data leak with a short life and a long tail.
        @chmod($path, 0600);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new \RuntimeException('Could not open the workbook for writing.');
        }

        $sheetXml = $this->sheetXml();

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    // -----------------------------------------------------------------------

    private function sheetXml(): string
    {
        $rows = '';
        foreach ($this->sheet as $index => $cells) {
            $rowNumber = $index + 1;
            $cellXml = '';
            foreach ($cells as $column => $cell) {
                $reference = $this->columnName($column) . $rowNumber;
                if ($cell['numeric']) {
                    $cellXml .= sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $cell['style'], $cell['value']);
                    continue;
                }
                $stringIndex = $this->sharedString((string) $cell['value']);
                $cellXml .= sprintf('<c r="%s" s="%d" t="s"><v>%d</v></c>', $reference, $cell['style'], $stringIndex);
            }
            $rows .= sprintf('<row r="%d">%s</row>', $rowNumber, $cellXml);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols><col min="1" max="1" width="36" customWidth="1"/><col min="2" max="12" width="18" customWidth="1"/></cols>'
            . '<sheetData>' . $rows . '</sheetData></worksheet>';
    }

    private function sharedString(string $value): int
    {
        if (isset($this->sharedStrings[$value])) {
            return $this->sharedStrings[$value];
        }
        $index = $this->sharedCount++;
        $this->sharedStrings[$value] = $index;

        return $index;
    }

    private function sharedStringsXml(): string
    {
        $items = '';
        foreach (array_keys($this->sharedStrings) as $value) {
            $items .= '<si><t xml:space="preserve">' . $this->escape((string) $value) . '</t></si>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $this->sharedCount
            . '" uniqueCount="' . $this->sharedCount . '">' . $items . '</sst>';
    }

    private function stylesXml(): string
    {
        // 164: Indian-grouped rupees. 165: a percentage. 166: a plain integer.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3">'
            . '<numFmt numFmtId="164" formatCode="[$&#8377;-en-IN]##,##,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="0.0&quot;%&quot;"/>'
            . '<numFmt numFmtId="166" formatCode="##,##,##0"/>'
            . '</numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="13"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8F6E4"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $this->escape($this->sheetTitle()) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';
    }

    private function sheetTitle(): string
    {
        // Excel refuses a sheet name over 31 characters or containing : \ / ? * [ ]
        $name = (string) preg_replace('/[:\\\\\\/\?\*\[\]]/', ' ', $this->sheetName);

        return mb_substr(trim($name) ?: 'Report', 0, 31);
    }

    /**
     * Neutralise a leading character a spreadsheet would treat as a formula.
     *
     * Applied to TEXT only. A numeric cell keeps its minus sign, because a
     * negative number is a negative number.
     */
    private function safeText(string $value): string
    {
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/u', '', $value);

        if ($clean !== '' && str_contains("=+-@\t\r", $clean[0])) {
            return "'" . $clean;
        }

        return $clean;
    }

    /**
     * XML-escape a text node.
     *
     * ENT_XML1 without ENT_QUOTES: `<`, `>` and `&` must be escaped, and a
     * literal apostrophe inside an element is legal XML. Escaping it to
     * &apos; is also legal and reads identically in Excel, but it hides the
     * leading apostrophe that neutralises a formula from anyone inspecting the
     * file to check that guard is working.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }

    private function columnName(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name = chr(65 + $remainder) . $name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }
}
