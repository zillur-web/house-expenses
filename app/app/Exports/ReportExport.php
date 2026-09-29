<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

class ReportExport extends StringValueBinder implements FromArray, WithCustomValueBinder, WithHeadings
{
    public function __construct(private array $columns, private array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->columns;
    }

    public function bindValue(Cell $cell, $value)
    {
        $column = Coordinate::columnIndexFromString($cell->getColumn()) - 1;
        if ($cell->getRow() > 1 && str_ends_with($this->columns[$column] ?? '', 'BDT')) {
            $cell->setValueExplicit((float) str_replace(',', '', (string) $value), DataType::TYPE_NUMERIC);
            $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
