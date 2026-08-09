<?php

namespace Modules\Admin\Services;

use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\BranchShiftConfig;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Support\ShiftEnums;

/**
 * SRS §4.4 — the N-shift configuration, for a brand OR a single branch.
 * Computes the sequential shift windows from
 * `{numShifts, durationHours|durationMinutes, firstShiftStart}` and keeps the
 * prototype `morningWindow`/`eveningWindow` in sync as derived aliases.
 *
 * Two things the dashboard needs beyond the original model (meeting 2026-08-09):
 *
 *  - the duration field is free text, not a 4/6/8/10/12 dropdown, so a typed
 *    `7.5` must survive — durations are carried in MINUTES internally and
 *    `duration_hours` is kept as the rounded integer for existing readers;
 *  - each shift row can be edited on its own (the ✏️ on the schedule list), so
 *    a config may carry per-shift `overrides` that replace the computed start
 *    and/or duration of one shift without disturbing the others.
 *
 * Pure computation (no DB writes) so late-detection and the config endpoints can
 * both derive windows without duplicating the arithmetic.
 */
class ShiftConfigService
{
    /** Shortest schedulable shift — guards a typo like «0.1 ساعة». */
    private const MIN_DURATION_MINUTES = 15;

    private const DAY_MINUTES = 1440;

    /**
     * The full presentation for one config: real columns + computed shift
     * windows + legacy aliases. `$config` may be a brand config, a branch
     * override, or null (defaults).
     *
     * @return array<string, mixed>
     */
    public function present(
        string $ownerId,
        ?string $ownerName,
        BrandShiftConfig|BranchShiftConfig|null $config,
        string $scope = 'brand',
        ?string $inheritedFromBrandId = null,
        ?bool $hasOwnConfig = null,
    ): array {
        $numShifts = $this->numShifts($config);
        $durationMinutes = $this->durationMinutes($config);
        $firstStart = $config->first_shift_start ?? '06:00';
        $settings = is_array($config->shifts ?? null) ? $config->shifts : [];
        $float = (int) ($settings['openingFloatHalalas'] ?? ShiftEnums::DEFAULT_FLOAT_HALALAS);
        $overrides = $this->overrides($settings);

        $windows = $this->windowsFromMinutes($numShifts, $durationMinutes, $firstStart, $overrides);

        return [
            // Scope keys — `brandId`/`brandName` stay populated for a brand config
            // so no existing FE read breaks; a branch config fills branchId/Name.
            'scope' => $scope,
            'ownerId' => $ownerId,
            'ownerName' => $ownerName,
            'brandId' => $scope === 'brand' ? $ownerId : $inheritedFromBrandId,
            'brandName' => $scope === 'brand' ? $ownerName : null,
            'branchId' => $scope === 'branch' ? $ownerId : null,
            'branchName' => $scope === 'branch' ? $ownerName : null,
            // A branch with no row of its own follows its brand — the caller
            // states it explicitly when the row it passed is the INHERITED one.
            'hasOwnConfig' => $hasOwnConfig ?? ($config !== null),
            'numShifts' => $numShifts,
            'durationHours' => $this->hoursOf($durationMinutes),
            'durationMinutes' => $durationMinutes,
            'firstShiftStart' => $firstStart,
            'openingFloatHalalas' => $float,
            'shifts' => $windows,
            'coverageMinutes' => array_sum(array_column($windows, 'durationMinutes')),
            // Prototype aliases — shift 1 = morning, shift 2 = evening.
            'morningWindow' => $windows[0]['window'] ?? '06:00-14:00',
            'eveningWindow' => $windows[1]['window'] ?? '14:00-23:00',
        ];
    }

