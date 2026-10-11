<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * Minimal PDF 1.4 writer sufficient for printable tabular reports.
 *
 * Produces a single-page PDF using Helvetica (one of the PDF base 14 fonts),
 * so no font embedding is required. User-sourced text is escaped to avoid
 * malformed PDF literal strings.
 *
 * This is intentionally dependency-free: no dompdf/tcpdf/libharu required.
 */
class PdfWriter
{
    protected int $objectCount = 0;
    protected array $offsets = [];
    protected array $lines = [];
    protected int $pageCount = 0;
    protected string $title;

    /**
     * @param list<list<string|int|float|bool|null>> $rows
     */
    public function __construct(protected array $rows, protected array $meta = []) {}

    public function write(string $path): string
    {
        $this->objectCount = 0;
        $this->offsets = [];
        $this->lines = [];
        $this->pageCount = 0;
        $this->title = $this->meta['title'] ?? 'DragonMart Report';

        // PDF header is NOT an object; emit it as raw bytes so file_get_contents
        // can detect the magic signature (used by validation suites).
        $headerOffset = 0;
        $this->offsets['__header__'] = $headerOffset;
        $this->lines[] = '%PDF-1.4';

        $catalogId = $this->addCatalog();
        $pagesId = $this->addPagesObject();
        $pageId = $this->addPage($pagesId);
        $this->updatePagesKids($pagesId, $pageId);
        $this->addXrefAndTrailer($catalogId);

        $pdf = implode("\n", $this->lines) . "\n";
        file_put_contents($path, $pdf, LOCK_EX);
        return $path;
    }

    private function addObject(string $content): int
    {
        $this->objectCount++;
        $id = $this->objectCount;
        $this->offsets[$id] = strlen(implode("\n", $this->lines)) + ($this->lines === [] ? 0 : 1);
        $this->lines[] = "{$id} 0 obj";
        $this->lines[] = $content;
        $this->lines[] = 'endobj';
        return $id;
    }

    private function addCatalog(): int
    {
        return $this->addObject("<< /Type /Catalog /Pages 2 0 R >>");
    }

    private function addPagesObject(): int
    {
        // Object 2 placeholder; kids and count updated later.
        return $this->addObject("<< /Type /Pages /Kids [] /Count 0 >>");
    }

    private function updatePagesKids(int $pagesId, int $pageId): void
    {
        $this->pageCount = 1;
        // Find the object line in $lines and replace it.
        for ($i = 0; $i < count($this->lines); $i++) {
            if ($this->lines[$i] === "{$pagesId} 0 obj") {
                $this->lines[$i + 1] = "<< /Type /Pages /Kids [{$pageId} 0 R] /Count 1 >>";
                break;
            }
        }
    }

    private function addPage(int $pagesId): int
    {
        $stream = $this->buildPageStream();
        $streamObj = $this->addObject("<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream");
        $pageContent = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 595 842] /Contents {$streamObj} 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>";
        return $this->addObject($pageContent);
    }

    private function buildPageStream(): string
    {
        $lines = [];
        $lines[] = 'BT /F1 10 Tf';
        $y = 800;
        $lines[] = sprintf('1 0 0 1 40 %d Tm', $y);

        $title = $this->title;
        $lines[] = '/F1 14 Tf';
        $lines[] = '(' . $this->escapePdf($title) . ') Tj';
        $y -= 25;
        $lines[] = sprintf('1 0 0 1 40 %d Tm', $y);
        $lines[] = '/F1 10 Tf';

        if (isset($this->meta['generated_at'])) {
            $lines[] = '(' . $this->escapePdf('Generated: ' . $this->meta['generated_at']) . ') Tj';
            $y -= 15;
            $lines[] = sprintf('1 0 0 1 40 %d Tm', $y);
        }
        if (isset($this->meta['period'])) {
            $lines[] = '(' . $this->escapePdf('Period: ' . $this->meta['period']) . ') Tj';
            $y -= 20;
            $lines[] = sprintf('1 0 0 1 40 %d Tm', $y);
        }

        $y -= 15;
        $lines[] = sprintf('1 0 0 1 40 %d Tm', $y);

        $rowIndex = 0;
        foreach ($this->rows as $row) {
            $line = '';
            $x = 40;
            foreach (array_values($row) as $index => $cell) {
                if ($index > 0) {
                    $line .= ' ';
                }
                $text = SpreadsheetEscaper::escape($cell);
                $line .= $this->escapePdf($text);
            }
            $lines[] = '(' . $line . ') Tj';
            $y -= 12;
            if ($y < 50) {
                // Stop adding rows to avoid writing off-page.
                $lines[] = '... (truncated) Tj';
                break;
            }
            $lines[] = sprintf('1 0 0 1 40 %d Tm', $y);
            $rowIndex++;
        }

        $lines[] = 'ET';
        return implode("\n", $lines);
    }

    private function escapePdf(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text) ?? $text;
        return strtr($text, [
            '(' => '\\(',
            ')' => '\\)',
            '\\' => '\\\\',
        ]);
    }

    private function addXrefAndTrailer(int $rootId): void
    {
        $xrefOffset = strlen(implode("\n", $this->lines)) + 1;
        $this->lines[] = 'xref';
        $this->lines[] = '0 ' . ($this->objectCount + 1);
        $this->lines[] = '0000000000 65535 f ';
        for ($i = 1; $i <= $this->objectCount; $i++) {
            $this->lines[] = sprintf('%010d 00000 n ', $this->offsets[$i]);
        }
        $this->lines[] = 'trailer';
        $this->lines[] = "<< /Size " . ($this->objectCount + 1) . " /Root {$rootId} 0 R >>";
        $this->lines[] = 'startxref';
        $this->lines[] = (string) $xrefOffset;
        $this->lines[] = '%%EOF';
    }
}
