<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * Escapes user-sourced cell values so a spreadsheet application cannot
 * interpret them as formulas (PRD §8.2, AC-28). Any value beginning with
 * = + - @ (or a leading control char) is prefixed with an apostrophe, and
 * embedded control characters are stripped.
 */
class SpreadsheetEscaper
{
    public static function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $text = (string) $value;

        // Remove control characters except tab/newline which are meaningful in cells.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'" . $text;
        }

        return $text;
    }
}