    /**
     * Normalise a save body (the meeting model, the legacy morning/evening pair,
     * or a per-shift override list) to the columns to persist.
     *
     * `$current` is the row being edited, so a PARTIAL body keeps everything it
     * did not mention: the ✏️ per-shift editor saves one shift at a time and
     * used to reset `numShifts` to 1 on every such save.
     *
     * @param  array<string, mixed>  $data
     * @return array{num_shifts:int, duration_hours:int, duration_minutes:int, first_shift_start:string, shifts:array}
     */
    public function fromInput(array $data, BrandShiftConfig|BranchShiftConfig|null $current = null): array
    {
        $existing = is_array($current->shifts ?? null) ? $current->shifts : [];
        $settings = [
            'openingFloatHalalas' => (int) ($data['openingFloatHalalas']
                ?? $existing['openingFloatHalalas']
                ?? ShiftEnums::DEFAULT_FLOAT_HALALAS),
        ];

        // Legacy body: morning/evening windows → numShifts=2, duration+start
        // derived from the morning window.
        if (! isset($data['numShifts']) && isset($data['morningWindow'])) {
            [$start, $end] = $this->splitWindow($data['morningWindow']);
            $minutes = $this->clampDuration($this->minutesBetween($start, $end));

            return [
                'num_shifts' => 2,
                'duration_hours' => $this->hoursOf($minutes),
                'duration_minutes' => $minutes,
                'first_shift_start' => $start,
                'shifts' => $settings,
            ];
        }

        $minutes = $this->clampDuration(
            $this->inputDurationMinutes($data) ?? ($current !== null ? $this->durationMinutes($current) : null),
        );

        // Per-shift edits: replace only the shifts named in the body, keep the
        // rest of the saved overrides (the ✏️ saves one row at a time).
        $overrides = $this->overrides($existing);
        if (array_key_exists('shiftOverrides', $data)) {
            foreach ((array) $data['shiftOverrides'] as $row) {
                $no = (int) ($row['no'] ?? 0);
                if ($no < 1) {
                    continue;
                }
                $entry = [];
                if (isset($row['start'])) {
                    $entry['start'] = $this->normalizeTime((string) $row['start']);
                }
                $rowMinutes = $this->inputDurationMinutes($row);
                if ($rowMinutes !== null) {
                    $entry['durationMinutes'] = $this->clampDuration($rowMinutes);
                }
                // An empty entry clears the override → the shift follows the schedule again.
                if ($entry === []) {
                    unset($overrides[$no]);
                } else {
                    $overrides[$no] = $entry;
                }
            }
        }
        if ($overrides !== []) {
            $settings['overrides'] = $overrides;
        }

        return [
            'num_shifts' => max(1, min(ShiftEnums::MAX_SHIFTS, (int) ($data['numShifts'] ?? $current->num_shifts ?? 1))),
            'duration_hours' => $this->hoursOf($minutes),
            'duration_minutes' => $minutes,
            'first_shift_start' => $this->normalizeTime((string) ($data['firstShiftStart'] ?? $current->first_shift_start ?? '06:00')),
            'shifts' => $settings,
        ];
    }

    /**
     * A day holds 24 hours: the shift windows must fit inside one, or they wrap
     * onto each other. Two identical windows collide on the mobile `shifts`
     * natural key (branch_id, start_time, end_time), so a 4×8h schedule silently
     * landed as THREE template rows in the app — the fourth renamed the first
     * instead of adding a shift.
     *
     * @throws AsabException 422 SHIFT_SCHEDULE_OVERFLOW
     */
    public function assertFitsDay(int $numShifts, int $durationHours): void
    {
        $this->assertFitsDayMinutes($numShifts, $durationHours * 60);
    }

    /** Minutes-precise counterpart of {@see assertFitsDay()}. */
    public function assertFitsDayMinutes(int $numShifts, int $durationMinutes, array $overrides = []): void
    {
        $total = 0;
        for ($no = 1; $no <= $numShifts; $no++) {
            $total += (int) ($overrides[$no]['durationMinutes'] ?? $durationMinutes);
        }
        if ($total <= self::DAY_MINUTES) {
            return;
        }

        $hours = $this->humanHours($durationMinutes);
        $totalHours = $this->humanHours($total);

        throw new AsabException(
            'SHIFT_SCHEDULE_OVERFLOW',
            "{$numShifts} shifts × {$hours}h = {$totalHours}h does not fit in a 24-hour day.",
            "عدد الورديات ({$numShifts}) × مدة الوردية ({$hours} ساعة) = {$totalHours} ساعة، وهو أكبر من اليوم (24 ساعة).",
            422,
        );
    }

    /**
     * The `{no, name, start, end, window}` list of sequential windows.
     *
     * @return array<int, array{no:int, name:string, start:string, end:string, window:string}>
     */
    public function windows(int $numShifts, int $durationHours, string $firstStart): array
    {
        return $this->windowsFromMinutes($numShifts, $durationHours * 60, $firstStart);
    }

    /**
     * Minutes-precise windows with optional per-shift overrides. Each shift
     * starts where the previous one ended unless it overrides its own start.
     *
     * @param  array<int, array{start?:string, durationMinutes?:int}>  $overrides  keyed by shift no
     * @return array<int, array<string, mixed>>
     */
    public function windowsFromMinutes(int $numShifts, int $durationMinutes, string $firstStart, array $overrides = []): array
    {
        $cursor = $this->toMinutes($this->normalizeTime($firstStart));
        $out = [];

        for ($i = 0; $i < $numShifts; $i++) {
            $no = $i + 1;
            $override = $overrides[$no] ?? [];
            $start = isset($override['start']) ? $this->toMinutes($this->normalizeTime((string) $override['start'])) : $cursor;
            $length = max(1, (int) ($override['durationMinutes'] ?? $durationMinutes));
            $end = ($start + $length) % self::DAY_MINUTES;

            $out[] = [
                'no' => $no,
                'name' => ShiftEnums::shiftName($no),
                'start' => $this->toHHMM($start),
                'end' => $this->toHHMM($end),
                'window' => $this->toHHMM($start).'-'.$this->toHHMM($end),
                'durationMinutes' => $length,
                'durationHours' => $this->hoursOf($length),
                'overridden' => $override !== [],
            ];

            $cursor = $end;
        }

        return $out;
    }

