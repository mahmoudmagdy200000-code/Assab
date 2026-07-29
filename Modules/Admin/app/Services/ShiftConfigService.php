<?php

namespace Modules\Admin\Services;

use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Admin\Support\ShiftEnums;

/**
 * SRS §4.4 — the N-shift brand configuration. Computes the sequential shift
 * windows from `{numShifts, durationHours, firstShiftStart}` and keeps the
 * prototype `morningWindow`/`eveningWindow` in sync as derived aliases.
 *
 * Pure computation (no DB writes) so late-detection and the config endpoints can
 * both derive windows without duplicating the arithmetic.
 */
class ShiftConfigService
{
    /**
     * The full presentation for one brand config: real columns + computed shift
     * windows + legacy aliases.
     *
     * @return array<string, mixed>
     */
    public function present(string $brandId, ?string $brandName, ?BrandShiftConfig $config): array
    {
        $numShifts = max(1, min(4, (int) ($config->num_shifts ?? 2)));
        $durationHours = max(1, min(24, (int) ($config->duration_hours ?? 8)));
        $firstStart = $config->first_shift_start ?? '06:00';
        $float = (int) ($config->shifts['openingFloatHalalas'] ?? ShiftEnums::DEFAULT_FLOAT_HALALAS);

        $windows = $this->windows($numShifts, $durationHours, $firstStart);

        return [
            'brandId' => $brandId,
            'brandName' => $brandName,
            'numShifts' => $numShifts,
            'durationHours' => $durationHours,
            'firstShiftStart' => $firstStart,
            'openingFloatHalalas' => $float,
            'shifts' => $windows,
            // Prototype aliases — shift 1 = morning, shift 2 = evening.
            'morningWindow' => $windows[0]['window'] ?? '06:00-14:00',
            'eveningWindow' => $windows[1]['window'] ?? '14:00-23:00',
        ];
    }

    /**
     * Normalise a save body (either the meeting model or the legacy
     * morning/evening pair) to the columns to persist.
     *
     * @param  array<string, mixed>  $data
     * @return array{num_shifts:int, duration_hours:int, first_shift_start:string, shifts:array}
     */
    public function fromInput(array $data): array
    {
        // Legacy body: morning/evening windows → numShifts=2, duration+start
        // derived from the morning window.
        if (! isset($data['numShifts']) && isset($data['morningWindow'])) {
            [$start, $end] = $this->splitWindow($data['morningWindow']);

            return [
                'num_shifts' => 2,
                'duration_hours' => $this->hoursBetween($start, $end),
                'first_shift_start' => $start,
                'shifts' => ['openingFloatHalalas' => (int) ($data['openingFloatHalalas'] ?? ShiftEnums::DEFAULT_FLOAT_HALALAS)],
            ];
        }

        return [
            'num_shifts' => max(1, min(4, (int) ($data['numShifts'] ?? 1))),
            'duration_hours' => max(1, min(24, (int) ($data['durationHours'] ?? 8))),
            'first_shift_start' => $data['firstShiftStart'] ?? '06:00',
            'shifts' => ['openingFloatHalalas' => (int) ($data['openingFloatHalalas'] ?? ShiftEnums::DEFAULT_FLOAT_HALALAS)],
        ];
    }

    /**
     * A day holds 24 hours: N shifts × H hours must fit inside one, or the
     * windows wrap onto each other. Two identical windows collide on the mobile
     * `shifts` natural key (branch_id, start_time, end_time), so a 4×8h schedule
     * silently landed as THREE template rows in the app — the fourth renamed the
     * first instead of adding a shift.
     *
     * @throws AsabException 422 SHIFT_SCHEDULE_OVERFLOW
     */
    public function assertFitsDay(int $numShifts, int $durationHours): void
    {
        $total = $numShifts * $durationHours;
        if ($total <= 24) {
            return;
        }

        throw new AsabException(
            'SHIFT_SCHEDULE_OVERFLOW',
            "{$numShifts} shifts × {$durationHours}h = {$total}h does not fit in a 24-hour day.",
            "عدد الورديات ({$numShifts}) × مدة الوردية ({$durationHours} ساعة) = {$total} ساعة، وهو أكبر من اليوم (24 ساعة).",
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
        $startMin = $this->toMinutes($firstStart);
        $out = [];
        for ($i = 0; $i < $numShifts; $i++) {
            $s = ($startMin + $i * $durationHours * 60) % 1440;
            $e = ($s + $durationHours * 60) % 1440;
            $out[] = [
                'no' => $i + 1,
                'name' => ShiftEnums::shiftName($i + 1),
                'start' => $this->toHHMM($s),
                'end' => $this->toHHMM($e),
                'window' => $this->toHHMM($s).'-'.$this->toHHMM($e),
            ];
        }

        return $out;
    }

    /**
     * Which shift number + type a timestamp falls in, from a brand config.
     * Fallback: صباحي before noon, مسائي after.
     *
     * @return array{shiftNo:?int, shiftType:string}
     */
    public function classify(?BrandShiftConfig $config, \DateTimeInterface $at): array
    {
        $numShifts = max(1, min(4, (int) ($config->num_shifts ?? 2)));
        $durationHours = max(1, min(24, (int) ($config->duration_hours ?? 8)));
        $firstStart = $config->first_shift_start ?? '06:00';
        $minute = (int) $at->format('G') * 60 + (int) $at->format('i');

        foreach ($this->windows($numShifts, $durationHours, $firstStart) as $w) {
            if ($this->within($minute, $this->toMinutes($w['start']), $this->toMinutes($w['end']))) {
                return ['shiftNo' => $w['no'], 'shiftType' => $w['name']];
            }
        }

        return ['shiftNo' => null, 'shiftType' => $minute < 720 ? 'صباحي' : 'مسائي'];
    }

    private function within(int $m, int $start, int $end): bool
    {
        return $start <= $end ? ($m >= $start && $m < $end) : ($m >= $start || $m < $end);
    }

    /** @return array{0:string, 1:string} */
    private function splitWindow(string $window): array
    {
        $parts = explode('-', $window);

        return [trim($parts[0] ?? '06:00'), trim($parts[1] ?? '14:00')];
    }

    private function hoursBetween(string $start, string $end): int
    {
        $diff = ($this->toMinutes($end) - $this->toMinutes($start) + 1440) % 1440;

        return max(1, (int) round($diff / 60));
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
