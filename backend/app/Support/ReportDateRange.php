<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;
use Carbon\CarbonImmutable;

/**
 * Translates local calendar dates (YYYY-MM-DD) into the half-open UTC interval
 * required by PRD §8.1:
 *   start_utc = local_start at 00:00 in the store timezone
 *   end_utc   = (local_end + 1 day) at 00:00 in the store timezone
 *
 * The endpoint is half-open [start, end), which means end is exclusive and the
 * last full day is included.
 */
class ReportDateRange
{

    public string $start;
    public string $end;
    public string $timezone;
    /**
     * @param string|null $startDate YYYY-MM-DD in store timezone; null = today minus 30 days
     * @param string|null $endDate   YYYY-MM-DD in store timezone; null = today
     * @param string     $timezone  IANA timezone (defaults to Asia/Makassar fallback)
     */
    public static function fromInputs(?string $startDate, ?string $endDate, string $timezone): self
    {
        if ($timezone === '') {
            $timezone = 'Asia/Makassar';
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new DomainException("Zona waktu '{$timezone}' tidak valid.", 'INVALID_TIMEZONE', 422);
        }

        $todayLocal = CarbonImmutable::now($timezone)->toDateString();

        if ($startDate === null) {
            $startLocal = CarbonImmutable::parse($todayLocal, $timezone)->subDays(30)->toDateString();
        } else {
            self::assertIsoDate($startDate, 'start_date');
            $startLocal = $startDate;
        }

        if ($endDate === null) {
            $endLocal = $todayLocal;
        } else {
            self::assertIsoDate($endDate, 'end_date');
            $endLocal = $endDate;
        }

        if (strcmp($startLocal, $endLocal) > 0) {
            throw new DomainException('Tanggal awal harus lebih kecil atau sama dengan tanggal akhir.', 'INVALID_DATE_RANGE', 422);
        }

        // Maximum 366-day window to bound report scans.
        $diffDays = CarbonImmutable::parse($endLocal, $timezone)->diffInDays(CarbonImmutable::parse($startLocal, $timezone));
        if ($diffDays > 366) {
            throw new DomainException('Rentang tanggal laporan terlalu panjang (maks 366 hari).', 'DATE_RANGE_TOO_LARGE', 422);
        }

        $startUtc = CarbonImmutable::parse($startLocal, $timezone)->startOfDay()->utc()->toIso8601String();
        $endUtc = CarbonImmutable::parse($endLocal, $timezone)->addDay()->startOfDay()->utc()->toIso8601String();

        $instance = new self();
        $instance->start = $startUtc;
        $instance->end = $endUtc;
        $instance->timezone = $timezone;
        return $instance;
    }

    private static function assertIsoDate(string $value, string $field): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new DomainException("{$field} harus berformat YYYY-MM-DD.", 'INVALID_DATE', 422, [$field => $value]);
        }
        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new DomainException("{$field} bukan tanggal kalender yang valid.", 'INVALID_DATE', 422, [$field => $value]);
        }
    }

    public function toArray(): array
    {
        return [
            'start' => $this->start,
            'end' => $this->end,
            'timezone' => $this->timezone,
        ];
    }
}
