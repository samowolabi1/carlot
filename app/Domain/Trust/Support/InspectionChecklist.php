<?php

namespace App\Domain\Trust\Support;

use App\Domain\Trust\Enums\CheckResult;

/**
 * The 40-point inspection (TDD M14): seven groups, each item pass / advisory / fail with an
 * optional note. The score is the share of points, an advisory counting half.
 */
class InspectionChecklist
{
    /** @var array<string, array{label: string, items: array<string, string>}> */
    public const GROUPS = [
        'engine' => ['label' => 'Engine', 'items' => [
            'oil_level' => 'Oil level and condition',
            'oil_leaks' => 'No oil leaks',
            'coolant' => 'Coolant level, no overheating',
            'belts_hoses' => 'Belts and hoses',
            'battery' => 'Battery and terminals',
            'cold_start' => 'Starts cleanly from cold',
            'idle' => 'Smooth idle, no knocking',
            'exhaust' => 'Exhaust, no heavy smoke',
        ]],
        'gearbox' => ['label' => 'Gearbox and drive', 'items' => [
            'shifts' => 'Gear changes smooth',
            'clutch' => 'Clutch or torque converter',
            'gearbox_leaks' => 'No gearbox leaks',
            'axles' => 'Drive shafts and CV joints',
            'gearbox_noise' => 'No whining or clunks on the road',
        ]],
        'body' => ['label' => 'Body and paint', 'items' => [
            'paint' => 'Paint finish',
            'panel_gaps' => 'Even panel gaps',
            'rust' => 'No rust or corrosion',
            'accident' => 'No signs of accident repair',
            'glass' => 'Windscreen and glass',
            'lights' => 'Headlights, indicators, brake lights',
            'underbody' => 'Underbody and chassis',
        ]],
        'electrics' => ['label' => 'Electrics', 'items' => [
            'warnings' => 'No dashboard warning lights',
            'ac' => 'Air conditioning cools',
            'windows_locks' => 'Windows, mirrors and central locking',
            'infotainment' => 'Radio, screen and camera',
            'horn_wipers' => 'Horn and wipers',
            'gauges' => 'Gauges and instruments',
        ]],
        'tyres' => ['label' => 'Tyres, brakes and suspension', 'items' => [
            'tread_front' => 'Front tyre tread',
            'tread_rear' => 'Rear tyre tread',
            'tyre_age' => 'Tyre age (under 5 years)',
            'spare' => 'Spare tyre, jack and spanner',
            'brakes' => 'Brakes stop straight, no noise',
            'suspension' => 'Suspension and steering',
        ]],
        'interior' => ['label' => 'Interior', 'items' => [
            'seats' => 'Seats and upholstery',
            'seatbelts' => 'Seat belts',
            'airbags' => 'Airbags (no warning light)',
            'odometer' => 'Mileage consistent with wear',
            'flood' => 'No damp smell or flood signs',
        ]],
        'documents' => ['label' => 'Documents', 'items' => [
            'customs' => 'Customs papers (duty)',
            'licence' => 'Vehicle licence and proof of ownership',
            'vin_match' => 'VIN matches the papers',
        ]],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return collect(self::GROUPS)->flatMap(fn (array $g) => array_keys($g['items']))->values()->all();
    }

    public static function label(string $key): ?string
    {
        foreach (self::GROUPS as $group) {
            if (isset($group['items'][$key])) {
                return $group['items'][$key];
            }
        }

        return null;
    }

    /** @param array<string, array{status: string, note?: string|null}> $checklist */
    public static function score(array $checklist): int
    {
        $keys = self::keys();
        $points = 0.0;

        foreach ($keys as $key) {
            $points += CheckResult::tryFrom($checklist[$key]['status'] ?? '')?->points() ?? 0.0;
        }

        return (int) round($points / count($keys) * 100);
    }

    /**
     * Per group: the worst result, and the items that were not a pass. Used on the car page
     * and in the PDF.
     *
     * @param  array<string, array{status: string, note?: string|null}>  $checklist
     * @return list<array{key: string, label: string, result: string, result_label: string, issues: list<array{label: string, result: string, note: string|null}>}>
     */
    public static function summary(array $checklist): array
    {
        $rows = [];

        foreach (self::GROUPS as $groupKey => $group) {
            $worst = CheckResult::Pass;
            $issues = [];

            foreach ($group['items'] as $key => $label) {
                $result = CheckResult::tryFrom($checklist[$key]['status'] ?? '') ?? CheckResult::Pass;

                if ($result !== CheckResult::Pass) {
                    $issues[] = ['label' => $label, 'result' => $result->value, 'note' => $checklist[$key]['note'] ?? null];
                }

                if ($result->points() < $worst->points()) {
                    $worst = $result;
                }
            }

            $rows[] = ['key' => $groupKey, 'label' => $group['label'], 'result' => $worst->value, 'result_label' => $worst->label(), 'issues' => $issues];
        }

        return $rows;
    }
}
