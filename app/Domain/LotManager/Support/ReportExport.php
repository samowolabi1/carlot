<?php

namespace App\Domain\LotManager\Support;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** One Lot Manager report as an Excel sheet (TDD M19: "?export=xlsx"). Money is whole naira. */
class ReportExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  array{title: string, columns: list<array{key: string, label: string, money?: bool, percent?: bool}>, rows: list<array<string, mixed>>, totals: array<string, mixed>|null}  $report
     */
    public function __construct(private readonly array $report) {}

    /** @return list<list<mixed>> */
    public function array(): array
    {
        $keys = array_column($this->report['columns'], 'key');
        $rows = array_map(fn (array $r) => array_map(fn (string $k) => $r[$k] ?? '', $keys), $this->report['rows']);

        if ($this->report['totals'] !== null) {
            $rows[] = array_map(fn (string $k) => $this->report['totals'][$k] ?? '', $keys);
        }

        return $rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn (array $c) => $c['label'].(($c['money'] ?? false) ? ' (₦)' : '').(($c['percent'] ?? false) ? ' (%)' : ''), $this->report['columns']);
    }

    public function title(): string
    {
        return mb_substr($this->report['title'], 0, 31);
    }
}
