<?php

namespace App\Exports;

use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ReportWorkbook implements Export, WithMultipleSheets
{
    private const DECIMAL_FIELDS = [
        'amount', 'amount_after_platform_commission', 'authorized_amount', 'cash_in', 'cash_out', 'cash_sales',
        'commission_amount', 'costed_net_sales', 'counted_cash', 'current_stock', 'delivery_fee', 'difference',
        'discount_total', 'estimated_cost', 'estimated_labor_cost', 'estimated_pay', 'estimated_profit',
        'estimated_unit_cost', 'expected_cash', 'external_platform_net_sales', 'gross_item_amount', 'gross_sales',
        'hourly_rate', 'labor_cost_percentage', 'loyalty_redeemed_amount', 'margin_percentage', 'net_amount',
        'net_consumption', 'net_sales', 'opening_amount', 'overtime_hours', 'overtime_multiplier', 'overtime_weekly_hours', 'percentage', 'quantity',
        'regular_hours', 'returns_amount', 'returns_tax_amount', 'sale_price', 'subtotal', 'surcharge_amount', 'surcharge_total',
        'tax_total', 'tip_amount', 'total', 'total_amount', 'total_sales', 'uncosted_net_sales',
        'unit_cost', 'unit_price', 'value', 'worked_hours',
    ];

    public function __construct(private readonly array $data) {}

    public function sheets(): array
    {
        // Normalize Eloquent resources and timestamps the same way as the JSON response.
        $data = json_decode(json_encode($this->data, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        if (isset($data['by_type'])) {
            $payments = [];
            foreach ($data['by_type'] as $type => $amounts) {
                $payments[] = ['payment_type' => $type, ...$amounts];
            }
            $data['by_type'] = $payments;
        }

        $tables = [];
        $summary = $this->flatten($data, '', $tables);
        $sheets = [new ReportSheet('Resumen', ['field', 'value'], array_map(
            fn ($field, $value) => [$field, $value], array_keys($summary), $summary,
        ))];

        foreach ($tables as $title => $records) {
            $columns = [];
            foreach ($records as $row) {
                $columns += $row;
            }
            $headings = array_keys($columns);
            $sheets[] = new ReportSheet($title, $headings, array_map(
                fn (array $row) => array_map(fn (string $field) => $row[$field] ?? null, $headings), $records,
            ));
        }

        return $sheets;
    }

    private function flatten(array $record, string $prefix, array &$tables, string $tablePrefix = ''): array
    {
        $fields = [];
        foreach ($record as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                if ($value !== [] && array_is_list($value) && is_array($value[0])) {
                    $table = $tablePrefix === '' ? $path : $tablePrefix.'.'.$path;
                    $tables[$table] ??= [];
                    foreach ($value as $row) {
                        $tables[$table][] = $this->flatten($row, '', $tables, $table);
                    }
                } else {
                    $fields += $this->flatten($value, $path, $tables, $tablePrefix);
                }
            } elseif (is_string($value) && mb_strlen($value) > 16000) {
                // Keep long audit text within Excel's cell size without dropping any characters.
                foreach (mb_str_split($value, 16000) as $index => $part) {
                    $fields[$path.'.part_'.($index + 1)] = $part;
                }
            } else {
                $field = Str::afterLast($path, '.');
                $fields[$path] = is_string($value) && is_numeric($value) && in_array($field, self::DECIMAL_FIELDS, true)
                    && strlen(str_replace(['-', '.'], '', $value)) <= 15
                    ? (float) $value : $value;
            }
        }

        return $fields;
    }
}
