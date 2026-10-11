<?php

declare(strict_types=1);

namespace App\Support\Export;

use ZipArchive;

/**
 * Minimal spec-compliant XLSX writer (Office Open XML, SpreadsheetML).
 *
 * Produces a single-sheet workbook with inline strings, all user-sourced cell
 * values escaped against formula injection via {@see SpreadsheetEscaper}.
 *
 * Spec references:
 *   - ECMA-376 §18.6 (worksheet), §18.2 (shared strings replaced by inline)
 *   - PRD §8.2: "Escape spreadsheet cells originating from user input so they
 *     cannot become formulas."
 *
 * The writer uses ZipArchive (which is part of PHP via ext-zip); no third-party
 * spreadsheet library is required.
 */
class XlsxWriter
{
    /**
     * @param list<list<string|int|float|bool|null>> $rows
     * @param array{sheet_name?: string, period?: string, timezone?: string, generated_at?: string} $meta
     */
    public function __construct(protected array $rows, protected array $meta = []) {}

    public function write(string $path): string
    {
        $sheetXml = $this->buildSheet();
        $contentTypes = $this->buildContentTypes();
        $rels = $this->buildRels();
        $workbook = $this->buildWorkbook();

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException("Tidak dapat membuka ZIP untuk menulis XLSX ({$path}).");
        }
        try {
            $zip->addFromString('[Content_Types].xml', $contentTypes);
            $zip->addFromString('_rels/.rels', $rels);
            $zip->addFromString('xl/workbook.xml', $workbook);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        } finally {
            $zip->close();
        }
        return $path;
    }

    private function buildSheet(): string
    {
        $sheet = $this->meta['sheet_name'] ?? 'Report';
        $rows = '';

        foreach ($this->rows as $rowIndex => $cells) {
            $cellXml = '';
            foreach (array_values($cells) as $colIndex => $value) {
                $ref = $this->cellRef($rowIndex, $colIndex);
                $escaped = SpreadsheetEscaper::escape($value);
                $xmlValue = htmlspecialchars($escaped, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                // Preserve leading whitespace inside inline strings by wrapping in xml:space="preserve".
                $attr = str_starts_with($escaped, ' ') || str_starts_with($escaped, "\t") ? ' xml:space="preserve"' : '';
                $type = ' inlineStr';
                $cellXml .= "<c r=\"{$ref}\" t=\"inlineStr\"{$attr}><is><t{$attr}>{$xmlValue}</t></is></c>";
            }
            $rows .= "<row r=\"" . ($rowIndex + 1) . "\">{$cellXml}</row>";
        }

        $cols = $this->autoColumnWidths();
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $rows . '</sheetData>'
            . '</worksheet>';
    }

    private function autoColumnWidths(): string
    {
        $widths = [];
        foreach ($this->rows as $row) {
            foreach (array_values($row) as $col => $value) {
                $length = mb_strlen((string) $value);
                $widths[$col] = max($widths[$col] ?? 0, $length);
            }
        }
        if (count($widths) === 0) {
            return '';
        }
        $cols = '';
        foreach ($widths as $index => $width) {
            // Add a small padding to width and clamp minimum to a readable size.
            $width = max(10, $width + 2);
            $min = $index + 1;
            $max = $min;
            $cols .= "<col min=\"{$min}\" max=\"{$max}\" width=\"{$width}\" customWidth=\"1\"/>";
        }
        return "<cols>{$cols}</cols>";
    }

    private function buildContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private function buildRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function buildWorkbook(): string
    {
        $sheet = $this->meta['sheet_name'] ?? 'Report';
        $escaped = htmlspecialchars($sheet, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<workbookPr/>'
            . '<bookViews><workbookView activeTab="0"/></bookViews>'
            . '<sheets><sheet name="' . $escaped . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function cellRef(int $rowIndex, int $colIndex): string
    {
        $letters = '';
        $col = $colIndex;
        do {
            $letters = chr(65 + ($col % 26)) . $letters;
            $col = intdiv($col, 26) - 1;
        } while ($col >= 0);
        return $letters . ($rowIndex + 1);
    }
}