    /**
     * Which shift number + type a timestamp falls in, from a config.
     * Fallback: صباحي before noon, مسائي after.
     *
     * @return array{shiftNo:?int, shiftType:string}
     */
    public function classify(BrandShiftConfig|BranchShiftConfig|null $config, \DateTimeInterface $at): array
    {
        $settings = is_array($config->shifts ?? null) ? $config->shifts : [];
        $minute = (int) $at->format('G') * 60 + (int) $at->format('i');

        $windows = $this->windowsFromMinutes(
            $this->numShifts($config),
            $this->durationMinutes($config),
            $config->first_shift_start ?? '06:00',
            $this->overrides($settings),
        );

        foreach ($windows as $w) {
            if ($this->within($minute, $this->toMinutes($w['start']), $this->toMinutes($w['end']))) {
                return ['shiftNo' => $w['no'], 'shiftType' => $w['name']];
            }
        }

        return ['shiftNo' => null, 'shiftType' => $minute < 720 ? 'صباحي' : 'مسائي'];
    }

    /** Effective duration in minutes for a stored config (defaults to 8h). */
    public function durationMinutes(BrandShiftConfig|BranchShiftConfig|null $config): int
    {
        $minutes = $config?->duration_minutes;
        if ($minutes === null) {
            $minutes = ((int) ($config->duration_hours ?? 8)) * 60;
        }

        return $this->clampDuration((int) $minutes);
    }

    /** Per-shift overrides out of the `shifts` JSON, keyed by shift number. */
    public function overrides(?array $settings): array
    {
        $raw = $settings['overrides'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $no = (int) ($row['no'] ?? $key);
            if ($no < 1) {
                continue;
            }
            $entry = [];
            if (isset($row['start'])) {
                $entry['start'] = $this->normalizeTime((string) $row['start']);
            }
            if (isset($row['durationMinutes'])) {
                $entry['durationMinutes'] = $this->clampDuration((int) $row['durationMinutes']);
            }
            if ($entry !== []) {
                $out[$no] = $entry;
            }
        }

        return $out;
    }

    /**
     * Accept what a human types. The duration field is free text now, so
     * `8`, `7.5`, `٧` (Arabic-Indic) and `90m`-style minutes all have to land on
     * the same integer.
     */
    public function normalizeTime(string $raw): string
    {
        $value = trim($this->latinDigits($raw));
        $pm = (bool) preg_match('/\b(pm|م)\b/iu', $value);
        $am = (bool) preg_match('/\b(am|ص)\b/iu', $value);
        $value = trim(preg_replace('/\b(am|pm|ص|م)\b/iu', '', $value) ?? $value);

        if (! preg_match('/^(\d{1,2})\s*:\s*(\d{1,2})$/', $value, $m)) {
            // Bare hour («6») is a legitimate manual entry.
            if (preg_match('/^(\d{1,2})$/', $value, $h)) {
                $m = [$value, $h[1], '0'];
            } else {
                return '06:00';
            }
        }

        $hour = (int) $m[1] % 24;
        $minute = min(59, (int) $m[2]);
        if ($pm && $hour < 12) {
            $hour += 12;
        }
        if ($am && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Duration out of a save body: `durationMinutes` wins, else `durationHours`
     * (which may be fractional — the field is typed by hand). null = the body
     * did not mention it, so the caller keeps the stored value.
     *
     * @param  array<string, mixed>  $data
     */
    private function inputDurationMinutes(array $data): ?int
    {
        if (isset($data['durationMinutes']) && is_numeric($this->latinDigits((string) $data['durationMinutes']))) {
            return (int) round((float) $this->latinDigits((string) $data['durationMinutes']));
        }
        if (isset($data['durationHours']) && is_numeric($this->latinDigits((string) $data['durationHours']))) {
            return (int) round((float) $this->latinDigits((string) $data['durationHours']) * 60);
        }

        return null;
    }

    private function clampDuration(?int $minutes): int
    {
        $minutes ??= 8 * 60;

        return max(self::MIN_DURATION_MINUTES, min(self::DAY_MINUTES, $minutes));
    }

    private function numShifts(BrandShiftConfig|BranchShiftConfig|null $config): int
    {
        return max(1, min(ShiftEnums::MAX_SHIFTS, (int) ($config->num_shifts ?? 2)));
    }

    /** Minutes → whole hours, never 0 (a 45-minute shift still reads as «1 ساعة»). */
    private function hoursOf(int $minutes): int
    {
        return max(1, (int) round($minutes / 60));
    }

    private function humanHours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
    }

    /** Arabic-Indic digits (٠١٢…) → ASCII, so typed input parses. */
    private function latinDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '،' => '.',
        ]);
    }

    private function within(int $m, int $start, int $end): bool
    {
        return $start <= $end ? ($m >= $start && $m < $end) : ($m >= $start || $m < $end);
    }

    /** @return array{0:string, 1:string} */
    private function splitWindow(string $window): array
    {
        $parts = explode('-', $window);

        return [$this->normalizeTime(trim($parts[0] ?? '06:00')), $this->normalizeTime(trim($parts[1] ?? '14:00'))];
    }

    private function minutesBetween(string $start, string $end): int
    {
        return ($this->toMinutes($end) - $this->toMinutes($start) + self::DAY_MINUTES) % self::DAY_MINUTES;
    }

    private function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    private function toHHMM(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
