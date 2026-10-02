<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * CSV reading and writing shared by exports, reports and imports.
 *
 * Writing: UTF-8 with a byte order mark (so spreadsheets read accents correctly), and cells that a
 * spreadsheet would run as a formula (= + - @, tab, CR) are prefixed with an apostrophe.
 *
 * Reading: comma, semicolon or tab delimiters are detected from the header line; a byte order mark is
 * removed; header names are normalised so aliases can be matched ("Base airport" -> "baseairport").
 */
final class Csv
{
    /**
     * Build a CSV document.
     *
     * @param  array<int, string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function write(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), escape: '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}".$csv;
    }

    /** Neutralise spreadsheet formulas in one cell value. */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * Parse CSV text into rows keyed by normalised header. Blank lines are skipped; each row keeps its
     * 1-based line number in "_line".
     *
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, delimiter: string}
     */
    public static function read(string $text): array
    {
        $text = preg_replace('/^\x{FEFF}/u', '', str_replace(["\r\n", "\r"], "\n", $text)) ?? '';
        $firstLine = strtok($text, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $candidate): int => substr_count($firstLine, $candidate))->first();
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);
        $headers = array_map(self::normalise(...), fgetcsv($handle, null, $delimiter, '"', '') ?: []);
        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($values === [null] || trim(implode('', $values)) === '') {
                continue;
            }
            $row = ['_line' => (string) $line];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = trim((string) ($values[$index] ?? ''));
                }
            }
            $rows[] = $row;
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    /** "Base Airport" / "base_airport" -> "baseairport". */
    public static function normalise(?string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $header))) ?? '';
    }

    /**
     * A calendar date from common spreadsheet formats: 2026-10-05, 05/10/2026 (day first), 05.10.2026,
     * 5 Oct 2026, 05-Oct-26, or an Excel serial number (days since 1899-12-30). Null when unreadable.
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            $serial = (int) $value;

            return $serial > 20000 && $serial < 80000 ? CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays($serial)->format('Y-m-d') : null;
        }
        // Native DateTimeImmutable returns false on a mismatch (Carbon throws); the round trip rejects 31/02.
        foreach (['Y-m-d', 'd/m/Y', 'd.m.Y', 'd-m-Y', 'j M Y', 'd M Y', 'j F Y', 'd-M-y', 'd-M-Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** "08:00", "8:00", "0800" -> "08:00"; null when not a time. */
    public static function time(string $value): ?string
    {
        if (! preg_match('/^(\d{1,2}):?(\d{2})$/', trim($value), $match) || (int) $match[1] > 23 || (int) $match[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $match[1], $match[2]);
    }
}
