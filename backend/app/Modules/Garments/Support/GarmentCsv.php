<?php

declare(strict_types=1);

namespace App\Modules\Garments\Support;

use App\Modules\Garments\Models\GarmentFieldDefinition;
use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Pricing\Models\Size;
use App\Support\Exceptions\ApiException;

/**
 * CSV import/export of a name/number list. Columns: Size, Quantity, then the category's
 * configured fields (header = field label or key). XLSX is not supported (no spreadsheet library
 * on shared hosting): save as CSV first.
 */
final class GarmentCsv
{
    /** @return list<array<string, mixed>> rows in the API row shape (validated later by GarmentRowValidator) */
    public function parse(string $path, GarmentSchema $schema): array
    {
        $handle = fopen($path, 'rb') ?: throw ApiException::unprocessable('import_unreadable', 'The file could not be read.');
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! is_array($header)) {
            throw new ApiException(422, 'validation_failed', 'The file is empty.', ['file' => ['The file is empty.']]);
        }

        $columns = [];
        foreach ($header as $index => $name) {
            $key = mb_strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)));
            $field = $schema->fields->first(fn (GarmentFieldDefinition $f) => mb_strtolower($f->label) === $key || $f->key === $key);
            $columns[$index] = match (true) {
                $key === 'size' => 'size',
                in_array($key, ['quantity', 'qty'], true) => 'quantity',
                $key === 'notes' => 'notes',
                $field !== null => $field->key,
                default => null,
            };
        }
        if (! in_array('size', $columns, true)) {
            fclose($handle);
            throw new ApiException(422, 'validation_failed', 'The file needs a "Size" column.', ['file' => ['The file needs a "Size" column.']]);
        }

        $rows = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            $row = ['values' => []];
            foreach ($cells as $index => $cell) {
                $column = $columns[$index] ?? null;
                $cell = ltrim(trim((string) $cell), "'"); // undo our own formula-injection prefix
                match (true) {
                    $column === null => null,
                    $column === 'size' => $row['size_id'] = $this->sizeId($cell, $schema),
                    in_array($column, ['quantity', 'notes', ...GarmentFieldDefinition::COLUMN_KEYS], true) => $row[$column] = $cell,
                    default => $row['values'][$column] = $cell,
                };
            }
            $rows[] = $row;
            if (count($rows) > 2000) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }

    public function write(GarmentListVersion $version, GarmentSchema $schema): void
    {
        $out = fopen('php://output', 'wb') ?: throw new \RuntimeException('Cannot open output.');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet apps
        fputcsv($out, ['Size', 'Quantity', ...$schema->fields->pluck('label')->all(), 'Notes'], ',', '"', '');

        $version->items()->with('values')->orderBy('line_no')->chunk(500, function ($items) use ($out, $schema): void {
            /** @var GarmentListItem $item */
            foreach ($items as $item) {
                $values = $item->valueMap();
                $cells = [$schema->sizes->get($item->size_id)->code ?? '', (string) $item->quantity];
                foreach ($schema->fields as $field) {
                    $cells[] = $schema->display($field, $field->isColumn() ? $item->getAttribute($field->key) : ($values[$field->key] ?? null));
                }
                $cells[] = (string) $item->notes;
                fputcsv($out, array_map(self::safe(...), $cells), ',', '"', '');
            }
        });
        fclose($out);
    }

    /** Neutralise spreadsheet formulas (CSV injection): prefix cells starting with = + - @ tab or CR. */
    public static function safe(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell;
    }

    private function sizeId(string $value, GarmentSchema $schema): ?int
    {
        $needle = mb_strtolower($value);
        $size = $schema->sizes->first(fn (Size $s) => mb_strtolower($s->code) === $needle || mb_strtolower($s->label) === $needle);

        return $size?->id;
    }
}
