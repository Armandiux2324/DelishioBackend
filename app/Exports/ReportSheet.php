<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportSheet extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(private readonly string $name, private readonly array $columns, private readonly array $rows) {}

    public function title(): string
    {
        return $this->name;
    }

    public function headings(): array
    {
        return $this->columns;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) || (is_int($value) && strlen(ltrim((string) $value, '-')) > 15)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
