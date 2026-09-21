<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;

/**
 * The period a report covers: whole days in the reporting time zone (REPORTS_TIMEZONE),
 * both ends included. Tickets are stored in the application time zone (UTC), so the
 * boundaries are converted for the database.
 */
final class ReportRange
{
    public const MAX_DAYS = 366;

    public const DEFAULT_DAYS = 30;

    /** More days than this and the timeline groups by week instead of by day. */
    public const DAILY_LIMIT_DAYS = 92;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $timezone,
    ) {}

    /** @param  ?string  $from  Y-m-d in the reporting time zone; defaults to 29 days before $to */
    public static function make(?string $from, ?string $to, string $timezone): self
    {
        $end = $to ? CarbonImmutable::parse($to, $timezone) : CarbonImmutable::now($timezone);
        $start = $from ? CarbonImmutable::parse($from, $timezone) : $end->subDays(self::DEFAULT_DAYS - 1);

        return new self($start->startOfDay(), $end->endOfDay(), $timezone);
    }

    /** Number of calendar days covered. */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    public function granularity(): string
    {
        return $this->days() > self::DAILY_LIMIT_DAYS ? 'week' : 'day';
    }

    /** Start of the period as the database stores times. */
    public function fromDb(): string
    {
        return $this->from->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /** End of the period as the database stores times. */
    public function toDb(): string
    {
        return $this->to->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /** @return array{from: string, to: string, timezone: string, days: int, granularity: string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'timezone' => $this->timezone,
            'days' => $this->days(),
            'granularity' => $this->granularity(),
        ];
    }
}
