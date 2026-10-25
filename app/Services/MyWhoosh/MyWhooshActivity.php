<?php

namespace App\Services\MyWhoosh;

use Carbon\CarbonImmutable;

/**
 * A single MyWhoosh activity as returned by the rider activities API.
 *
 * Fields mirror the Go Activity struct one-to-one:
 *   id, title, date, activityFileId, distance, rideDuration, watt,
 *   heartrate, elevation, wattPerKg, routeName, sportType, startDatetime
 */
final class MyWhooshActivity
{
    public function __construct(
        public string $id,
        public ?string $title = null,
        public ?float $date = null,
        public ?string $startDatetime = null,
        public ?string $activityFileId = null,
        public ?float $distance = null,
        public ?string $rideDuration = null,
        public ?float $watt = null,
        public ?float $heartrate = null,
        public ?float $elevation = null,
        public ?float $wattPerKg = null,
        public ?string $routeName = null,
        public ?string $sportType = null,
    ) {}

    public static function fromArray(array $a): self
    {
        $str = static fn ($v) => is_scalar($v) ? (string) $v : null;
        $num = static fn ($v) => is_numeric($v) ? (float) $v : null;

        return new self(
            id: (string) ($a['id'] ?? ''),
            title: $str($a['title'] ?? null),
            date: $num($a['date'] ?? null),
            startDatetime: $str($a['startDatetime'] ?? null),
            activityFileId: $str($a['activityFileId'] ?? null),
            distance: $num($a['distance'] ?? null),
            rideDuration: $str($a['rideDuration'] ?? null),
            watt: $num($a['watt'] ?? null),
            heartrate: $num($a['heartrate'] ?? null),
            elevation: $num($a['elevation'] ?? null),
            wattPerKg: $num($a['wattPerKg'] ?? null),
            routeName: $str($a['routeName'] ?? null),
            sportType: $str($a['sportType'] ?? null),
        );
    }

    /** Precise start time: startDatetime first, epoch date as fallback. */
    public function startAt(): ?CarbonImmutable
    {
        if ($this->startDatetime !== null && $this->startDatetime !== '') {
            try {
                return CarbonImmutable::parse($this->startDatetime);
            } catch (\Throwable) {
                // fall through to epoch date
            }
        }

        return $this->date !== null
            ? CarbonImmutable::createFromTimestampUTC((int) $this->date)
            : null;
    }

    /** "01:31:54.000" → seconds since start. */
    public function durationSeconds(): ?int
    {
        if ($this->rideDuration === null || $this->rideDuration === '') {
            return null;
        }

        $hms = explode('.', $this->rideDuration)[0];
        $parts = explode(':', $hms);
        if (count($parts) !== 3) {
            return null;
        }

        return (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2];
    }
}
